import BrowserFingerprint from "./fingerprint-generator";

const BOT_AGENT_REGEX = new RegExp(['Mozilla/5.0 \\(Linux; Android 11; moto g power \\(2022\\)\\) AppleWebKit/537\\.36 \\(KHTML, like Gecko\\) Chrome/119\\.0\\.0\\.0 Mobile Safari/537\\.36', 'Mozilla/5.0 \\(Macintosh; Intel Mac OS X 10_15_7\\) AppleWebKit/537\\.36 \\(KHTML, like Gecko\\) Chrome/119\\.0\\.0\\.0 Safari/537\\.36', '(?:Googlebot|Bingbot|Baiduspider|YandexBot|DuckDuckBot|Slackbot|Facebookbot|Twitterbot|LinkedInbot|Pinterest|WhatsApp|TelegramBot|Slurp|Sogou|Exabot|ia_archiver|msnbot|YandexMobileBot|AdsBot-Google-Mobile|Googlebot-Image|Googlebot-News|Googlebot-Video|Mediapartners-Google|AdsBot-Google|FeedFetcher-Google|Google-Read-Aloud|Google-Adwords-Instant|Yahoo! Slurp China|Yahoo! Slurp|Y!J-BRW|Y!J-SRD|Y!J-MBS|Y!J-MR2|Y!J-PSCS|Y!J-BSC|Y!J-GECC|Y!J-DSC|Y!J-DBS|Y!J-SRB|Y!J-RTS|Y!J-BEP|Y!J-BRP|Y!J-BSP|Y!J-SRS|Y!J-SRE|Y!J-SRT|Y!J-BRV|Y!J-BSV|Y!J-SBC|Y!J-BRL|Y!J-TRG|Y!J-BRD|Y!J-BRG|Y!J-SRQ|Y!J-BRW|Y!J-BRW|Google PageSpeed)'].join('|'),'i',)
/**
 *
 * @param tag
 * @param attrs
 * @param appendTo
 * @return {*}
 */
export const createElementWithAttrs = (tag, attrs, appendTo) => {
    const el = document.createElement(tag)
    for (const key in attrs) {
        if (!attrs.hasOwnProperty(key)) continue

        if (key === 'innerText')
            el.innerText = attrs[key]
        else if (key === 'innerHTML')
            el.innerHTML = attrs[key]
        else {
            if (key in el)
                el[key] = attrs[key]
            else
                el.setAttribute(
                    key === 'className' ? 'class' : key,
                    attrs[key]
                )
        }
    }
    if (appendTo)
        appendTo.appendChild(el)
    return el;
}
/**
 *
 * @param isGroup
 * @param cbPrefix
 * @param label
 * @param inputName
 * @param description
 * @param inputAttributes
 * @param appendComponents
 * @return {*}
 */
export const createToggle = (isGroup, cbPrefix, label, inputName, description, inputAttributes, appendComponents) => {
    isGroup = isGroup ?? false
    cbPrefix = cbPrefix ?? 'bb-cb-'
    label = label ?? ''
    inputName = inputName ?? ''
    description = description ?? ''
    inputAttributes = inputAttributes ?? {}
    appendComponents = appendComponents ?? false

    const _el = createElementWithAttrs

    const main = _el('div', {
        className: [
            cbPrefix + (isGroup ? 'group' : 'component')
        ].join(' ')
    })

    const labelEl = _el('label', {
        className: 'bb-control-checkbox',
        'aria-label': label
    })

    labelEl.appendChild(_el('span', {
        className: ['bb-control-label', (isGroup ? 'bb-label-group' : 'bb-label-component')].join(' '),
        innerText: label
    }))
    labelEl.appendChild(_el('input', {...inputAttributes, type: 'checkbox', name: inputName}))
    labelEl.appendChild(_el('span', {className: 'bb-toggle'}))
    main.appendChild(labelEl)

    if (description)
        main.appendChild(_el('p', {className: cbPrefix + 'description', innerText: description}))

    if (appendComponents)
        main.appendChild(appendComponents);

    return main
}

export const isBotAgent = () => {
    return (BOT_AGENT_REGEX.test(navigator.userAgent))
}

export const typeofIsAndValueIsNot = (variable, type, value) => typeof variable === type && variable !== value

export const generateUserUid = () => {
    let d = new Date().getTime();
    let d2 = (performance && performance.now && (performance.now() * 1000)) || 0;
    return 'zzzwz2zz-zz3zz-w9zzz-wz6zz-zzzwzzzzz'.replace(/[zw]/g, function(e) {
        let r = Math.random() * 16;
        if (d > 0) {
            r = (d + r) % 16 | 0;
            d = Math.floor(d / 16)
        } else {
            r = (d2 + r) % 16 | 0;
            d2 = Math.floor(d2 / 16)
        }
        return (e === 'z' ? r : (r & 0x3 | 0x8)).toString(16)
    })
}

export const generateUserHash = () => {
    const fingerprint = new BrowserFingerprint();
    let hash = fingerprint.generateHash()
    let i = 0;
    return '########-#####-#####-#####-#########'.replace(/#/g, () => hash[i++]);
}

export const hasDaysPassed = (timestamp, days) => {
    if (!timestamp) return true;
    // timestamp and Date.now()/1000 are in SECONDS, so compare against seconds/day.
    const secondsPerDay = 24 * 60 * 60;
    return (Math.floor(Date.now() / 1000) - timestamp) >= days * secondsPerDay;
}