<?php

declare(strict_types=1);

namespace Berbach\ConsentBanner\Tests;

use Berbach\ConsentBanner\ConsentMode;
use PHPUnit\Framework\TestCase;

final class ConsentModeTest extends TestCase
{
    public function testWithoutTrackingThereIsNoScript(): void
    {
        self::assertSame('', (new ConsentMode())->script());
        // Components alone load nothing
        self::assertSame('', (new ConsentMode(googleComponents: [['id' => 'ga', 'signals' => ['analytics_storage']]]))->script());
    }

    public function testGoogleStartsDeniedAndLoadsTheContainer(): void
    {
        $script = (new ConsentMode('GTM-TEST1', googleComponents: [['id' => 'ga', 'signals' => ['analytics_storage']]]))->script();

        self::assertStringContainsString("gtag('consent','default',", $script);
        self::assertStringContainsString('"analytics_storage":"denied"', $script);
        self::assertStringContainsString('"security_storage":"granted"', $script);
        self::assertStringContainsString('"wait_for_update":500', $script);
        self::assertStringContainsString("gtag('consent','update',g)", $script);
        self::assertStringContainsString('"GTM-TEST1"', $script);
        self::assertStringContainsString('BbConsentPreferences', $script);
        self::assertStringNotContainsString('_paq', $script);
    }

    public function testMatomoRequiresConsent(): void
    {
        $script = (new ConsentMode(matomoUrl: 'https://matomo.test', matomoSiteId: '7', matomoComponentIds: ['m1']))->script();

        self::assertStringContainsString("_paq.push(['requireConsent']);", $script);
        self::assertStringContainsString("_paq.push(['requireCookieConsent']);", $script);
        self::assertStringContainsString('["m1"].some(', $script);
        self::assertStringContainsString('"https://matomo.test/"', $script);
        self::assertStringContainsString("['setSiteId',\"7\"]", $script);
        self::assertStringNotContainsString('gtag', $script);
    }

    public function testTheTagManagerOfMatomoReplacesTheTracker(): void
    {
        $script = (new ConsentMode(matomoUrl: 'https://matomo.test/', matomoSiteId: '7', matomoMtmUrl: 'https://matomo.test/js/container_x.js'))->script();

        self::assertStringContainsString('_mtm.push(', $script);
        self::assertStringContainsString('container_x.js', $script);
        self::assertStringNotContainsString('matomo.php', $script);
    }

    public function testValuesCannotLeaveTheScript(): void
    {
        $script = (new ConsentMode('</script><script>alert(1)//', matomoUrl: "https://m.test/'+alert(1)+'", matomoSiteId: '1'))->script();

        self::assertStringNotContainsString('</script>', $script);
        self::assertStringNotContainsString("'+alert(1)+'", $script);
    }
}
