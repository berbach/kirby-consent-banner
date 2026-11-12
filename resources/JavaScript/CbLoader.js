import {createToggle, createElementWithAttrs, isBotAgent, typeofIsAndValueIsNot, generateUserUid, generateUserHash, hasDaysPassed} from "./Lib/utils";

import Debug from './Lib/debug';
// Kirby serves every plugin asset under its own media hash, so a lazily loaded
// chunk next to this file could not be resolved: the overlay is bundled.
import CookieInformation from './Lib/CookieInformation.js';
const cookieUtils = require('./Lib/cookie')
/**
 * @property {string} NODE_ENV
 */
window.DEVMODE = process.env.NODE_ENV !== 'production' ?? false;

Debug.setDevMode(DEVMODE);

/**
 * @constructor
 */
const CbManager = function ()  {

    const CB_NAME = 'bb-consentbanner';
    const CB_PREFIX = CB_NAME + '-';
    const CB_GROUP_PREFIX = 'group-'
    const LAST_PREFERENCES_NAME = this.bannerPreferences?.cName ?? 'BbConsentPreferences';

    this.bannerPreferences = JSON.parse(document.getElementById('bbBannerData').innerHTML)

    this.isBottomLayout = !typeofIsAndValueIsNot(this.bannerPreferences?.layout, 'string', 'cb-bottom');
    // Compact overlay: starts as a small dialog (text + buttons) and expands to the settings on demand.
    this.isCompactOverlay = this.bannerPreferences?.layout === 'cb-overlay-compact';

    this.cookiePreferences = JSON.parse(cookieUtils.get(LAST_PREFERENCES_NAME));
    this.localPreferences = {}
    this.nonce = document.getElementById('bbBannerData')?.nonce || ''

    this.changePreferences = null
    this.acceptAllButton = null
    this.saveAndCloseButton = null
    this.confirmSelectionButton = null
    this.advancedSettingsButton = null
    this.rejectButton = null
    this.showCookieInfoButton = null

    this.banner = null
    this.bannerBody = null
    this.bannerStage = null
    this.bannerMain = null
    this.bannerFooter = null
    this.bannerOpener = null
    /**
     *
     */
    this.init = () => {
        if(this.shouldCreateBanner()){
            this.openerChangePreferences()
            this.createWrapperBanner()
            this.createBanner()
        }else{
            this.openerChangePreferences()
        }

        this.attachPlaceholderToggles()
        // Reveal what a returning visitor has already accepted. Without this the
        // placeholder stayed in place until the next consent was saved, so a
        // reload showed the block again instead of the content.
        this.handlePlaceholderElements()
        // Apply tracking integrations for returning visitors (from the cookie).
        this.applyIntegrations()
    }

    this.attachBannerEventListeners = () => {
        this.bannerMain?.addEventListener('submit', e => {
            e.preventDefault()
        })

        this.acceptAllButton?.addEventListener('click', () => {
            this.savePreferences(this.collectAndModifyData(true))
        })
        this.saveAndCloseButton?.addEventListener('click', () => {
            this.savePreferences(this.collectData())
        })
        this.confirmSelectionButton?.addEventListener('click', () => {
            this.savePreferences(this.collectData())
        })
        this.rejectButton?.addEventListener('click', () => {
            this.savePreferences(this.collectEssentialData())
        })
        this.advancedSettingsButton?.addEventListener('click', () => {
            this.createBannerOverlay()
        })

        this.attachSyncToggles();
    }
    /**
     * @property {number} openerVariant
     * @property {Object} openerData
     * @property {string} targetFooterNavigation
     * @property {string} textLinkText
     * @property {string} textLinkPosition
     * @property {string} buttonWidgetPosition
     * @property {string} buttonWidgetText
     */
    this.openerChangePreferences = () => {
        let openerType = this.bannerPreferences.openerVariant ?? 10
        let targetWrapper = null

        if(openerType === 10){
            try {
                targetWrapper = document.querySelector(this.bannerPreferences?.openerData?.targetFooterNavigation);
            } catch (error) {
                targetWrapper = null // empty or invalid selector
            }
            // Without the footer navigation (or with an empty one) the visitor
            // could never reopen the settings: fall back to the button widget.
            if (!targetWrapper?.firstElementChild) openerType = 20
        }

        if(openerType === 10){
            const cloneFirstElementChild = targetWrapper.firstElementChild.cloneNode(true);

            cloneFirstElementChild.innerHTML = '';

            this.changePreferences = createElementWithAttrs('button', {
                className: ['bb-nav__link', 'bb-text-widget', CB_PREFIX + 'link'].join(' '),
                type: 'button',
                innerText: this.bannerPreferences?.openerData?.textLinkText ?? 'Cookie Settings'
            }, cloneFirstElementChild)

            if (this.bannerPreferences?.openerData?.textLinkPosition === 'first'){
                targetWrapper.insertBefore(cloneFirstElementChild, targetWrapper.firstElementChild);
            }
            if (this.bannerPreferences?.openerData?.textLinkPosition === 'last'){
                targetWrapper.appendChild(cloneFirstElementChild)
            }
        }

        if(openerType === 20){
            const buttonPosition = this.bannerPreferences?.openerData?.buttonWidgetPosition ?? 'left';
            const buttonText = this.bannerPreferences?.openerData?.buttonWidgetText ?? 'Cookie Settings';

            this.changePreferences = createElementWithAttrs("button", {
                className: ["bb-button-widget", CB_PREFIX + "button", "bb-button-widget-" + buttonPosition].join(' '),
                innerHTML: createElementWithAttrs("span", { innerText: buttonText}).outerHTML
            });
            //TODO Widget Position
            const firstBlock = document.querySelector('body > div')
            if (firstBlock) {
                firstBlock.insertAdjacentElement('afterend', this.changePreferences)
            } else {
                document.body.appendChild(this.changePreferences)
            }
        }

        this.changePreferences?.addEventListener('click', this.openPreferences)

        // Any element of the template can reopen the settings as well:
        // <button type="button" data-consent-banner-open>Cookie settings</button>
        document.querySelectorAll('[data-consent-banner-open]').forEach(opener => {
            opener.addEventListener('click', event => {
                event.preventDefault()
                this.openPreferences()
            })
        })
    }

    /**
     * Opens the settings with the saved selection of the visitor.
     * @return {void}
     */
    this.openPreferences = () => {
        const localPreferences = this.getLastPreferences();
        this.createWrapperBanner(true)
        this.createBanner(true)

        Object.keys(localPreferences?.services ?? {}).forEach(component => {
            const componentToggle = this.bannerMain.querySelector(`.${CB_PREFIX}component input[name="${component}"]`)
            // A component that was removed from the banner since has no toggle.
            if (componentToggle && localPreferences?.services[component].consent !== componentToggle.checked)
                componentToggle.click()
        })
    }

    this.closeAndRemoveBanner = () => {
        let existBanner = document.querySelector(`div.${CB_NAME}`);
        if(!!existBanner) {
            existBanner.removeEventListener('keydown', this.bannerKeydownHandler)
            existBanner.remove();
            // Return focus to the opener (e.g. the "Cookie settings" link) after close.
            const focusTarget = this.changePreferences || this.bannerOpener
            if (focusTarget && typeof focusTarget.focus === 'function' && document.contains(focusTarget)) {
                focusTarget.focus()
            }
        }
        // Unlock page scroll when the banner is closed.
        document.documentElement.classList.remove(CB_NAME + '--page-locked')
    }

    /**
     * Focusable elements currently inside the banner dialog.
     * @return {HTMLElement[]}
     */
    this.getBannerFocusable = () => {
        if (!this.banner) return []
        return Array.from(this.banner.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        )).filter(el => !el.disabled && el.offsetParent !== null)
    }

    /**
     * Keeps Tab focus inside the dialog while it is modal (overlay layout).
     */
    this.bannerKeydownHandler = (e) => {
        if (e.key !== 'Tab' || this.banner?.getAttribute('aria-modal') !== 'true') return
        const focusable = this.getBannerFocusable()
        if (focusable.length === 0) return
        const first = focusable[0]
        const last = focusable[focusable.length - 1]
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault(); last.focus()
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault(); first.focus()
        }
    }

    /**
     * Moves focus into the dialog (announces the title via aria-labelledby) and
     * installs the focus trap. The trap only engages while the banner is modal.
     */
    this.focusBanner = () => {
        if (!this.banner) return
        this.banner.removeEventListener('keydown', this.bannerKeydownHandler)
        this.banner.addEventListener('keydown', this.bannerKeydownHandler)
        this.banner.focus()
    }

    this.createWrapperBanner = (isOverlay = false) => {
        this.closeAndRemoveBanner();
        // Remember who opened the banner so focus can return there on close.
        this.bannerOpener = document.activeElement;

        let layoutClass = `bb-${this.bannerPreferences?.layout ?? 'cb-bottom'}`
        if (isOverlay) {
            layoutClass = 'bb-cb-overlay'
        } else if (this.isCompactOverlay) {
            layoutClass = 'bb-cb-overlay bb-cb-compact'
        }

        this.banner = createElementWithAttrs('div', {
            className: [CB_NAME, layoutClass].join(" "),
            tabindex: "-1",
            role: "dialog",
            "aria-label": this.bannerPreferences?.banner?.title || 'Cookie consent',
            "data-nosnippet": "true"
        });
        // Overlay layout blocks the page → expose it as a modal dialog.
        if (isOverlay || !this.isBottomLayout) {
            this.banner.setAttribute('aria-modal', 'true');
        }
        document.querySelector('body').insertAdjacentElement('afterbegin', this.banner);
    }

    this.createBanner = (isOverlay = false) => {
        this.bannerBody = createElementWithAttrs('div', {className: [CB_PREFIX + 'body'].join(' ')})
        this.bannerStage = createElementWithAttrs('div', {className: [CB_PREFIX + 'stage'].join(' ')})
        this.bannerMain = createElementWithAttrs('form', {className: [CB_PREFIX + 'main'].join(' ')})
        this.bannerFooter = createElementWithAttrs('div', {className: [CB_PREFIX + 'footer'].join(' ')})

        const bannerContent = createElementWithAttrs('div', {
            className: [
                CB_PREFIX + 'content',
                !this.isBottomLayout ? 'bb-type-scroll' : 'bb-type-dynamic'
            ].join(' ')
        })

        const bannerHeader = createElementWithAttrs('div', {className: CB_PREFIX + 'header'})

        const hasTitle = this.bannerPreferences?.banner?.title !== ''
        const hasDescription = this.bannerPreferences?.banner?.description !== ''

        if (hasTitle) {
            createElementWithAttrs('p', {
                className: CB_PREFIX + '-title',
                id: CB_PREFIX + 'title-label',
                role: 'heading',
                'aria-level': '2',
                innerHTML: createElementWithAttrs("strong", { innerText: this.bannerPreferences?.banner?.title}).outerHTML
            }, bannerHeader)
        }

        if (hasDescription) {
            createElementWithAttrs('p', {
                className: CB_PREFIX + '-description',
                id: CB_PREFIX + 'desc-label',
                innerHTML: this.bannerPreferences?.banner?.description // innerHTML to decode html entities
            }, bannerHeader)
        }

        // Associate the dialog with its visible title / description for screen readers.
        if (hasTitle) {
            this.banner.setAttribute('aria-labelledby', CB_PREFIX + 'title-label')
        }
        if (hasDescription) {
            this.banner.setAttribute('aria-describedby', CB_PREFIX + 'desc-label')
        }

        // Header X close button (styled + shown only in the overlay layout via CSS).
        this.closeButton = createElementWithAttrs('button', {
            className: CB_PREFIX + 'close',
            type: 'button',
            'aria-label': this.bannerPreferences?.displayTexts?.buttons?.close || 'Close'
        }, bannerHeader)
        this.closeButton.addEventListener('click', () => this.handleCloseButton())

        bannerContent.appendChild(bannerHeader)
        bannerContent.appendChild(this.createPreferences(isOverlay))

        this.bannerMain.appendChild(bannerContent)
        this.bannerMain.appendChild(this.createContainerButtons(isOverlay))


        this.bannerFooter.appendChild(this.createContainerFooter())

        this.bannerStage.appendChild(this.bannerMain)
        this.bannerStage.appendChild(this.bannerFooter)

        this.banner.innerHTML = `
            <!--googleoff: index-->
            ${this.bannerBody.outerHTML}
            <!--googleon: index-->
            `;

        if(document.querySelector('.' + CB_PREFIX + 'stage') == null) {
            document.querySelector('.' + CB_PREFIX + 'body').appendChild(this.bannerStage)
            document.querySelector('.' + CB_NAME).classList.add('visible')
        }

        // Full-viewport backdrop as the last child of the banner wrapper.
        createElementWithAttrs('div', {
            className: CB_NAME + '--page-disabled',
            'aria-hidden': 'true'
        }, this.banner)

        // Lock page scroll while the banner is open/active.
        document.documentElement.classList.add(CB_NAME + '--page-locked')

        this.focusBanner()
    }

    /**
     * The compact overlay never closes without a decision: the X keeps the
     * essential components in the compact view and saves the current selection
     * in the advanced settings. Other layouts just close the banner.
     */
    this.handleCloseButton = () => {
        if (!this.isCompactOverlay) {
            this.closeAndRemoveBanner()
            return
        }
        if (this.banner?.classList.contains('bb-cb-compact')) {
            this.savePreferences(this.collectEssentialData())
        } else {
            this.savePreferences(this.collectData())
        }
    }

    this.createBannerOverlay = () => {

        this.banner.classList.remove("bb-cb-bottom", 'bb-cb-compact', 'visible')
        this.banner.classList.add('bb-cb-overlay', 'visible')
        // Switched to the blocking overlay → now behaves as a modal dialog.
        this.banner.setAttribute('aria-modal', 'true')
        this.focusBanner()

    }

    this.createPreferences = () => {
        const containerPreferences = createElementWithAttrs('div', {className: [CB_PREFIX + 'preferences'].join(' ')})

        if (typeof this.bannerPreferences?.groups === "object" && Object.keys(this.bannerPreferences?.groups).length > 0) {
            const contentGroups = createElementWithAttrs('div', {
                className: [CB_PREFIX + 'groups'].join(' ')
            })

            for (let groupKey in this.bannerPreferences?.groups){
                let group = this.bannerPreferences.groups[groupKey];

                const groupComponents = createElementWithAttrs('div', {
                    className: [CB_PREFIX + 'components'].join(' ')
                })

                if (typeof group?.components === "object" && Object.keys(group?.components).length > 0){
                    for (let componentKey in group?.components){
                        let component = group?.components[componentKey];

                        groupComponents.appendChild(
                            createToggle(false, CB_PREFIX, component?.title, component?.id, component?.description,
                                {
                                    'data-group-id': component?.groupId,
                                    'data-group-name': group?.title,
                                    'data-component-title' : component?.title,
                                    checked: !!group.lockedAndActive,
                                    disabled: !!group.lockedAndActive
                                }
                            )
                        )
                    }
                }

                if((groupComponents.children.length > 0 || group.lockedAndActive) && (groupComponents.children.length > 0 || !group.lockedAndActive)) {
                    const groupEl = createToggle(
                        true, CB_PREFIX, group?.title, CB_GROUP_PREFIX + group?.id, group?.description,
                        {
                            checked: !!group.lockedAndActive,
                            disabled: !!group.lockedAndActive
                        },
                        groupComponents.children.length > 0 ? groupComponents : null
                    )
                    // Expose each group as a labelled group so the component
                    // toggles are announced within their category.
                    groupEl.setAttribute('role', 'group')
                    if (group?.title) {
                        groupEl.setAttribute('aria-label', group.title)
                    }
                    contentGroups.appendChild(groupEl)
                }
            }

            containerPreferences.appendChild(contentGroups)
        }
        return containerPreferences;
    }
    /**
     * @property {Object} displayTexts
     * @param {boolean} isOverlay
     * @return {*}
     */
    this.createContainerButtons = (isOverlay = false) => {

        let buttonLabels = Object.keys(this.bannerPreferences?.displayTexts?.buttons).length > 0 ? this.bannerPreferences?.displayTexts?.buttons : {}
        const containerButtons = createElementWithAttrs('div', {className: [CB_PREFIX + 'buttons'].join(' ')})
        if (this.isCompactOverlay) {
            // Accepts only the locked (essential) components.
            this.rejectButton = createElementWithAttrs('button', {
                className: ['bb-button' , 'bb-btn--typeS', 'bb-btn--reject'].join(' '),
                type: 'button',
                innerText: buttonLabels?.acceptEssential
            }, containerButtons)
        }
        this.saveAndCloseButton = createElementWithAttrs('button', {
            className: ['bb-button' , 'bb-btn--typeS', 'bb-show-overlay'].join(' '),
            type: 'button',
            innerText: buttonLabels?.saveAndClose
        }, containerButtons)
        this.acceptAllButton = createElementWithAttrs('button', {
            className: ['bb-button' , 'bb-btn--typeS', 'bb-show-all'].join(' '),
            type: 'button',
            innerText: buttonLabels?.acceptAll
        }, containerButtons)
        this.confirmSelectionButton = createElementWithAttrs('button', {
            className: ['bb-button' , 'bb-btn--typeS', 'bb-show-bottom'].join(' '),
            type: 'button',
            innerText: buttonLabels?.confirmSelection
        }, containerButtons)
        this.advancedSettingsButton = createElementWithAttrs('button', {
            className: ['bb-button' , 'bb-btn--typeS', 'bb-show-bottom', 'bb-show-compact'].join(' '),
            type: 'button',
            innerText: buttonLabels?.advancedSettings
        }, containerButtons)

        if (this.isCompactOverlay) {
            // Compact overlay order: reject, advanced settings / save & close, accept all.
            containerButtons.appendChild(this.acceptAllButton)
        }

        this.attachBannerEventListeners(isOverlay);
        return containerButtons;
    }
    /**
     * @property {Object} footerNavigation
     * @property {string} showInfo
     * @return {*}
     */
    this.createContainerFooter = () => {
        let buttonLabels = Object.keys(this.bannerPreferences?.displayTexts?.buttons).length > 0 ? this.bannerPreferences?.displayTexts?.buttons : {}
        let footerLinks = this.bannerPreferences?.footerNavigation.length > 0 ? this.bannerPreferences?.footerNavigation : []
        const containerFooter = createElementWithAttrs('div', {className: [CB_PREFIX + 'footer-row'].join(' ')})
        let containerFooterCell = createElementWithAttrs('div', {className: CB_PREFIX + 'footer-cell'})

        this.showCookieInfoButton = createElementWithAttrs('button', {
            className: [CB_PREFIX + '-link', 'bb-link--cookie-info'].join(' '),
            type: 'button',
            innerText: buttonLabels?.showInfo,
        }, containerFooterCell)

        containerFooter.appendChild(containerFooterCell)

        this.showCookieInfoButton?.addEventListener('click', () => {
            const cookieInfo = new CookieInformation(this.bannerPreferences);
            cookieInfo.show();
        })

        containerFooterCell = createElementWithAttrs('div', {className: [CB_PREFIX + 'footer-cell', CB_PREFIX + 'footer-cell--links'].join(' ')})

        const linkContainer = createElementWithAttrs('div', {className: CB_PREFIX + 'links'})
        footerLinks.forEach(link => {
            createElementWithAttrs('a', {
                className: CB_PREFIX + '-link',
                innerText: link.title,
                href: link.url
            }, linkContainer)
        })
        createElementWithAttrs('div', {className: CB_PREFIX + 'footer-cell'})
        const identificationContainer = createElementWithAttrs('div', {className: CB_PREFIX + 'uid', innerText: `User-ID: ${this.getUserHash()}`,})

        containerFooterCell.appendChild(linkContainer)

        containerFooter.appendChild(containerFooterCell)
        containerFooter.appendChild(identificationContainer)
        return containerFooter;
    }
    /**
     * Swaps every placeholder whose component has been accepted with its real
     * (deferred) content, so blocked iframes appear without a page reload.
     * @return {void}
     */
    this.handlePlaceholderElements = () => {
        document.querySelectorAll(`.${CB_PREFIX}placeholder[data-cookiebanner-component]`).forEach(placeholder => {
            const componentId = placeholder.dataset.cookiebannerComponent
            if (this.cookiePreferences?.[componentId] === true) {
                this.activatePlaceholder(placeholder)
            } else {
                this.deactivatePlaceholder(placeholder)
            }
        })
    }
    /**
     * Reveals the real (deferred) content next to the placeholder and hides the
     * placeholder itself. The placeholder stays in the DOM (with its inert
     * <template>) so consent can be withdrawn again without a page reload.
     * @param {HTMLElement} placeholder
     * @return {void}
     */
    this.activatePlaceholder = (placeholder) => {
        if (placeholder.dataset.cbActive === '1') return

        const template = placeholder.querySelector(`template.${CB_PREFIX}deferred`)
        if (!template) return

        const content = template.content.cloneNode(true)
        const moduleElements = Array.from(content.querySelectorAll('[data-module]'))

        // Insert the cloned nodes after the (now hidden) placeholder; this makes
        // their iframes live without a reload.
        const holder = createElementWithAttrs('div', { className: CB_PREFIX + 'revealed' })
        holder.appendChild(content)
        placeholder.insertAdjacentElement('afterend', holder)
        placeholder._cbHolder = holder
        placeholder.style.display = 'none'
        placeholder.dataset.cbActive = '1'

        // The template's data-module loader only runs on page load, so it never
        // sees nodes injected afterwards. Initialise them here (same convention)
        // e.g. so the Videoplayer module wires up its poster / player.
        moduleElements.forEach(element => this.initContentModules(element))
    }
    /**
     * Removes previously revealed content (stopping any iframe/video) and shows
     * the placeholder again — used when consent for the component is withdrawn.
     * @param {HTMLElement} placeholder
     * @return {void}
     */
    this.deactivatePlaceholder = (placeholder) => {
        if (placeholder.dataset.cbActive !== '1') return

        if (placeholder._cbHolder) {
            placeholder._cbHolder.remove()
            placeholder._cbHolder = null
        }
        placeholder.style.display = ''
        delete placeholder.dataset.cbActive

        const input = placeholder.querySelector(`.${CB_PREFIX}component input[name="${placeholder.dataset.cookiebannerComponent}"]`)
        if (input) input.checked = false
    }
    /**
     * Initialises the data-module scripts of a freshly injected element,
     * mirroring the template's module loader, then notifies external listeners.
     * @param {HTMLElement} element
     * @return {void}
     */
    this.initContentModules = (element) => {
        // Only templates that follow the data-module convention set the base
        // path; everyone else listens to the event below.
        const basePath = window.__BASE_PUBLIC_PATH__ || ''
        const moduleNames = basePath === '' ? [] : (element.dataset.module || '').split(' ').map(name => name.trim()).filter(Boolean)
        const options = element.dataset.options || ''

        moduleNames.forEach(async name => {
            try {
                const module = await import(/* webpackIgnore: true */ `${basePath}JavaScript/Module/${name}.js`)
                module.default ? new module.default(element, options) : module.init?.(element, options)
            } catch (error) {
                Debug.log('Content module init failed', { name, error })
            }
        })

        document.dispatchEvent(new CustomEvent('bb:content-injected', { detail: { element } }))
    }
    /**
     * Wires the accept toggle rendered inside a placeholder: checking it grants
     * consent for that single component and reveals its content.
     * @return {void}
     */
    this.attachPlaceholderToggles = () => {
        document.querySelectorAll(`.${CB_PREFIX}placeholder[data-cookiebanner-component]`).forEach(placeholder => {
            const componentId = placeholder.dataset.cookiebannerComponent
            const input = placeholder.querySelector(`.${CB_PREFIX}component input[name="${componentId}"]`)
            input?.addEventListener('change', () => {
                if (input.checked) this.acceptComponent(componentId)
            })
        })
    }
    /**
     * Grants consent for a single component (merged with the existing
     * preferences) and persists it, which also swaps the related placeholders.
     * @param {string} componentId
     * @return {void}
     */
    this.acceptComponent = (componentId) => {
        const meta = this.findComponentMeta(componentId)
        const currentServices = this.getLastPreferences()?.services ?? {}

        const services = {}
        Object.entries(currentServices).forEach(([id, value]) => {
            services[id] = {
                title: value?.title ?? '',
                consent: value?.consent === true,
                groupId: value?.groupId ?? '',
                groupName: value?.groupName ?? ''
            }
        })
        services[componentId] = { title: meta.title, consent: true, groupId: meta.groupId, groupName: meta.groupName }

        this.savePreferences(services)
    }
    /**
     * Looks up a component's meta data (title, group) in the banner data.
     * @param {string} componentId
     * @return {{title: string, groupId: string, groupName: string}}
     */
    this.findComponentMeta = (componentId) => {
        const groups = this.bannerPreferences?.groups ?? {}
        for (const groupKey in groups) {
            const group = groups[groupKey]
            const components = group?.components ?? {}
            for (const componentKey in components) {
                if (components[componentKey]?.id === componentId) {
                    return {
                        title: components[componentKey]?.title ?? '',
                        groupId: String(components[componentKey]?.groupId ?? ''),
                        groupName: group?.title ?? ''
                    }
                }
            }
        }
        return { title: '', groupId: '', groupName: '' }
    }
    /**
     * @return {void}
     */
    this.attachSyncToggles = () => {
        const formPreferencesContainer = this.bannerMain.querySelector(`.${CB_PREFIX}preferences`)

        Array.from(formPreferencesContainer.querySelectorAll(`.${CB_PREFIX}component input:not(:disabled)`)).forEach(input => {

            input.addEventListener('change', () => {
                const groupId = input.dataset.groupId
                const siblingComponents = Array.from(
                    formPreferencesContainer.querySelectorAll(`.${CB_PREFIX}component input`)
                ).filter(el => el.dataset.groupId === groupId).map(el => el.checked)

                const group = document.querySelector(`.${CB_PREFIX}group input[name="${CB_GROUP_PREFIX + groupId}"]`)

                group.indeterminate = false
                if (!siblingComponents.includes(true))
                    group.checked = false
                else if (!siblingComponents.includes(false))
                    group.checked = true
                else
                    group.indeterminate = true
            })
        })

        Array.from(formPreferencesContainer.querySelectorAll(`.${CB_PREFIX}group input[name^=${CB_GROUP_PREFIX}]:not(:disabled)`)).forEach(input => {

            input.addEventListener('change', () => {
                const groupId = input.name.replace(CB_GROUP_PREFIX, '')
                Array.from(
                    formPreferencesContainer.querySelectorAll(`.${CB_PREFIX}component input`)
                )
                    .filter(el => el.dataset.groupId === groupId)
                    .forEach(component => component.checked = input.checked)
            })
        })
    }
    /**
     * @param {Object} consentServiceData
     * @return {void}
     */
    this.savePreferences = (consentServiceData) => {
        const lastPreferences = this.getLastPreferences();
        const userConsentLogData = {
            hash : "",
            services : {},
            version : "",
            timestamp : ""
        };

        userConsentLogData.hash = lastPreferences.hash
        userConsentLogData.services = consentServiceData
        lastPreferences.services = Object.fromEntries(
            Object.entries(consentServiceData).map(
                service => {
                    return [service[0], {'title': service[1]?.title, 'consent' : service[1]?.consent}]
                }
            )
        )

        lastPreferences.timestamp = userConsentLogData.timestamp = Math.floor(Date.now() / 1000)
        lastPreferences.version = userConsentLogData.version = this.bannerPreferences?.banner?.version

        this.localPreferences = lastPreferences;

        this.cookiePreferences = Object.fromEntries(
            Object.entries(consentServiceData).map(
                service => {
                    return [service[0], service[1]?.consent]
                }
            )
        )

        this.setUserConsentCookieServices();
        this.setLocalStorageData();

        this.saveLogUserConsent(userConsentLogData);

        this.closeAndRemoveBanner();
        this.handlePlaceholderElements();
        this.applyIntegrations();
    }
    /**
     * Applies all non-iframe integrations for the current consent state.
     * @return {void}
     */
    this.applyIntegrations = () => {
        this.applyConsentSignals()
        this.applyMatomoConsent()
        this.applyScriptComponents()
    }
    /**
     * Returns all components of a given integration_type from the banner data.
     * @param {string} type
     * @return {Object[]}
     */
    this.componentsByType = (type) => {
        const groups = this.bannerPreferences?.groups ?? {}
        const result = []
        for (const groupKey in groups) {
            const components = groups[groupKey]?.components ?? {}
            for (const componentKey in components) {
                if (components[componentKey]?.integrationType === type) result.push(components[componentKey])
            }
        }
        return result
    }
    /**
     * Matomo: consent is global (one tracker) — granted if any matomo component
     * is consented, otherwise withdrawn.
     * @return {void}
     */
    this.applyMatomoConsent = () => {
        const components = this.componentsByType('matomo')
        if (components.length === 0) return

        const consented = components.some(component => this.cookiePreferences?.[component.id] === true)
        window._paq = window._paq || []
        if (consented) {
            window._paq.push(['setConsentGiven'])
            window._paq.push(['setCookieConsentGiven'])
        } else {
            window._paq.push(['forgetConsentGiven'])
            window._paq.push(['forgetCookieConsentGiven'])
        }
    }
    /**
     * "Script" components: inject the accepted script on consent; on withdrawal
     * remove it again and run the optional rejected (cleanup) script.
     * @return {void}
     */
    this.applyScriptComponents = () => {
        this.componentsByType('script').forEach(component => {
            if (this.cookiePreferences?.[component.id] === true) {
                this.injectComponentScript(component.id, component.acceptedScript)
            } else {
                this.removeComponentScript(component.id, component.rejectedScript)
            }
        })
    }
    /**
     * @param {string} id
     * @param {string} code
     * @return {void}
     */
    this.injectComponentScript = (id, code) => {
        if (!code) return
        if (document.querySelector(`script[data-cb-script="${id}"]`)) return // already injected

        const script = document.createElement('script')
        if (this.nonce) script.nonce = this.nonce
        script.setAttribute('data-cb-script', id)
        script.textContent = code
        document.body.appendChild(script)
    }
    /**
     * @param {string} id
     * @param {string} rejectedCode
     * @return {void}
     */
    this.removeComponentScript = (id, rejectedCode) => {
        const existing = document.querySelector(`script[data-cb-script="${id}"]`)
        if (!existing) return // nothing was injected -> nothing to undo

        existing.remove()
        if (rejectedCode) {
            const script = document.createElement('script')
            if (this.nonce) script.nonce = this.nonce
            script.textContent = rejectedCode
            document.body.appendChild(script)
        }
    }
    /**
     * Pushes a Google Consent Mode `update` for all google_consent_mode
     * components: each signal is granted if the visitor consented to a component
     * that controls it, otherwise explicitly denied (so revoking works too).
     * @return {void}
     */
    this.applyConsentSignals = () => {
        const groups = this.bannerPreferences?.groups ?? {}
        const signalState = {}

        for (const groupKey in groups) {
            const components = groups[groupKey]?.components ?? {}
            for (const componentKey in components) {
                const component = components[componentKey]
                if (component?.integrationType !== 'google_consent_mode') continue

                const granted = this.cookiePreferences?.[component.id] === true
                ;(Array.isArray(component.signals) ? component.signals : []).forEach(signal => {
                    // Once any consented component grants a signal it stays granted.
                    if (signalState[signal] !== 'granted') {
                        signalState[signal] = granted ? 'granted' : 'denied'
                    }
                })
            }
        }

        if (Object.keys(signalState).length === 0) return

        const gtag = window.gtag || function () { (window.dataLayer = window.dataLayer || []).push(arguments) }
        gtag('consent', 'update', signalState)
    }
    /**
     *
     * @return {*|{}}
     */
    this.getLastPreferences = () => {
        return localStorage.getItem(LAST_PREFERENCES_NAME) ? JSON.parse(localStorage.getItem(LAST_PREFERENCES_NAME)) : {};
    }
    /**
     *
     * @return {boolean}
     */
    this.isPreferencesCookie = () => {
        return Object.keys(this.cookiePreferences).length !== 0;
    }
    /**
     *
     * @return {boolean}
     */
    this.isPreferencesLocalStorage = () => {
        return Object.keys(this.localPreferences).length !== 0;
    }
    /**
     * hash is the generate browser fingerprint hash
     * @return {string}
     */
    this.getUserHash = () => {
        const lastPreferences = this.getLastPreferences();
        let hash = lastPreferences?.hash;
        if (!hash) {
            hash = generateUserHash();
            lastPreferences.hash = hash;
            window.localStorage.setItem(LAST_PREFERENCES_NAME, JSON.stringify(lastPreferences))
        }
        return hash
    }
    /**
     * @param {Object} userConsentLogData
     * @return {Promise<void>}
     */
    this.saveLogUserConsent = async (userConsentLogData) => {
        // Kirby keeps /api for the panel API: the banner data name the route.
        const url = this.bannerPreferences?.saveUrl || "/consent-banner-api/save"

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(userConsentLogData)
        });

        if (!response.ok) throw new Error(`Network response was not ok. Status: ${response.status}`);

        try {
            const data = await response.json();
            if (!data.success) {
                console.error('Error saving field:', data.message);
            }
        } catch (error) {
            console.error(error.message);
        }
    }
    /**
     * @property {string} componentTitle
     * @return {Object}
     */
    this.collectData = () => {
        const formPreferencesContainer = this.bannerMain.querySelector(`.${CB_PREFIX}preferences`)
        return Object.fromEntries(
            Array.from(
                formPreferencesContainer.querySelectorAll(`.${CB_PREFIX}component input[data-group-id]`)
            ).map(el => {
                return [el.name, {'title': el.dataset.componentTitle, 'consent' : el.checked, 'groupId': el.dataset.groupId, 'groupName': el.dataset.groupName}]
            })
        )
    }
    /**
     *
     * @param {boolean} value
     * @return {*}
     */
    this.collectAndModifyData = (value) => {
        const data = this.collectData()
        for (let key of Object.keys(data)) data[key].consent = value

        return data
    }
    /**
     * Consent for the locked (essential) components only, everything else is declined.
     * @return {Object}
     */
    this.collectEssentialData = () => {
        const data = this.collectData()
        const formPreferencesContainer = this.bannerMain.querySelector(`.${CB_PREFIX}preferences`)
        for (let key of Object.keys(data)) {
            const input = formPreferencesContainer.querySelector(`.${CB_PREFIX}component input[name="${key}"]`)
            data[key].consent = !!input?.disabled && !!input?.checked
        }

        return data
    }
    /**
     * @return {void}
     */
    this.setUserConsentCookieServices = () => {
        cookieUtils.set(LAST_PREFERENCES_NAME, JSON.stringify(this.cookiePreferences) + ';secure;samesite=lax', this.bannerPreferences?.lifetimes?.userConsent)
    }
    /**
     * @return {void}
     */
    this.setLocalStorageData = () => {
        window.localStorage.setItem(LAST_PREFERENCES_NAME, JSON.stringify(this.localPreferences))
    }
    /**
     * checks if the banner needs to be created
     * @property {Object} lifetimes
     * @return {boolean}
     */
    this.shouldCreateBanner = () => {
        const localStoragePreferences = this.getLastPreferences();
        return Object.keys(this.cookiePreferences).length === 0 ||
            Object.keys(localStoragePreferences?.services ?? {}).length === 0 ||
            localStoragePreferences?.version !== this.bannerPreferences?.banner?.version ||
            localStoragePreferences?.hash !== this.getUserHash() ||
            hasDaysPassed(localStoragePreferences?.timestamp, this.bannerPreferences?.lifetimes?.banner);
    }

}

new CbManager().init();