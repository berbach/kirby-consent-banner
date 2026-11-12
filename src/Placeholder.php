<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

/**
 * Placeholders for content that needs consent.
 *
 * A placeholder of a component carries an accept toggle and the real content
 * in an inert <template>; the frontend script swaps it in on consent and back
 * out on withdrawal, without a page reload. The markup is the same for every
 * visitor, so the page cache may keep it.
 */
final class Placeholder
{
    public const CSS_CLASS = 'bb-consentbanner-placeholder';
    public const VARIABLE_HEIGHT = 'bb-consentbanner-placeholder-variable-height';

    /** Parts of a page whose iframes are no iframes of the page */
    private const PROTECTED_PARTS = '~(<script\b[^>]*>.*?</script\s*>|<template\b[^>]*>.*?</template\s*>|<textarea\b[^>]*>.*?</textarea\s*>|<noscript\b[^>]*>.*?</noscript\s*>|<!--.*?-->)~is';

    private const IFRAME = '~<iframe\b[^>]*>(?:.*?</iframe\s*>)?~is';

    /**
     * The markup of a placeholder. With a component id it has the accept
     * toggle, without one only headline and text.
     *
     * @param string $text HTML, already safe to print
     * @param string $deferred the real content, kept inert until consent
     * @param array<string, string> $attributes
     */
    public static function build(
        string $headline,
        string $text = '',
        string $componentId = '',
        string $deferred = '',
        string $class = '',
        array $attributes = [],
    ): string {
        $headlineEscaped = self::escape($headline);
        $idEscaped = self::escape($componentId);

        $classes = self::CSS_CLASS . (trim($class) !== '' ? ' ' . trim($class) : '');
        $more = '';
        foreach ($attributes as $name => $value) {
            if (preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', (string) $name) === 1) {
                $more .= ' ' . $name . '="' . self::escape((string) $value) . '"';
            }
        }
        $component = $componentId !== '' ? ' data-cookiebanner-component="' . $idEscaped . '"' : '';

        $html = '<div class="' . self::escape($classes) . '"' . $component . $more . '>';
        $html .= '<div class="bb-consentbanner-placeholder-wrapper">';
        if ($headline !== '') {
            $html .= '<h3 class="bb-consentbanner-placeholder-headline">' . $headlineEscaped . '</h3>';
        }
        if ($componentId !== '') {
            $html .= '<div class="bb-consentbanner-component" data-cookiebanner-component="' . $idEscaped . '">'
                . '<label class="bb-control-checkbox" aria-label="' . $headlineEscaped . '">'
                . '<span class="bb-control-label bb-label-module">' . $headlineEscaped . '</span>'
                . '<input type="checkbox" name="' . $idEscaped . '">'
                . '<span class="bb-toggle"></span>'
                . '</label>';
            if ($text !== '') {
                $html .= '<span class="bb-consentbanner-placeholder-text">' . $text . '</span>';
            }
            $html .= '</div>';
        } elseif ($text !== '') {
            $html .= '<span class="bb-consentbanner-placeholder-text">' . $text . '</span>';
        }
        $html .= '</div>';
        if ($deferred !== '') {
            $html .= '<template class="bb-consentbanner-deferred">' . $deferred . '</template>';
        }
        return $html . '</div>';
    }

    /**
     * Puts the content behind the consent for a component.
     *
     * @param array{id: string, title: string, placeholderTitle: string, placeholderText: string} $component
     * @param array<string, string> $attributes
     */
    public static function gate(array $component, string $content, string $class = '', array $attributes = []): string
    {
        $headline = $component['placeholderTitle'] !== '' ? $component['placeholderTitle'] : $component['title'];
        return self::build(
            $headline,
            self::text($component['placeholderText']),
            $component['id'],
            $content,
            $class,
            $attributes,
        );
    }

    /**
     * Replaces every iframe of another host: with the placeholder of the
     * component that names the host, otherwise with the generic placeholder
     * (no toggle, the iframe is gone). Iframes of the own host, relative ones
     * and iframes inside scripts, templates and comments stay as they are.
     *
     * @param array<int, array{id: string, title: string, domains: string[], placeholderTitle: string, placeholderText: string}> $components
     * @param string $generic markup for iframes no component stands for
     */
    public static function replaceIframes(string $html, string $ownHost, array $components, string $generic): string
    {
        if (stripos($html, '<iframe') === false) {
            return $html;
        }
        $ownHost = self::normalizeHost($ownHost);
        $replace = static function (string $part) use ($ownHost, $components, $generic): string {
            $result = preg_replace_callback(
                self::IFRAME,
                static function (array $match) use ($ownHost, $components, $generic): string {
                    $host = self::iframeHost($match[0]);
                    if ($host === '' || $host === $ownHost) {
                        return $match[0];
                    }
                    $component = self::componentForHost($host, $components);
                    return $component !== null
                        ? self::gate($component, $match[0], self::VARIABLE_HEIGHT)
                        : $generic;
                },
                $part,
            );
            return $result ?? $part;
        };

        $parts = preg_split(self::PROTECTED_PARTS, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            // The page was too much for the pattern: rather replace an iframe
            // inside a script than leave one in the page.
            return $replace($html);
        }
        foreach ($parts as $index => $part) {
            // preg_split with a capturing group: text, delimiter, text, …
            if ($index % 2 === 0 && $part !== '') {
                $parts[$index] = $replace($part);
            }
        }
        return implode('', $parts);
    }

    /**
     * Host of the iframe source, without "www."; empty for relative sources
     * and iframes without a source.
     */
    public static function iframeHost(string $iframe): string
    {
        // Attribute by attribute, so that "src=" inside another value is no source
        preg_match_all(
            '~\s([^\s=>"\'/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~',
            (string) preg_replace('~^<iframe~i', '', $iframe),
            $attributes,
            PREG_SET_ORDER,
        );
        $source = '';
        foreach ($attributes as $attribute) {
            if (strtolower($attribute[1]) === 'src') {
                $source = ($attribute[2] ?? '') . ($attribute[3] ?? '') . ($attribute[4] ?? '');
                break;
            }
            if (str_contains($attribute[0], '>')) {
                break;
            }
        }
        $source = html_entity_decode(trim($source), ENT_QUOTES | ENT_HTML5);
        if ($source === '' || preg_match('~^(?:https?:)?//~i', $source) !== 1) {
            return '';
        }
        $host = parse_url($source, PHP_URL_HOST);
        return is_string($host) ? self::normalizeHost($host) : '';
    }

    /**
     * "www.YouTube.com", ".youtube.com" and "https://youtube.com/x" all become
     * "youtube.com".
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_contains($host, '/')) {
            $parsed = parse_url(str_contains($host, '//') ? $host : '//' . $host, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : '';
        }
        $host = ltrim($host, '.*');
        $host = (string) preg_replace('/:\d+$/', '', $host);
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @param array<int, array{id: string, title: string, domains: string[], placeholderTitle: string, placeholderText: string}> $components
     * @return array{id: string, title: string, domains: string[], placeholderTitle: string, placeholderText: string}|null
     */
    public static function componentForHost(string $host, array $components): ?array
    {
        foreach ($components as $component) {
            foreach ($component['domains'] as $domain) {
                if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                    return $component;
                }
            }
        }
        return null;
    }

    /**
     * The placeholder text of a component: plain text, line breaks kept.
     */
    public static function text(string $text): string
    {
        return nl2br(self::escape(trim($text)), false);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
