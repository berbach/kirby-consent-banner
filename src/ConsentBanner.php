<?php

declare(strict_types=1);

namespace Berbach\ConsentBanner;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;

/**
 * Entry point of the plugin: finds the banner page with its groups and
 * components and hands out the services built on top of them.
 *
 * The three levels of the banner are pages:
 *
 *   consent-banner            (template consent-banner)            the banner
 *     └ analytics             (template consent-banner-group)      a group
 *         └ google-analytics  (template consent-banner-component)  a component
 *
 * A draft is switched off: a banner as draft shows nothing in the frontend, a
 * group or component as draft is left out of the banner.
 */
final class ConsentBanner
{
    public const PLUGIN = 'berbach/consent-banner';
    public const OPTION = 'berbach.consent-banner.';
    public const PERMISSION = 'berbach.consent-banner';

    /** Name of the consent cookie and of the localStorage entry */
    public const COOKIE_NAME = 'BbConsentPreferences';

    public const TEMPLATE_BANNER = 'consent-banner';
    public const TEMPLATE_GROUP = 'consent-banner-group';
    public const TEMPLATE_COMPONENT = 'consent-banner-component';

    public const TYPE_IFRAME = 'iframe';
    public const TYPE_GOOGLE = 'google_consent_mode';
    public const TYPE_MATOMO = 'matomo';
    public const TYPE_SCRIPT = 'script';

    public const LAYOUTS = ['cb-bottom', 'cb-overlay', 'cb-overlay-compact'];

    /** Set while Kirby deletes the banner page with its groups and components */
    public static bool $deleting = false;

    private static ?self $instance = null;

    private ?ConsentLog $log = null;

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $components = null;

    public function __construct(public readonly App $kirby) {}

    public static function instance(?App $kirby = null): self
    {
        $kirby ??= App::instance();
        if (self::$instance === null || self::$instance->kirby !== $kirby) {
            self::$instance = new self($kirby);
        }
        return self::$instance;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->kirby->option(self::OPTION . $name, $default);
    }

    /**
     * Language of the texts a visitor reads: the language of the page, on a
     * site with one language the option "language" (Kirby itself only knows
     * English there).
     */
    public function frontendLocale(): ?string
    {
        if ($this->kirby->multilang()) {
            return $this->kirby->language()?->code();
        }
        $language = $this->option('language');
        return is_string($language) && trim($language) !== '' ? trim($language) : null;
    }

    /**
     * Id of the banner page, "consent-banner" unless the config says otherwise.
     */
    public function pageId(): string
    {
        $id = trim((string) $this->option('page', self::TEMPLATE_BANNER), '/ ');
        return $id !== '' ? $id : self::TEMPLATE_BANNER;
    }

    /**
     * The banner page, also as draft (the panel works with it then).
     */
    public function page(): ?Page
    {
        $page = $this->kirby->site()->findPageOrDraft($this->pageId());
        return $page !== null && $page->intendedTemplate()->name() === self::TEMPLATE_BANNER ? $page : null;
    }

    /**
     * The banner the frontend shows: the banner page, unless it is a draft.
     */
    public function banner(): ?Page
    {
        $page = $this->page();
        return $page !== null && $page->isDraft() === false ? $page : null;
    }

    /**
     * The published groups in their order, essential groups first.
     */
    public function groups(?Page $banner = null): Pages
    {
        $banner ??= $this->banner();
        if ($banner === null) {
            return new Pages();
        }
        $groups = $banner->children()->listed()->filter(
            static fn(Page $page): bool => $page->intendedTemplate()->name() === self::TEMPLATE_GROUP,
        );
        $essential = $groups->filter(static fn(Page $group): bool => self::isEssential($group));
        return $essential->add($groups->not($essential));
    }

    /**
     * The published components of a group in their order.
     */
    public function components(Page $group): Pages
    {
        return $group->children()->listed()->filter(
            static fn(Page $page): bool => $page->intendedTemplate()->name() === self::TEMPLATE_COMPONENT,
        );
    }

    public static function isEssential(Page $group): bool
    {
        return $group->content()->get('group_essential')->toBool();
    }

    /**
     * Whether the page is the banner, one of its groups or components.
     */
    public function owns(Page $page): bool
    {
        $root = $this->pageId();
        return $page->id() === $root || str_starts_with($page->id(), $root . '/');
    }

    /**
     * The id a group or component has in the consent cookie, in the consent
     * log and in the markup: the UUID of the page, which survives a renamed
     * slug. Without UUIDs a hash of the page id takes its place.
     */
    public static function idOf(Page $page): string
    {
        $uuid = $page->uuid();
        if ($uuid !== null) {
            return $uuid->id();
        }
        return substr(md5($page->id()), 0, 16);
    }

    public static function hashOf(Page $page): string
    {
        return substr(md5(self::idOf($page) . '|' . $page->title()->value()), 0, 20);
    }

    /**
     * All published components as plain rows, per integration type:
     * id, slug, title, the consent mode signals and the domains of the iframes
     * the component stands for.
     *
     * @return array<int, array{id: string, slug: string, title: string, groupId: string, groupTitle: string, type: string, signals: string[], domains: string[], placeholderTitle: string, placeholderText: string}>
     */
    public function componentRows(?string $type = null): array
    {
        if ($this->components === null) {
            $this->components = [];
            foreach ($this->groups() as $group) {
                foreach ($this->components($group) as $component) {
                    $row = self::componentRow($component);
                    $this->components[$row['type']][] = $row;
                }
            }
        }
        if ($type !== null) {
            return $this->components[$type] ?? [];
        }
        return array_merge(...array_values($this->components));
    }

    /**
     * @return array{id: string, slug: string, title: string, groupId: string, groupTitle: string, type: string, signals: string[], domains: string[], placeholderTitle: string, placeholderText: string}
     */
    public static function componentRow(Page $component): array
    {
        $content = $component->content();
        $domains = $content->get('component_target')->split(',');
        if ($domains === []) {
            $domains = $content->get('service_provider')->split(',');
        }
        return [
            'id' => self::idOf($component),
            'slug' => $component->slug(),
            'title' => (string) $component->title()->value(),
            'groupId' => $component->parent() !== null ? self::idOf($component->parent()) : '',
            'groupTitle' => (string) ($component->parent()?->title()->value() ?? ''),
            'type' => self::integrationType($component),
            'signals' => array_values(array_intersect(
                $content->get('consent_mode_signals')->split(','),
                array_keys(ConsentMode::DEFAULT_SIGNALS),
            )),
            'domains' => array_values(array_filter(array_map([Placeholder::class, 'normalizeHost'], $domains))),
            'placeholderTitle' => trim((string) $content->get('placeholder_title')->value()),
            'placeholderText' => trim((string) $content->get('placeholder_description')->value()),
        ];
    }

    public static function integrationType(Page $component): string
    {
        $type = (string) $component->content()->get('integration_type')->value();
        return in_array($type, [self::TYPE_IFRAME, self::TYPE_GOOGLE, self::TYPE_MATOMO, self::TYPE_SCRIPT], true)
            ? $type
            : self::TYPE_IFRAME;
    }

    /**
     * A published component by its id or by its slug ("youtube").
     *
     * @return array{id: string, slug: string, title: string, groupId: string, groupTitle: string, type: string, signals: string[], domains: string[], placeholderTitle: string, placeholderText: string}|null
     */
    public function findComponent(string $idOrSlug): ?array
    {
        $idOrSlug = trim($idOrSlug);
        if ($idOrSlug === '') {
            return null;
        }
        foreach ($this->componentRows() as $row) {
            if ($row['id'] === $idOrSlug || $row['slug'] === $idOrSlug) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Version of the banner. It counts up with every change of the banner, a
     * group or a component; the frontend asks again when it differs from the
     * version the visitor agreed to.
     */
    public function version(?Page $banner = null): int
    {
        $banner ??= $this->page();
        if ($banner === null) {
            return 1;
        }
        return max(1, $this->defaultContent($banner)->get('banner_version')->toInt());
    }

    /**
     * Counts the version up; called by the hooks in index.php.
     */
    public function bumpVersion(): void
    {
        static $running = false;
        $banner = $this->page();
        if ($running || $banner === null) {
            return;
        }
        $running = true;
        try {
            $version = $this->version($banner) + 1;
            $language = $this->kirby->defaultLanguage()?->code();
            $this->kirby->impersonate('kirby', static function () use ($banner, $version, $language): void {
                $banner->update(['banner_version' => $version], $language, false);
            });
            $this->components = null;
        } finally {
            $running = false;
        }
    }

    /**
     * Days a consent stays valid; the consent log keeps an entry as long.
     */
    public function lifetimeUserConsent(?Page $banner = null): int
    {
        $banner ??= $this->page();
        $days = $banner?->content()->get('lifetime_user_consent')->toInt() ?? 0;
        return in_array($days, [365, 730, 1095], true) ? $days : 1095;
    }

    public function log(): ConsentLog
    {
        if ($this->log === null) {
            $file = $this->option('database');
            if (is_string($file) === false || trim($file) === '') {
                $logs = $this->kirby->root('logs') ?? $this->kirby->root('site') . '/logs';
                $file = $logs . '/consent-banner/consent-log.sqlite';
            }
            $this->log = new ConsentLog($file);
        }
        return $this->log;
    }

    /**
     * URL of the route that writes the consent log.
     */
    public function saveUrl(): string
    {
        return $this->kirby->url('index') . '/' . self::saveRoute();
    }

    public static function saveRoute(): string
    {
        return 'consent-banner-api/save';
    }

    /**
     * URL of a file below assets/, with the media hash Kirby expects.
     */
    public function assetUrl(string $path): ?string
    {
        return $this->kirby->plugin(self::PLUGIN)?->asset($path)?->url();
    }

    /**
     * The nonce for inline scripts, if the site sends a content security
     * policy: the option "nonce" is a string or a closure.
     */
    public function nonce(): string
    {
        $nonce = $this->option('nonce');
        if ($nonce instanceof \Closure) {
            $nonce = $nonce($this->kirby);
        }
        return is_string($nonce) ? trim($nonce) : '';
    }

    /**
     * A user may open the area and the log unless the role says otherwise:
     * permissions: berbach.consent-banner: access: false
     */
    public function hasAccess(): bool
    {
        $user = $this->kirby->user();
        if ($user === null) {
            return false;
        }
        return $user->role()->permissions()->for(self::PERMISSION, 'access', true);
    }

    /**
     * Fields that are not translated live in the default language.
     */
    private function defaultContent(Page $page): \Kirby\Content\Content
    {
        return $page->content($this->kirby->defaultLanguage()?->code());
    }
}
