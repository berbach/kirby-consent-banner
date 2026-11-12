<?php

declare(strict_types=1);

namespace Bb\ConsentBanner\Tests;

use Bb\ConsentBanner\ServiceDatabase;
use PHPUnit\Framework\TestCase;

final class ServiceDatabaseTest extends TestCase
{
    public function testFindsAServiceByNameIdentifierAndAlias(): void
    {
        $database = ServiceDatabase::default();

        self::assertSame('YouTube', $database->search('youtu')[0]['service_name']);
        self::assertSame('YouTube', $database->search('YT')[0]['service_name']);
        self::assertContains('Google Analytics (GA4)', array_column($database->search('analytics'), 'service_name'));
    }

    public function testAnEmptyQueryFindsNothing(): void
    {
        self::assertSame([], ServiceDatabase::default()->search('   '));
        self::assertSame([], ServiceDatabase::default()->search('no such service at all'));
    }

    public function testTheLimitHolds(): void
    {
        self::assertCount(2, ServiceDatabase::default()->search('o', 2));
    }

    public function testEveryServiceHasTheFieldsThePanelFills(): void
    {
        $services = ServiceDatabase::default()->services();
        self::assertNotEmpty($services);
        foreach ($services as $service) {
            $name = $service['service_name'] ?? '';
            self::assertNotSame('', $name);
            self::assertIsArray($service['cookies'] ?? null, $name);
            foreach (['service_provider', 'service_publisher', 'service_privacy_link', 'service_data_collected'] as $field) {
                self::assertArrayHasKey($field, $service, $name);
            }
            foreach ($service['cookies'] as $cookie) {
                self::assertNotSame('', $cookie['name'] ?? '', $name);
            }
        }
    }

    public function testAMissingOrBrokenFileIsAnEmptyDatabase(): void
    {
        self::assertSame([], (new ServiceDatabase('/does/not/exist.json'))->search('youtube'));

        $file = tempnam(sys_get_temp_dir(), 'cb');
        file_put_contents($file, '{broken');
        try {
            self::assertSame([], (new ServiceDatabase($file))->search('youtube'));
        } finally {
            unlink($file);
        }
    }
}
