<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

use Kirby\Cms\Page;
use Kirby\Toolkit\I18n;

/**
 * The banner as the frontend script reads it (script#bbBannerData): texts,
 * layout, lifetimes and the groups with their components and cookies. The
 * script builds the banner, the settings and the cookie information from it.
 */
final class BannerData
{
    public function __construct(private readonly ConsentBanner $consentBanner) {}

    /**
     * @return array<string, mixed> empty without a published banner
     */
    public function toArray(): array
    {
        $banner = $this->consentBanner->banner();
        if ($banner === null) {
            return [];
        }
        $content = $banner->content();
        $text = fn(string $field, string $key): string => self::filled($content->get($field)->value()) ?? self::translate($key);

        $variant = $content->get('privacy_settings_variant')->toInt() === 20 ? 20 : 10;
        $layout = (string) $content->get('banner_layout')->value();
        $lifetimeBanner = $content->get('lifetime_banner')->toInt();

        return [
            'banner' => [
                'id' => ConsentBanner::idOf($banner),
                'hash' => ConsentBanner::hashOf($banner),
                'title' => (string) $content->get('banner_title')->or($banner->title())->value(),
                // The script prints the description as HTML
                'description' => (string) $content->get('banner_description')->value(),
                'active' => true,
                'version' => $this->consentBanner->version($banner),
            ],
            'footerNavigation' => $this->footerNavigation($banner),
            'displayTexts' => [
                'buttons' => [
                    'acceptAll' => $text('accept_all_text', 'accept_all_text'),
                    'acceptEssential' => $text('accept_essential_text', 'accept_essential_text'),
                    'close' => self::translate('close_text'),
                    'saveAndClose' => $text('save_and_close_text', 'save_and_close_text'),
                    'confirmSelection' => $text('confirm_selection_text', 'confirm_selection_text'),
                    'advancedSettings' => $text('advanced_settings_text', 'advanced_settings_text'),
                    'showInfo' => $text('cookie_infos_show_text', 'cookie_infos_show_text'),
                    'closeInfo' => $text('cookie_infos_close_text', 'cookie_infos_close_text'),
                ],
                'cookie' => [
                    'name' => $text('cookie_name_text', 'cookie_name_text'),
                    'type' => self::translate('cookie_type_text'),
                    'lifetime' => $text('cookie_lifetime_text', 'cookie_lifetime_text'),
                    'provider' => $text('cookie_provider_text', 'cookie_provider_text'),
                    'purpose' => $text('cookie_purpose_text', 'cookie_purpose_text'),
                    'description' => $text('cookie_description_text', 'cookie_description_text'),
                ],
                'cookieTableHeading' => self::translate('cookie_table_heading'),
                'serviceMeta' => [
                    'publisher' => self::translate('meta_publisher_label'),
                    'provider' => self::translate('meta_provider_label'),
                    'dataCollected' => self::translate('meta_data_collected_label'),
                    'privacy' => self::translate('meta_privacy_label'),
                ],
            ],
            // 10 text link in the footer navigation | 20 button widget
            'openerVariant' => $variant,
            'openerData' => $variant === 10
                ? [
                    'targetFooterNavigation' => trim((string) $content->get('target_footer_navigation')->value()),
                    'textLinkPosition' => $content->get('text_link_position')->value() === 'first' ? 'first' : 'last',
                    'textLinkText' => $text('text_link_text', 'text_link_text'),
                    'type' => 10,
                ]
                : [
                    'buttonWidgetPosition' => $content->get('button_widget_position')->value() === 'right' ? 'right' : 'left',
                    'buttonWidgetText' => $text('button_widget_text', 'button_widget_text'),
                    'type' => 20,
                ],
            'groups' => $this->groups($banner),
            'layout' => in_array($layout, ConsentBanner::LAYOUTS, true) ? $layout : 'cb-bottom',
            'lifetimes' => [
                'banner' => in_array($lifetimeBanner, [14, 21, 28], true) ? $lifetimeBanner : 14,
                'userConsent' => $this->consentBanner->lifetimeUserConsent($banner),
            ],
            'cName' => ConsentBanner::COOKIE_NAME,
            'saveUrl' => $this->consentBanner->saveUrl(),
        ];
    }

    /**
     * JSON for a <script type="application/json"> element.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Groups keyed 10, 20, … as the script expects them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groups(Page $banner): array
    {
        $groups = [];
        $key = 10;
        foreach ($this->consentBanner->groups($banner) as $group) {
            $content = $group->content();
            $essential = ConsentBanner::isEssential($group);
            $id = ConsentBanner::idOf($group);
            $groups[$key] = [
                'id' => $id,
                'hash' => ConsentBanner::hashOf($group),
                'title' => self::filled($group->title()->value())
                    ?? ($essential ? self::translate('essential_title_text') : ''),
                'description' => (string) $content->get('group_description')->value(),
                'components' => $this->components($group, $id),
                'lockedAndActive' => $essential,
            ];
            $key += 10;
        }
        return $groups;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function components(Page $group, string $groupId): array
    {
        $components = [];
        $key = 10;
        foreach ($this->consentBanner->components($group) as $component) {
            $content = $component->content();
            $type = ConsentBanner::integrationType($component);
            $isScript = $type === ConsentBanner::TYPE_SCRIPT;
            $components[$key] = [
                'id' => ConsentBanner::idOf($component),
                'hash' => ConsentBanner::hashOf($component),
                'groupId' => $groupId,
                'title' => (string) $component->title()->value(),
                'description' => (string) $content->get('component_description')->value(),
                'integrationType' => $type,
                'signals' => $type === ConsentBanner::TYPE_GOOGLE
                    ? array_values(array_intersect(
                        $content->get('consent_mode_signals')->split(','),
                        array_keys(ConsentMode::DEFAULT_SIGNALS),
                    ))
                    : [],
                'acceptedScript' => $isScript ? (string) $content->get('accepted_script')->value() : '',
                'rejectedScript' => $isScript ? (string) $content->get('rejected_script')->value() : '',
                'service' => [
                    'name' => (string) $content->get('service_name')->value(),
                    'provider' => (string) $content->get('service_provider')->value(),
                    'privacyLink' => (string) ($content->get('service_privacy_link')->toUrl() ?? ''),
                    'publisher' => (string) $content->get('service_publisher')->value(),
                    'dataCollected' => (string) $content->get('service_data_collected')->value(),
                ],
                'cookies' => $this->cookies($component),
            ];
            $key += 10;
        }
        return $components;
    }

    /**
     * @return array<int, array{name: string, type: string, purpose: string, lifetime: string, description: string}>
     */
    private function cookies(Page $component): array
    {
        $cookies = [];
        foreach ($component->content()->get('cookies')->toStructure() as $cookie) {
            $content = $cookie->content();
            $cookies[] = [
                'name' => (string) $content->get('cookie_name')->value(),
                'type' => (string) $content->get('cookie_type')->value(),
                'purpose' => (string) $content->get('cookie_purpose')->value(),
                'lifetime' => (string) $content->get('cookie_lifetime')->value(),
                'description' => (string) $content->get('cookie_description')->value(),
            ];
        }
        return $cookies;
    }

    /**
     * The links at the bottom of the banner, e.g. imprint and privacy policy.
     *
     * @return array<int, array{uid: string, title: string, url: string}>
     */
    private function footerNavigation(Page $banner): array
    {
        $links = [];
        foreach ($banner->content()->get('banner_navigation')->toPages() as $page) {
            $links[] = [
                'uid' => $page->id(),
                'title' => (string) $page->title()->value(),
                'url' => $page->url(),
            ];
        }
        return $links;
    }

    /**
     * A text of the frontend in the language of the page.
     */
    public static function translate(string $key): string
    {
        $key = ConsentBanner::OPTION . $key;
        $locale = ConsentBanner::instance()->frontendLocale();
        return (string) (I18n::translate($key, null, $locale) ?? I18n::translate($key, null, 'en') ?? '');
    }

    private static function filled(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
