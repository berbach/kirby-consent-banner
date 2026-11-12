<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

/**
 * The tracking bootstrap for the <head>, before any tag fires:
 *
 *   - Google Consent Mode: gtag stub, `consent default` (denied), a
 *     `consent update` for returning visitors and the GTM loader.
 *   - Matomo: requireConsent/requireCookieConsent, setConsentGiven for
 *     returning visitors and the tracker or the Matomo Tag Manager.
 *
 * The script reads the consent cookie in the browser, so the markup is the
 * same for every visitor and the page cache may keep it. Changes while the
 * page is open are the job of CbLoader.js.
 */
final class ConsentMode
{
    /** @var array<string, string> */
    public const DEFAULT_SIGNALS = [
        'ad_storage' => 'denied',
        'ad_user_data' => 'denied',
        'ad_personalization' => 'denied',
        'analytics_storage' => 'denied',
        'functionality_storage' => 'denied',
        'personalization_storage' => 'denied',
        'security_storage' => 'granted',
    ];

    /**
     * @param array<int, array{id: string, signals: string[]}> $googleComponents
     * @param string[] $matomoComponentIds
     */
    public function __construct(
        private readonly string $gtmContainerId = '',
        private readonly string $matomoUrl = '',
        private readonly string $matomoSiteId = '',
        private readonly string $matomoMtmUrl = '',
        private readonly array $googleComponents = [],
        private readonly array $matomoComponentIds = [],
        private readonly string $cookieName = ConsentBanner::COOKIE_NAME,
    ) {}

    public static function fromBanner(ConsentBanner $consentBanner): self
    {
        $banner = $consentBanner->banner();
        if ($banner === null) {
            return new self();
        }
        $content = $banner->content();
        return new self(
            trim((string) $content->get('gtm_container_id')->value()),
            trim((string) $content->get('matomo_url')->value()),
            trim((string) $content->get('matomo_site_id')->value()),
            trim((string) $content->get('matomo_mtm_url')->value()),
            array_map(
                static fn(array $row): array => ['id' => $row['id'], 'signals' => $row['signals']],
                $consentBanner->componentRows(ConsentBanner::TYPE_GOOGLE),
            ),
            array_column($consentBanner->componentRows(ConsentBanner::TYPE_MATOMO), 'id'),
        );
    }

    /**
     * The inline script without the <script> tag; empty when neither Google
     * Tag Manager nor Matomo is set up.
     */
    public function script(): string
    {
        $lines = array_merge($this->google(), $this->matomo());
        if ($lines === []) {
            return '';
        }
        array_unshift(
            $lines,
            // The consent cookie: {"<component id>": true|false}
            'var p={};try{var m=document.cookie.match(/(?:^|;\s*)' . preg_quote($this->cookieName, '/')
                . '=([^;]*)/);if(m){p=JSON.parse(decodeURIComponent(m[1]))||{}}}catch(e){p={}}',
        );
        return '(function(){' . implode("\n", $lines) . '})();';
    }

    /**
     * @return string[]
     */
    private function google(): array
    {
        // Without a container nothing reads the consent state
        if ($this->gtmContainerId === '') {
            return [];
        }
        $lines = [];
        $lines[] = 'window.dataLayer=window.dataLayer||[];';
        $lines[] = 'window.gtag=window.gtag||function(){window.dataLayer.push(arguments)};';
        $lines[] = "gtag('consent','default'," . self::json(self::DEFAULT_SIGNALS + ['wait_for_update' => 500]) . ');';
        if ($this->googleComponents !== []) {
            $lines[] = 'var g={},n=0;' . self::json($this->googleComponents) . '.forEach(function(c){'
                . "if(p[c.id]===true){c.signals.forEach(function(s){g[s]='granted';n++})}});"
                . "if(n){gtag('consent','update',g)}";
        }
        $lines[] = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
            . "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';"
            . "j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;"
            . "f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . self::json($this->gtmContainerId) . ');';
        return $lines;
    }

    /**
     * @return string[]
     */
    private function matomo(): array
    {
        $hasTracker = $this->matomoUrl !== '' && $this->matomoSiteId !== '';
        $hasMtm = $this->matomoMtmUrl !== '';
        if ($hasTracker === false && $hasMtm === false) {
            return [];
        }
        $lines = [];
        if ($hasMtm) {
            $lines[] = 'var _mtm=window._mtm=window._mtm||[];';
            $lines[] = "_mtm.push({'mtm.startTime':(new Date().getTime()),'event':'mtm.Start'});";
        }
        $lines[] = 'var _paq=window._paq=window._paq||[];';
        $lines[] = "_paq.push(['requireConsent']);";
        $lines[] = "_paq.push(['requireCookieConsent']);";
        if ($this->matomoComponentIds !== []) {
            // Matomo has one tracker: consent to any Matomo component is consent
            $lines[] = 'if(' . self::json(array_values($this->matomoComponentIds)) . '.some(function(i){return p[i]===true})){'
                . "_paq.push(['setConsentGiven']);_paq.push(['setCookieConsentGiven'])}";
        }
        if ($hasMtm) {
            $lines[] = "(function(){var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];"
                . 'g.async=true;g.src=' . self::json($this->matomoMtmUrl) . ';s.parentNode.insertBefore(g,s);})();';
        } else {
            $lines[] = "_paq.push(['trackPageView']);";
            $lines[] = "_paq.push(['enableLinkTracking']);";
            $lines[] = '(function(){var u=' . self::json(rtrim($this->matomoUrl, '/') . '/') . ';'
                . "_paq.push(['setTrackerUrl',u+'matomo.php']);"
                . "_paq.push(['setSiteId'," . self::json($this->matomoSiteId) . ']);'
                . "var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];"
                . "g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();";
        }
        return $lines;
    }

    /**
     * JSON that is safe inside a <script> element.
     */
    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
