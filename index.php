<?php

use Berbach\ConsentBanner\ConsentBanner;
use Berbach\ConsentBanner\HiddenPage;
use Berbach\ConsentBanner\Panel;
use Berbach\ConsentBanner\Renderer;
use Berbach\ConsentBanner\SaveAction;
use Berbach\ConsentBanner\ServiceDatabase;
use Kirby\Cms\App as Kirby;
use Kirby\Cms\Page;
use Kirby\Exception\PermissionException;

if (!class_exists(ConsentBanner::class)) {
    // Installed without Composer (download or git submodule)
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Berbach\\ConsentBanner\\')) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Berbach\\ConsentBanner\\'))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

if (!function_exists('consentBanner')) {
    /**
     * For templates and snippets:
     *
     *   consentBanner()->head()                 stylesheet and tracking bootstrap
     *   consentBanner()->body()                 banner data and script
     *   consentBanner()->gate('youtube', $html) content behind the consent for a component
     *   consentBanner()->replaceIframes($html)  placeholders for the iframes of other hosts
     */
    function consentBanner(): Renderer
    {
        return new Renderer(ConsentBanner::instance());
    }
}

/**
 * The texts with the prefix of the plugin.
 */
$translations = static function (string $language): array {
    $texts = [];
    foreach (require __DIR__ . '/translations/' . $language . '.php' as $key => $text) {
        $texts[ConsentBanner::OPTION . $key] = $text;
    }
    return $texts;
};

/**
 * A change of the banner, a group or a component counts the version up, so
 * visitors are asked again. Only a published banner has visitors.
 */
$bump = static function (Page $page): void {
    $consentBanner = ConsentBanner::instance();
    // While the banner itself is deleted its pages go one by one: nothing to count
    if (ConsentBanner::$deleting) {
        return;
    }
    if ($consentBanner->owns($page) && $consentBanner->banner() !== null) {
        $consentBanner->bumpVersion();
    }
};

Kirby::plugin(ConsentBanner::PLUGIN, [
    'options' => [
        // Language of the default texts on a site with one language, e.g. 'de'
        'language' => null,
        // Id of the banner page
        'page' => ConsentBanner::TEMPLATE_BANNER,
        // Add stylesheet, tracking bootstrap, banner data and script to every HTML page
        'inject' => true,
        // Replace the iframes of other hosts with placeholders
        'iframes' => true,
        // Load the stylesheet of the plugin
        'css' => true,
        // File of the consent log; default: site/logs/consent-banner/consent-log.sqlite
        'database' => null,
        // Nonce for inline scripts: a string or a closure
        'nonce' => null,
    ],

    // Role blueprints: permissions: berbach.consent-banner: access: false
    'permissions' => [
        'access' => true,
    ],

    'translations' => [
        'de' => $translations('de'),
        'en' => $translations('en'),
    ],

    'blueprints' => [
        'pages/' . ConsentBanner::TEMPLATE_BANNER => __DIR__ . '/blueprints/pages/consent-banner.yml',
        'pages/' . ConsentBanner::TEMPLATE_GROUP => __DIR__ . '/blueprints/pages/consent-banner-group.yml',
        'pages/' . ConsentBanner::TEMPLATE_COMPONENT => __DIR__ . '/blueprints/pages/consent-banner-component.yml',
    ],

    // The pages of the banner have no URL in the frontend
    'pageModels' => [
        ConsentBanner::TEMPLATE_BANNER => HiddenPage::class,
        ConsentBanner::TEMPLATE_GROUP => HiddenPage::class,
        ConsentBanner::TEMPLATE_COMPONENT => HiddenPage::class,
    ],

    'snippets' => [
        'consent-banner/head' => __DIR__ . '/snippets/consent-banner/head.php',
        'consent-banner/body' => __DIR__ . '/snippets/consent-banner/body.php',
        'consent-banner/gate' => __DIR__ . '/snippets/consent-banner/gate.php',
    ],

    // Blueprints: type: consentservice (text field with the service search)
    'fields' => [
        'consentservice' => [
            'extends' => 'text',
        ],
    ],

    'areas' => [
        'consent-banner' => fn(Kirby $kirby): array => (new Panel(ConsentBanner::instance($kirby)))->area(),
    ],

    // Panel API (signed-in users): the service search of the field consentservice
    'api' => [
        'routes' => [
            [
                'pattern' => 'consent-banner/services',
                'method' => 'GET',
                'action' => function (): array {
                    if (ConsentBanner::instance()->hasAccess() === false) {
                        throw new PermissionException(['fallback' => Panel::t('error.access')]);
                    }
                    return [
                        'results' => ServiceDatabase::default()->search((string) $this->requestQuery('q', '')),
                    ];
                },
            ],
        ],
    ],

    // Frontend: the script posts the decision of a visitor to the consent log
    'routes' => [
        [
            'pattern' => ConsentBanner::saveRoute(),
            'method' => 'POST',
            'action' => fn() => (new SaveAction(ConsentBanner::instance()))(),
        ],
    ],

    'hooks' => [
        'page.render:after' => function (string $contentType, array $data, string $html, Page $page): string {
            return (new Renderer(ConsentBanner::instance()))->process($html, $contentType, $page);
        },
        'page.update:after' => function (Page $newPage, Page $oldPage) use ($bump): void {
            $new = $newPage->content()->toArray();
            $old = $oldPage->content()->toArray();
            unset($new['banner_version'], $old['banner_version']);
            if ($new != $old) {
                $bump($newPage);
            }
        },
        'page.create:after' => fn(Page $page) => $bump($page),
        'page.delete:before' => function (Page $page): void {
            if ($page->id() === ConsentBanner::instance()->pageId()) {
                ConsentBanner::$deleting = true;
            }
        },
        'page.delete:after' => function (bool $status, Page $page) use ($bump): void {
            if ($page->id() === ConsentBanner::instance()->pageId()) {
                ConsentBanner::$deleting = false;
                return;
            }
            $bump($page);
        },
        'page.duplicate:after' => fn(Page $duplicatePage) => $bump($duplicatePage),
        'page.changeStatus:after' => fn(Page $newPage) => $bump($newPage),
        'page.changeNum:after' => fn(Page $newPage) => $bump($newPage),
        'page.changeTitle:after' => fn(Page $newPage) => $bump($newPage),
    ],
]);
