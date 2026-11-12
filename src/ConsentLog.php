<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

use PDO;
use RuntimeException;

/**
 * The consent log: one row per visitor (browser fingerprint) in a SQLite
 * file. A new decision of the same visitor replaces the row and keeps the
 * date of the first one.
 */
final class ConsentLog
{
    public const TABLE = 'consent_log';
    public const MAX_SERVICES = 200;

    private ?PDO $connection = null;

    public function __construct(private readonly string $file) {}

    public function file(): string
    {
        return $this->file;
    }

    /**
     * The user id the frontend shows: letters, digits and hyphens.
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $key) === 1;
    }

    /**
     * @param array<string, array{title: string, consent: bool, groupId: string, groupName: string}> $services
     */
    public function save(string $identificationKey, int $version, array $services, ?int $time = null): void
    {
        if (self::isValidKey($identificationKey) === false) {
            throw new RuntimeException('Invalid identification key');
        }
        $time ??= time();
        $statement = $this->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (identification_key, banner_version, consent_services, tstamp, crdate)
             VALUES (:key, :version, :services, :time, :time)
             ON CONFLICT(identification_key) DO UPDATE SET
                banner_version = excluded.banner_version,
                consent_services = excluded.consent_services,
                tstamp = excluded.tstamp',
        );
        $statement->execute([
            'key' => $identificationKey,
            'version' => $version,
            'services' => json_encode($services, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'time' => $time,
        ]);
    }

    /**
     * @return array{identification_key: string, banner_version: int, consent_services: array<string, mixed>, tstamp: int, crdate: int}|null
     */
    public function findByKey(string $identificationKey): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE identification_key = :key');
        $statement->execute(['key' => $identificationKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /**
     * The entries, newest first. The search takes the complete user id.
     *
     * @return array<int, array{identification_key: string, banner_version: int, consent_services: array<string, mixed>, tstamp: int, crdate: int}>
     */
    public function find(string $search = '', int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE;
        $parameters = [];
        if ($search !== '') {
            $sql .= ' WHERE identification_key = :key';
            $parameters['key'] = $search;
        }
        $sql .= ' ORDER BY crdate DESC, identification_key ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $statement = $this->connection()->prepare($sql);
        $statement->execute($parameters);
        return array_map([self::class, 'row'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function count(string $search = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . self::TABLE;
        $parameters = [];
        if ($search !== '') {
            $sql .= ' WHERE identification_key = :key';
            $parameters['key'] = $search;
        }
        $statement = $this->connection()->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /**
     * Deletes the entries that were not renewed within the given days and
     * answers how many went.
     */
    public function deleteExpired(int $lifetimeDays, ?int $time = null): int
    {
        if ($lifetimeDays <= 0) {
            return 0;
        }
        $threshold = ($time ?? time()) - $lifetimeDays * 86400;
        $statement = $this->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE tstamp < :threshold');
        $statement->execute(['threshold' => $threshold]);
        return $statement->rowCount();
    }

    /**
     * Whether the log has a file yet; reading does not have to create one.
     */
    public function exists(): bool
    {
        return is_file($this->file);
    }

    private function connection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        if (extension_loaded('pdo_sqlite') === false) {
            throw new RuntimeException('The consent log needs the PHP extension pdo_sqlite');
        }
        $directory = dirname($this->file);
        if (is_dir($directory) === false && @mkdir($directory, 0775, true) === false && is_dir($directory) === false) {
            throw new RuntimeException('The folder of the consent log cannot be created: ' . $directory);
        }
        $connection = new PDO('sqlite:' . $this->file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Visitors write at the same time: wait for a lock instead of failing
        $connection->exec('PRAGMA busy_timeout = 5000');
        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                identification_key TEXT NOT NULL PRIMARY KEY,
                banner_version INTEGER NOT NULL DEFAULT 0,
                consent_services TEXT NOT NULL DEFAULT \'{}\',
                tstamp INTEGER NOT NULL DEFAULT 0,
                crdate INTEGER NOT NULL DEFAULT 0
            )',
        );
        $connection->exec('CREATE INDEX IF NOT EXISTS consent_log_crdate ON ' . self::TABLE . ' (crdate)');
        $connection->exec('CREATE INDEX IF NOT EXISTS consent_log_tstamp ON ' . self::TABLE . ' (tstamp)');
        return $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{identification_key: string, banner_version: int, consent_services: array<string, mixed>, tstamp: int, crdate: int}
     */
    private static function row(array $row): array
    {
        $services = json_decode((string) ($row['consent_services'] ?? ''), true);
        return [
            'identification_key' => (string) $row['identification_key'],
            'banner_version' => (int) $row['banner_version'],
            'consent_services' => is_array($services) ? $services : [],
            'tstamp' => (int) $row['tstamp'],
            'crdate' => (int) $row['crdate'],
        ];
    }
}
