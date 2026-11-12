<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

use Kirby\Cms\Page;

/**
 * What the plugin adds to a page of the frontend:
 *
 *   head()  stylesheet and the tracking bootstrap, as early as possible
 *   body()  the banner data and the script that builds the banner
 *
 * Both are printed by the snippets consent-banner/head and
 * consent-banner/body. Unless the option "inject" is off, process() puts them
 * into every HTML page by itself and replaces the iframes of other hosts.
 */
final class Renderer
{
    public const DATA_ID = 'bbBannerData';

    public function __construct(private readonly ConsentBanner $consentBanner) {}

    public function head(): string
    {
        if ($this->consentBanner->banner() === null) {
            return '';
        }
        $html = '';
        $css = $this->consentBanner->assetUrl('dist/Css/ConsentBanner.css');
        if ($css !== null && $this->consentBanner->option('css', true) !== false) {
            $html .= '<link rel="stylesheet" href="' . self::escape($css) . '">' . "\n";
        }
        $script = ConsentMode::fromBanner($this->consentBanner)->script();
        if ($script !== '') {
            $html .= '<script' . $this->nonceAttribute() . '>' . $script . '</script>' . "\n";
        }
        return $html;
    }

    public function body(): string
    {
        if ($this->consentBanner->banner() === null) {
            return '';
        }
        $js = $this->consentBanner->assetUrl('dist/JavaScript/CbLoader.js');
        if ($js === null) {
            return '';
        }
        $nonce = $this->nonceAttribute();
        return '<script type="application/json" id="' . self::DATA_ID . '"' . $nonce . '>'
            . (new BannerData($this->consentBanner))->toJson()
            . '</script>' . "\n"
            . '<script src="' . self::escape($js) . '" defer' . $nonce . '></script>' . "\n";
    }

    /**
     * Puts the content behind the consent for a component, named by its slug
     * or id. Without such a component the content is printed as it is.
     *
     * @param array<string, string> $attributes
     */
    public function gate(string $component, string $content, string $class = '', array $attributes = []): string
    {
        $row = $this->consentBanner->banner() !== null ? $this->consentBanner->findComponent($component) : null;
        return $row !== null ? Placeholder::gate($row, $content, $class, $attributes) : $content;
    }

    /**
     * The generic placeholder for an iframe no component stands for.
     */
    public function removedPlaceholder(): string
    {
        return Placeholder::build(
            BannerData::translate('placeholderHeadline.removed.html'),
            htmlspecialchars(BannerData::translate('placeholder.removed.html'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '',
            '',
            Placeholder::VARIABLE_HEIGHT,
        );
    }

    /**
     * Replaces the iframes of other hosts in a piece of HTML.
     */
    public function replaceIframes(string $html): string
    {
        if ($this->consentBanner->banner() === null) {
            return $html;
        }
        $host = (string) parse_url($this->consentBanner->kirby->url('index'), PHP_URL_HOST);
        return Placeholder::replaceIframes(
            $html,
            $host,
            $this->consentBanner->componentRows(ConsentBanner::TYPE_IFRAME),
            $this->removedPlaceholder(),
        );
    }

    /**
     * The hook page.render:after: adds head() and body() to a rendered HTML
     * page and replaces its iframes.
     */
    public function process(string $html, string $contentType, ?Page $page = null): string
    {
        if ($contentType !== 'html' || $this->consentBanner->banner() === null) {
            return $html;
        }
        if ($page !== null && $this->consentBanner->owns($page)) {
            return $html;
        }
        if ($this->consentBanner->option('iframes', true) !== false) {
            $html = $this->replaceIframes($html);
        }
        if ($this->consentBanner->option('inject', true) === false) {
            return $html;
        }
        // The snippets are in the template already
        if (str_contains($html, 'id="' . self::DATA_ID . '"')) {
            return $html;
        }
        $html = self::insertBefore($html, '</head>', $this->head(), false);
        return self::insertBefore($html, '</body>', $this->body(), true);
    }

    /**
     * Inserts before the first or the last closing tag of that name; a page
     * without the tag stays as it is.
     */
    private static function insertBefore(string $html, string $tag, string $insert, bool $last): string
    {
        if ($insert === '') {
            return $html;
        }
        $position = $last ? strripos($html, $tag) : stripos($html, $tag);
        if ($position === false) {
            return $html;
        }
        return substr($html, 0, $position) . $insert . substr($html, $position);
    }

    private function nonceAttribute(): string
    {
        $nonce = $this->consentBanner->nonce();
        return $nonce !== '' ? ' nonce="' . self::escape($nonce) . '"' : '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
