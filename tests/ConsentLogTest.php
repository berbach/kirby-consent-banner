<?php

declare(strict_types=1);

namespace Berbach\ConsentBanner\Tests;

use Berbach\ConsentBanner\ConsentLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsentLogTest extends TestCase
{
    private const KEY = '0123abcd-01234-01234-01234-012345678';

    private string $directory;
    private ConsentLog $log;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/consent-log-' . bin2hex(random_bytes(6));
        // The folder does not exist yet: the log creates it
        $this->log = new ConsentLog($this->directory . '/nested/consent-log.sqlite');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/nested/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory . '/nested');
        @rmdir($this->directory);
    }

    public function testNothingIsCreatedBeforeTheFirstEntry(): void
    {
        self::assertFalse($this->log->exists());
    }

    public function testSavesAndFindsAnEntry(): void
    {
        $services = ['abc' => ['title' => 'YouTube ä', 'consent' => true, 'groupId' => 'g', 'groupName' => 'Medien']];
        $this->log->save(self::KEY, 3, $services, 1000);

        self::assertTrue($this->log->exists());
        self::assertSame(1, $this->log->count());
        $row = $this->log->findByKey(self::KEY);
        self::assertNotNull($row);
        self::assertSame(3, $row['banner_version']);
        self::assertSame($services, $row['consent_services']);
        self::assertSame(1000, $row['crdate']);
        self::assertSame(1000, $row['tstamp']);
    }

    public function testANewDecisionReplacesTheEntryAndKeepsTheFirstDate(): void
    {
        $this->log->save(self::KEY, 1, ['abc' => ['title' => 'A', 'consent' => true, 'groupId' => '', 'groupName' => '']], 1000);
        $this->log->save(self::KEY, 2, ['abc' => ['title' => 'A', 'consent' => false, 'groupId' => '', 'groupName' => '']], 2000);

        self::assertSame(1, $this->log->count());
        $row = $this->log->findByKey(self::KEY);
        self::assertNotNull($row);
        self::assertSame(2, $row['banner_version']);
        self::assertFalse($row['consent_services']['abc']['consent']);
        self::assertSame(1000, $row['crdate']);
        self::assertSame(2000, $row['tstamp']);
    }

    public function testSearchTakesTheCompleteKeyOnly(): void
    {
        $this->log->save(self::KEY, 1, [], 1000);
        $this->log->save('ffffffff-01234-01234-01234-012345678', 1, [], 2000);

        self::assertCount(1, $this->log->find(self::KEY));
        self::assertSame(1, $this->log->count(self::KEY));
        self::assertCount(0, $this->log->find('0123abcd'));
        self::assertCount(0, $this->log->find("' OR 1=1 --"));
    }

    public function testNewestEntriesComeFirstAndPagesDoNotOverlap(): void
    {
        foreach ([1000, 3000, 2000] as $index => $time) {
            $this->log->save('aaaaaaa' . $index . '-01234-01234-01234-012345678', 1, [], $time);
        }
        $first = $this->log->find('', 2, 0);
        $second = $this->log->find('', 2, 2);

        self::assertSame([3000, 2000], array_column($first, 'crdate'));
        self::assertSame([1000], array_column($second, 'crdate'));
    }

    public function testDeletesEntriesOlderThanTheLifetime(): void
    {
        $now = 400 * 86400;
        $this->log->save('aaaaaaaa-01234-01234-01234-012345678', 1, [], $now - 366 * 86400);
        $this->log->save('bbbbbbbb-01234-01234-01234-012345678', 1, [], $now - 364 * 86400);

        self::assertSame(1, $this->log->deleteExpired(365, $now));
        self::assertSame(1, $this->log->count());
        self::assertNotNull($this->log->findByKey('bbbbbbbb-01234-01234-01234-012345678'));
    }

    public function testALifetimeOfZeroDeletesNothing(): void
    {
        $this->log->save(self::KEY, 1, [], 1);
        self::assertSame(0, $this->log->deleteExpired(0));
        self::assertSame(1, $this->log->count());
    }

    public function testRejectsAKeyThatIsNoUserId(): void
    {
        self::assertFalse(ConsentLog::isValidKey('<script>alert(1)</script>'));
        self::assertFalse(ConsentLog::isValidKey('short'));
        self::assertFalse(ConsentLog::isValidKey(str_repeat('a', 65)));
        self::assertTrue(ConsentLog::isValidKey(self::KEY));

        $this->expectException(RuntimeException::class);
        $this->log->save('not valid!', 1, []);
    }
}
