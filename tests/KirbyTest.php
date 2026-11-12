<?php

declare(strict_types=1);

namespace Bb\ConsentBanner\Tests;

use Bb\ConsentBanner\BannerData;
use Bb\ConsentBanner\ConsentBanner;
use Bb\ConsentBanner\Renderer;
use Bb\ConsentBanner\SaveAction;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;

/**
 * The plugin inside a Kirby site: a banner with an essential group and a
 * group with one component of every integration type.
 */
final class KirbyTest extends TestCase
{
    private string $root;
    private App $kirby;
    private ConsentBanner $consentBanner;
    private Page $banner;

    /** @var array<string, string> slug => id of the component */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/consent-banner-' . bin2hex(random_bytes(6));
        Dir::make($this->root . '/content');
        $this->kirby = new App([
            'roots' => ['index' => $this->root],
            'urls' => ['index' => 'https://example.com'],
            'options' => ['bb.consent-banner.language' => 'de'],
        ]);
        $this->kirby->impersonate('kirby');
        $this->consentBanner = ConsentBanner::instance($this->kirby);

        $this->banner = $this->kirby->site()->createChild([
            'slug' => 'consent-banner',
            'template' => ConsentBanner::TEMPLATE_BANNER,
            'content' => [
                'title' => 'Cookies',
                'banner_title' => 'Privacy',
                'banner_description' => 'We <strong>ask</strong> first',
                'banner_version' => 1,
                'banner_layout' => 'cb-overlay',
                'privacy_settings_variant' => '20',
                'lifetime_banner' => '21',
                'lifetime_user_consent' => '730',
                'accept_all_text' => 'Yes to all',
            ],
        ]);
        $essential = $this->child($this->banner, 'essential', ConsentBanner::TEMPLATE_GROUP, 2, ['title' => 'Essential', 'group_essential' => 'true']);
        $this->child($essential, 'session', ConsentBanner::TEMPLATE_COMPONENT, 1, ['title' => 'Session', 'integration_type' => 'script']);
        $media = $this->child($this->banner, 'media', ConsentBanner::TEMPLATE_GROUP, 1, ['title' => 'Media']);
        $this->child($media, 'youtube', ConsentBanner::TEMPLATE_COMPONENT, 1, [
            'title' => 'YouTube',
            'integration_type' => 'iframe',
            'placeholder_title' => 'A video',
            'service_provider' => '.youtube.com, .youtube-nocookie.com',
            'cookies' => "- \n  cookie_name: YSC\n  cookie_type: Session-Cookie\n  cookie_purpose: Statistics\n  cookie_lifetime: Session\n  cookie_description: Counts views\n",
        ]);
        $this->child($media, 'analytics', ConsentBanner::TEMPLATE_COMPONENT, 2, [
            'title' => 'Analytics',
            'integration_type' => 'google_consent_mode',
            'consent_mode_signals' => 'analytics_storage, not_a_signal',
        ]);
        $this->child($media, 'chat', ConsentBanner::TEMPLATE_COMPONENT, 3, [
            'title' => 'Chat',
            'integration_type' => 'script',
            'accepted_script' => 'window.chat = true;',
            'rejected_script' => 'delete window.chat;',
        ]);
        $hidden = $media->createChild(['slug' => 'hidden', 'template' => ConsentBanner::TEMPLATE_COMPONENT, 'content' => ['title' => 'Hidden']]);
        $this->ids['hidden'] = ConsentBanner::idOf($hidden);

        $this->banner = $this->banner->changeStatus('unlisted');
        $this->consentBanner = ConsentBanner::instance($this->fresh());
    }

    protected function tearDown(): void
    {
        Dir::remove($this->root);
    }

    public function testADraftBannerIsSwitchedOff(): void
    {
        $this->banner->changeStatus('draft');
        $consentBanner = ConsentBanner::instance($this->fresh());

        self::assertNotNull($consentBanner->page());
        self::assertNull($consentBanner->banner());
        self::assertSame([], (new BannerData($consentBanner))->toArray());
        self::assertSame('', (new Renderer($consentBanner))->body());
        self::assertSame('<html><head></head><body></body></html>', (new Renderer($consentBanner))->process('<html><head></head><body></body></html>', 'html'));
    }

    public function testTheBannerDataHaveTheShapeTheScriptReads(): void
    {
        $data = (new BannerData($this->consentBanner))->toArray();

        self::assertSame('Privacy', $data['banner']['title']);
        self::assertSame('We <strong>ask</strong> first', $data['banner']['description']);
        self::assertSame('cb-overlay', $data['layout']);
        self::assertSame(['banner' => 21, 'userConsent' => 730], $data['lifetimes']);
        self::assertSame(20, $data['openerVariant']);
        self::assertSame('left', $data['openerData']['buttonWidgetPosition']);
        self::assertSame('https://example.com/consent-banner-api/save', $data['saveUrl']);
        self::assertSame('BbConsentPreferences', $data['cName']);
        // A text of the banner wins, an empty one shows the default of the language
        self::assertSame('Yes to all', $data['displayTexts']['buttons']['acceptAll']);
        self::assertSame('Speichern & schließen', $data['displayTexts']['buttons']['saveAndClose']);
    }

    public function testEssentialGroupsComeFirstAndDraftsAreLeftOut(): void
    {
        $groups = (new BannerData($this->consentBanner))->toArray()['groups'];

        self::assertSame([10, 20], array_keys($groups));
        self::assertSame('Essential', $groups[10]['title']);
        self::assertTrue($groups[10]['lockedAndActive']);
        self::assertFalse($groups[20]['lockedAndActive']);
        self::assertSame(['YouTube', 'Analytics', 'Chat'], array_column($groups[20]['components'], 'title'));
        self::assertSame([10, 20, 30], array_keys($groups[20]['components']));
        self::assertSame($groups[20]['id'], $groups[20]['components'][10]['groupId']);
    }

    public function testComponentsCarryOnlyWhatTheirTypeNeeds(): void
    {
        $components = (new BannerData($this->consentBanner))->toArray()['groups'][20]['components'];

        self::assertSame('iframe', $components[10]['integrationType']);
        self::assertSame(
            [['name' => 'YSC', 'type' => 'Session-Cookie', 'purpose' => 'Statistics', 'lifetime' => 'Session', 'description' => 'Counts views']],
            $components[10]['cookies'],
        );
        self::assertSame(['analytics_storage'], $components[20]['signals']);
        self::assertSame('window.chat = true;', $components[30]['acceptedScript']);
        self::assertSame('delete window.chat;', $components[30]['rejectedScript']);
        self::assertSame('', $components[10]['acceptedScript']);
    }

    public function testTheJsonCannotCloseItsScriptElement(): void
    {
        $this->banner->update(['banner_title' => '</script><script>alert(1)</script>']);
        $json = (new BannerData(ConsentBanner::instance($this->fresh())))->toJson();

        self::assertStringNotContainsString('</script>', $json);
        self::assertSame('</script><script>alert(1)</script>', json_decode($json, true)['banner']['title']);
    }

    public function testAPageGetsStylesheetDataAndScript(): void
    {
        $html = (new Renderer($this->consentBanner))->process(
            '<html><head><title>x</title></head><body><p>Hi</p></body></html>',
            'html',
        );

        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="[^"]+ConsentBanner\.css"></head>~', str_replace("\n", '', $html));
        self::assertStringContainsString('<script type="application/json" id="bbBannerData">', $html);
        self::assertMatchesRegularExpression('~<script src="[^"]+CbLoader\.js" defer></script>\s*</body>~', $html);
        // Twice is once
        self::assertSame($html, (new Renderer($this->consentBanner))->process($html, 'html'));
    }

    public function testOtherContentTypesAndThePagesOfTheBannerStayUntouched(): void
    {
        $renderer = new Renderer($this->consentBanner);
        $html = '<html><head></head><body></body></html>';

        self::assertSame($html, $renderer->process($html, 'json'));
        self::assertSame($html, $renderer->process($html, 'html', $this->consentBanner->page()));
    }

    public function testIframesAreReplacedByThePlaceholderOfTheirComponent(): void
    {
        $html = (new Renderer($this->consentBanner))->replaceIframes(
            '<iframe src="https://www.youtube-nocookie.com/embed/x"></iframe><iframe src="https://other.test/"></iframe><iframe src="https://example.com/own"></iframe>',
        );

        self::assertStringContainsString('data-cookiebanner-component="' . $this->ids['youtube'] . '"', $html);
        self::assertStringContainsString('>A video</h3>', $html);
        self::assertStringContainsString('Drittanbieter HTML wurde entfernt.', $html);
        self::assertStringNotContainsString('other.test', $html);
        self::assertStringContainsString('<iframe src="https://example.com/own"></iframe>', $html);
    }

    public function testGateNamesAComponentBySlugOrId(): void
    {
        $renderer = new Renderer($this->consentBanner);

        self::assertStringContainsString('data-cookiebanner-component="' . $this->ids['youtube'] . '"', $renderer->gate('youtube', '<b>x</b>'));
        self::assertStringContainsString('<template class="bb-consentbanner-deferred"><b>x</b></template>', $renderer->gate($this->ids['youtube'], '<b>x</b>'));
        // Without the component the content is not locked away for good
        self::assertSame('<b>x</b>', $renderer->gate('unknown', '<b>x</b>'));
        self::assertSame('<b>x</b>', $renderer->gate('hidden', '<b>x</b>'));
    }

    public function testThePayloadKeepsOnlyComponentsOfTheBanner(): void
    {
        $payload = (new SaveAction($this->consentBanner))->payload([
            'hash' => '0123abcd-01234-01234-01234-012345678',
            'version' => 999,
            'services' => [
                $this->ids['youtube'] => ['title' => " YouTube\x00 ", 'consent' => true, 'groupId' => '', 'groupName' => ''],
                $this->ids['chat'] => ['title' => str_repeat('a', 400), 'consent' => 'yes'],
                $this->ids['hidden'] => ['title' => 'Hidden', 'consent' => true],
                'made-up' => ['title' => 'x', 'consent' => true],
            ],
        ]);

        self::assertNotNull($payload);
        self::assertSame([$this->ids['youtube'], $this->ids['chat']], array_keys($payload['services']));
        self::assertSame('YouTube', $payload['services'][$this->ids['youtube']]['title']);
        // The group comes from the banner when the browser does not send it
        self::assertSame('Media', $payload['services'][$this->ids['youtube']]['groupName']);
        self::assertSame(255, mb_strlen($payload['services'][$this->ids['chat']]['title']));
        self::assertFalse($payload['services'][$this->ids['chat']]['consent']);
        self::assertSame($this->consentBanner->version(), $payload['version']);
    }

    public function testAPayloadThatIsNotOneIsRejected(): void
    {
        $action = new SaveAction($this->consentBanner);
        $services = [$this->ids['youtube'] => ['title' => 'YouTube', 'consent' => true]];

        self::assertNull($action->payload([]));
        self::assertNull($action->payload(['hash' => '<script>', 'services' => $services]));
        self::assertNull($action->payload(['hash' => '0123abcd-01234-01234-01234-012345678', 'services' => 'all']));
        self::assertNull($action->payload(['hash' => '0123abcd-01234-01234-01234-012345678', 'services' => ['made-up' => ['consent' => true]]]));
    }

    public function testAChangeCountsTheVersionUp(): void
    {
        $version = $this->consentBanner->version();

        $this->kirby->site()->findPageOrDraft('consent-banner/media/youtube')->update(['placeholder_title' => 'Another video']);
        self::assertSame($version + 1, ConsentBanner::instance($this->fresh())->version());

        $this->kirby->site()->findPageOrDraft('consent-banner/media/chat')->changeStatus('draft');
        self::assertSame($version + 2, ConsentBanner::instance($this->fresh())->version());
    }

    public function testASaveWithoutAChangeAndOtherPagesDoNotCountUp(): void
    {
        $version = $this->consentBanner->version();

        $page = $this->kirby->site()->findPageOrDraft('consent-banner/media/youtube');
        $page->update(['placeholder_title' => 'A video']);
        $this->kirby->site()->createChild(['slug' => 'about', 'template' => 'default', 'content' => ['title' => 'About']]);

        self::assertSame($version, ConsentBanner::instance($this->fresh())->version());
    }

    public function testDeletingAGroupWithItsComponentsCountsUp(): void
    {
        $version = $this->consentBanner->version();

        $this->kirby->site()->findPageOrDraft('consent-banner/media')->delete(true);
        $consentBanner = ConsentBanner::instance($this->fresh());

        self::assertGreaterThan($version, $consentBanner->version());
        self::assertSame(['Session'], array_column($consentBanner->componentRows(), 'title'));
    }

    public function testThePublishedBannerCanBeDeleted(): void
    {
        $this->kirby->site()->findPageOrDraft('consent-banner')->delete(true);

        self::assertNull(ConsentBanner::instance($this->fresh())->page());
        self::assertFalse(ConsentBanner::$deleting);
    }

    public function testThePagesOfTheBannerHaveNoUrlInTheFrontend(): void
    {
        $this->expectException(\Kirby\Exception\NotFoundException::class);
        $this->consentBanner->page()->render();
    }

    /**
     * @param array<string, mixed> $content
     */
    private function child(Page $parent, string $slug, string $template, int $num, array $content): Page
    {
        $page = $parent->createChild(['slug' => $slug, 'template' => $template, 'content' => $content])->changeStatus('listed', $num);
        $this->ids[$slug] = ConsentBanner::idOf($page);
        return $page;
    }

    /**
     * A new instance reads the content from disk, as the next request does.
     */
    private function fresh(): App
    {
        $this->kirby = new App([
            'roots' => ['index' => $this->root],
            'urls' => ['index' => 'https://example.com'],
            'options' => ['bb.consent-banner.language' => 'de'],
        ]);
        $this->kirby->impersonate('kirby');
        return $this->kirby;
    }
}
