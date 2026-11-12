import {createElementWithAttrs} from "./utils";

const CB_PREFIX = 'bb-';
const CB_PREFIX_NAME = CB_PREFIX + 'consentbanner';
const CB_NAME = CB_PREFIX_NAME + '-';
const TITLE_ID = CB_PREFIX + 'cookie-info-title';

/**
 * Accessible, responsive cookie information overlay.
 *
 * The overlay is an ARIA dialog (focus is trapped while open, Escape closes it,
 * focus returns to the trigger on close). The cookie data is rendered with divs
 * carrying ARIA table roles so it reads as a table for assistive technology and
 * can reflow to a stacked, per-field layout on small screens (each cell keeps
 * its column label via data-label).
 */
class CookieInformation {

    constructor(data) {
        this.data = data;
        this.overlay = null;
        this.previouslyFocused = null;
        this.onKeydown = this.handleKeydown.bind(this);
    }

    createOverlay() {
        this.overlay = createElementWithAttrs('div', {
            className: [CB_PREFIX_NAME, CB_PREFIX + 'cookie-info', 'bb-cb-overlay'].join(' '),
            tabindex: "-1",
            role: "dialog",
            "aria-modal": "true",
            "aria-labelledby": TITLE_ID,
            "data-nosnippet": "true"
        });

        const overlayBody = createElementWithAttrs('div', {className: [CB_PREFIX + 'cookie-info-body'].join(' ')});
        overlayBody.appendChild(this.createOverlayHeader());
        overlayBody.appendChild(this.createOverlayContent());
        overlayBody.appendChild(this.createOverlayFooter());

        this.overlay.innerHTML = `
            <!--googleoff: index-->
            ${overlayBody.outerHTML}
            <!--googleon: index-->
        `;

        document.querySelector('body').insertAdjacentElement('afterbegin', this.overlay);
        this.attachEventListeners();
    }

    createOverlayHeader() {
        const overlayHeader = createElementWithAttrs('div', {className: [CB_PREFIX + 'cookie-info-header'].join(' ')});
        const title = this.data?.displayTexts?.buttons?.showInfo || 'Cookie Information';
        const closeLabel = this.data?.displayTexts?.buttons?.closeInfo || 'Close';
        overlayHeader.innerHTML = `
            <h2 id="${TITLE_ID}" class="${CB_PREFIX}cookie-info-title">${this.escape(title)}</h2>
            <button type="button" class="${CB_PREFIX}cookie-info-close-x" aria-label="${this.escape(closeLabel)}">
                <span aria-hidden="true">&times;</span>
            </button>
        `;
        return overlayHeader;
    }

    createOverlayContent() {
        const overlayContent = createElementWithAttrs('div', {className: [CB_PREFIX + 'cookie-info-content'].join(' ')});
        overlayContent.innerHTML = this.buildCookieContent();
        return overlayContent;
    }

    createOverlayFooter() {
        const overlayFooter = createElementWithAttrs('div', {className: [CB_PREFIX + 'cookie-info-footer'].join(' ')}),
            btn = createElementWithAttrs("button", {
                className: [CB_NAME + "button", CB_PREFIX + "cookie-info-close"].join(' '),
                type: 'button',
                innerText: this.data?.displayTexts?.buttons?.closeInfo || 'Close'
            });
        overlayFooter.appendChild(btn);
        return overlayFooter;
    }

    /**
     * @param {string} s
     * @return {string}
     */
    escape(s) {
        return String(s ?? '').replace(/[&<>"]/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
    }

    /**
     * Builds the cookie information markup grouped by consent group and service
     * (component). Uses divs with ARIA table roles; column headers come from the
     * configurable banner labels and are repeated per cell via data-label for the
     * stacked mobile layout.
     *
     * @return {string}
     */
    buildCookieContent() {
        const esc = (s) => this.escape(s);
        const labels = this.data?.displayTexts?.cookie || {};
        const cookiesHeading = this.data?.displayTexts?.cookieTableHeading || 'Cookies';
        const groups = this.data?.groups || {};
        // Column headers without a redundant "Cookie " prefix – a single
        // "Cookies" heading is shown above the table instead.
        const short = (l) => {
            const s = String(l ?? '').replace(/^\s*cookie[\s\-–_]*/i, '').trim();
            return s ? s.charAt(0).toUpperCase() + s.slice(1) : String(l ?? '');
        };
        const cols = [
            ['name', short(labels.name || 'Name')],
            ['type', short(labels.type || 'Type')],
            ['purpose', short(labels.purpose || 'Purpose')],
            ['lifetime', short(labels.lifetime || 'Lifetime')],
            ['description', short(labels.description || 'Description')],
        ];

        let html = '';
        Object.values(groups).forEach((group) => {
            const services = Object.values(group?.components || {})
                .filter((c) => Array.isArray(c?.cookies) && c.cookies.length > 0);
            if (services.length === 0) {
                return;
            }
            html += `<section class="${CB_PREFIX}cookie-info-group">`;
            html += `<h3 class="${CB_PREFIX}cookie-info-group-title">${esc(group?.title)}</h3>`;
            services.forEach((service) => {
                const serviceTitle = esc(service?.title);
                html += `<div class="${CB_PREFIX}cookie-info-service">`;
                html += `<h4 class="${CB_PREFIX}cookie-info-service-title">${serviceTitle}</h4>`;
                html += this.buildServiceMeta(service?.service);
                html += `<h5 class="${CB_PREFIX}cookie-info-cookies-title">${esc(cookiesHeading)}</h5>`;
                html += `<div class="${CB_PREFIX}cookie-info-table" role="table" aria-label="${serviceTitle}">`;
                html += `<div class="${CB_PREFIX}cookie-info-row ${CB_PREFIX}cookie-info-row--head" role="row">`;
                cols.forEach(([, label]) => {
                    html += `<span class="${CB_PREFIX}cookie-info-cell ${CB_PREFIX}cookie-info-cell--head" role="columnheader">${esc(label)}</span>`;
                });
                html += `</div>`;
                service.cookies.forEach((cookie) => {
                    html += `<div class="${CB_PREFIX}cookie-info-row" role="row">`;
                    cols.forEach(([key, label]) => {
                        html += `<span class="${CB_PREFIX}cookie-info-cell ${CB_PREFIX}cookie-info-cell--${key}" role="cell" data-label="${esc(label)}">${esc(cookie?.[key])}</span>`;
                    });
                    html += `</div>`;
                });
                html += `</div></div>`;
            });
            html += `</section>`;
        });

        return html || `<p class="${CB_PREFIX}cookie-info-empty">${esc(this.data?.displayTexts?.cookie?.description || 'No cookie details available.')}</p>`;
    }

    /**
     * Renders the service-level meta information (provider, publisher, data
     * collected and privacy link) below the service title. Only non-empty
     * values are shown.
     *
     * @param {{provider?: string, publisher?: string, dataCollected?: string, privacyLink?: string}} [service]
     * @return {string}
     */
    buildServiceMeta(service) {
        if (!service || typeof service !== 'object') {
            return '';
        }
        const esc = (s) => this.escape(s);
        const metaLabels = this.data?.displayTexts?.serviceMeta || {};
        const rows = [];
        const add = (label, valueHtml) => rows.push(
            `<div class="${CB_PREFIX}cookie-info-meta-row">`
            + `<dt class="${CB_PREFIX}cookie-info-meta-label">${esc(label)}</dt>`
            + `<dd class="${CB_PREFIX}cookie-info-meta-value">${valueHtml}</dd>`
            + `</div>`
        );
        if (service.publisher) {
            add(metaLabels.publisher || 'Publisher', esc(service.publisher));
        }
        if (service.provider) {
            add(metaLabels.provider || 'Domains', esc(service.provider));
        }
        if (service.dataCollected) {
            add(metaLabels.dataCollected || 'Data collected', esc(service.dataCollected));
        }
        if (service.privacyLink) {
            const href = esc(service.privacyLink);
            add(
                metaLabels.privacy || 'Privacy policy',
                `<a class="${CB_PREFIX}cookie-info-meta-link" href="${href}" target="_blank" rel="noopener noreferrer">${href}</a>`
            );
        }
        if (rows.length === 0) {
            return '';
        }
        return `<dl class="${CB_PREFIX}cookie-info-meta">${rows.join('')}</dl>`;
    }

    attachEventListeners() {
        this.overlay?.querySelectorAll(`.${CB_PREFIX}cookie-info-close, .${CB_PREFIX}cookie-info-close-x`)
            .forEach((btn) => btn.addEventListener('click', () => this.hide()));
        this.overlay?.addEventListener('keydown', this.onKeydown);
    }

    /**
     * @return {HTMLElement[]}
     */
    getFocusable() {
        if (!this.overlay) {
            return [];
        }
        return Array.from(this.overlay.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        )).filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
    }

    handleKeydown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            this.hide();
            return;
        }
        if (e.key !== 'Tab') {
            return;
        }
        // Fokus-Falle: Tab-Navigation innerhalb des Dialogs halten.
        const focusable = this.getFocusable();
        if (focusable.length === 0) {
            e.preventDefault();
            this.overlay.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    show() {
        if (!this.overlay) {
            this.createOverlay();
        }
        this.previouslyFocused = document.activeElement;
        this.overlay.style.zIndex = '100';
        this.overlay.classList.add('visible');
        // Fokus auf den Dialog-Container (aria-labelledby → Titel wird angesagt).
        this.overlay.focus();
    }

    hide() {
        if (!this.overlay) {
            return;
        }
        this.overlay.removeEventListener('keydown', this.onKeydown);
        this.overlay.classList.remove('visible');
        this.overlay.remove();
        this.overlay = null;
        // Fokus zum auslösenden Element zurückgeben.
        if (this.previouslyFocused && typeof this.previouslyFocused.focus === 'function') {
            this.previouslyFocused.focus();
        }
    }
}

export default CookieInformation;
