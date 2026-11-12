<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

use Kirby\Cms\Page;
use Kirby\Exception\PermissionException;
use Kirby\Toolkit\I18n;
use Throwable;

/**
 * The area "Consent Banner" of the panel: the overview of the banner with its
 * groups and components, the consent log and the dialog that creates the
 * banner.
 */
final class Panel
{
    public const LOG_LIMIT = 50;

    public function __construct(private readonly ConsentBanner $consentBanner) {}

    /**
     * @return array<string, mixed>
     */
    public function area(): array
    {
        // Kirby binds the closures of a route to the route: no $this in them
        $panel = $this;
        return [
            'label' => self::t('area'),
            'icon' => 'shield',
            'menu' => $this->consentBanner->hasAccess(),
            'link' => 'consent-banner',
            'views' => [
                [
                    'pattern' => 'consent-banner',
                    'action' => fn(): array => $panel->overview(),
                ],
                [
                    'pattern' => 'consent-banner/consents',
                    'action' => fn(): array => $panel->consents(),
                ],
            ],
            'dialogs' => [
                'consent-banner/create' => [
                    'load' => fn(): array => $panel->createDialog(),
                    'submit' => fn(): array => $panel->create(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $this->guard();
        $banner = $this->consentBanner->page();
        $log = $this->consentBanner->log();

        $consents = 0;
        try {
            $consents = $log->exists() ? $log->count() : 0;
        } catch (Throwable) {
            // The overview still opens; the log view names the problem
        }

        return [
            'component' => 'k-consent-banner-view',
            'title' => self::t('area'),
            'props' => [
                'banner' => $banner !== null ? $this->bannerProps($banner) : null,
                'groups' => $banner !== null ? $this->groupProps($banner) : [],
                'consents' => $consents,
                'pageId' => $this->consentBanner->pageId(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function consents(): array
    {
        $this->guard();
        $request = $this->consentBanner->kirby->request();
        $search = trim((string) $request->get('search', ''));
        $page = max(1, (int) $request->get('page', 1));
        $log = $this->consentBanner->log();

        $rows = [];
        $total = 0;
        $error = '';
        try {
            if ($log->exists()) {
                // Entries older than the lifetime of a consent are not kept
                $log->deleteExpired($this->consentBanner->lifetimeUserConsent());
                $total = $log->count($search);
                $page = min($page, max(1, (int) ceil($total / self::LOG_LIMIT)));
                $rows = array_map(
                    [self::class, 'consentRow'],
                    $log->find($search, self::LOG_LIMIT, ($page - 1) * self::LOG_LIMIT),
                );
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return [
            'component' => 'k-consent-banner-consents-view',
            'title' => self::t('consents.headline'),
            'breadcrumb' => [
                ['label' => self::t('consents.headline'), 'link' => 'consent-banner/consents'],
            ],
            'props' => [
                'rows' => $rows,
                'search' => $search,
                'error' => $error,
                'pagination' => ['page' => $page, 'limit' => self::LOG_LIMIT, 'total' => $total],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createDialog(): array
    {
        $this->guard();
        return [
            'component' => 'k-form-dialog',
            'props' => [
                'fields' => [
                    'title' => [
                        'label' => self::t('field.banner_title'),
                        'type' => 'text',
                        'required' => true,
                    ],
                ],
                'value' => ['title' => self::t('banner.default.title')],
                'submitButton' => self::t('action.banner.new.title'),
            ],
        ];
    }

    /**
     * Creates the banner as a draft, with the essential group every banner
     * has, and opens it.
     *
     * @return array<string, mixed>
     */
    public function create(): array
    {
        $this->guard();
        $kirby = $this->consentBanner->kirby;
        if ($this->consentBanner->page() !== null) {
            throw new PermissionException(['fallback' => self::t('error.banner.exists')]);
        }
        $title = trim((string) $kirby->request()->get('title', ''));
        $title = $title !== '' ? $title : self::t('banner.default.title');
        $id = $this->consentBanner->pageId();
        $parentId = dirname($id);
        $parent = $parentId === '.' ? $kirby->site() : $kirby->site()->findPageOrDraft($parentId);
        if ($parent === null) {
            throw new PermissionException(['fallback' => self::t('error.banner.parent')]);
        }

        $banner = $parent->createChild([
            'slug' => basename($id),
            'template' => ConsentBanner::TEMPLATE_BANNER,
            'content' => [
                'title' => $title,
                'banner_title' => $title,
                'banner_version' => 1,
            ],
        ]);
        $banner->createChild([
            'slug' => 'essential',
            'template' => ConsentBanner::TEMPLATE_GROUP,
            'num' => 1,
            'content' => [
                'title' => self::t('essential_title_text'),
                'group_essential' => 'true',
            ],
        ]);

        return [
            'event' => 'page.create',
            'redirect' => $banner->panel()->url(true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bannerProps(Page $banner): array
    {
        $content = $banner->content();
        $layout = (string) $content->get('banner_layout')->value();
        $layout = in_array($layout, ConsentBanner::LAYOUTS, true) ? $layout : 'cb-bottom';
        return [
            'title' => (string) $banner->title()->value(),
            'link' => $banner->panel()->url(true),
            'active' => $banner->isDraft() === false,
            'version' => $this->consentBanner->version($banner),
            'layout' => self::t('banner.layout.' . $layout),
            'lifetime' => $this->consentBanner->lifetimeUserConsent($banner),
        ];
    }

    /**
     * All groups with their components, drafts included.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupProps(Page $banner): array
    {
        $groups = [];
        foreach ($banner->childrenAndDrafts() as $group) {
            if ($group->intendedTemplate()->name() !== ConsentBanner::TEMPLATE_GROUP) {
                continue;
            }
            $components = [];
            foreach ($group->childrenAndDrafts() as $component) {
                if ($component->intendedTemplate()->name() !== ConsentBanner::TEMPLATE_COMPONENT) {
                    continue;
                }
                $components[] = [
                    'title' => (string) $component->title()->value(),
                    'link' => $component->panel()->url(true),
                    'active' => $component->isListed(),
                    'type' => self::t('field.integration_type.' . ConsentBanner::integrationType($component)),
                    'service' => (string) $component->content()->get('service_name')->value(),
                    'cookies' => $component->content()->get('cookies')->toStructure()->count(),
                ];
            }
            $groups[] = [
                'title' => (string) $group->title()->value(),
                'link' => $group->panel()->url(true),
                'active' => $group->isListed(),
                'essential' => ConsentBanner::isEssential($group),
                'components' => $components,
            ];
        }
        return $groups;
    }

    /**
     * A row of the log with its services sorted into their groups.
     *
     * @param array{identification_key: string, banner_version: int, consent_services: array<string, mixed>, tstamp: int, crdate: int} $row
     * @return array<string, mixed>
     */
    public static function consentRow(array $row): array
    {
        $groups = [];
        foreach ($row['consent_services'] as $service) {
            if (is_array($service) === false) {
                continue;
            }
            $group = trim((string) ($service['groupName'] ?? ''));
            $groups[$group]['title'] = $group;
            $groups[$group]['services'][] = [
                'title' => (string) ($service['title'] ?? ''),
                'consent' => ($service['consent'] ?? false) === true,
            ];
        }
        return [
            'id' => $row['identification_key'],
            'version' => $row['banner_version'],
            'groups' => array_values($groups),
            'created' => date('d.m.Y H:i', $row['crdate']),
            'updated' => date('d.m.Y H:i', $row['tstamp']),
        ];
    }

    private function guard(): void
    {
        if ($this->consentBanner->hasAccess() === false) {
            throw new PermissionException(['fallback' => self::t('error.access')]);
        }
    }

    /**
     * A text of the panel in the language of the user.
     */
    public static function t(string $key): string
    {
        $key = ConsentBanner::OPTION . $key;
        return (string) (I18n::translate($key) ?? I18n::translate($key, null, 'en') ?? $key);
    }
}
