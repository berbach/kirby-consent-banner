<?php

declare(strict_types=1);

namespace Berbach\ConsentBanner;

/**
 * The service database that comes with the plugin (data/cookie_database.json):
 * known services with their publisher, domains, privacy policy and cookies.
 * The panel field "consentservice" searches it and fills the cookie
 * information of a component from the chosen service.
 */
final class ServiceDatabase
{
    public const MAX_RESULTS = 20;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $services = null;

    public function __construct(private readonly string $file) {}

    public static function default(): self
    {
        return new self(dirname(__DIR__) . '/data/cookie_database.json');
    }

    /**
     * Services whose name, identifier or one of the aliases contains the
     * query, as their complete block: the panel fills all fields from it
     * without asking again.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = self::MAX_RESULTS): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return [];
        }
        $results = [];
        foreach ($this->services() as $service) {
            if (self::matches($service, $needle)) {
                $results[] = $service;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }
        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function services(): array
    {
        if ($this->services !== null) {
            return $this->services;
        }
        $contents = is_file($this->file) ? file_get_contents($this->file) : false;
        if ($contents === false) {
            return $this->services = [];
        }
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->services = [];
        }
        $services = is_array($data) ? ($data['services'] ?? []) : [];
        return $this->services = is_array($services) ? array_values(array_filter($services, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function matches(array $service, string $needle): bool
    {
        $haystack = [
            (string) ($service['service_name'] ?? ''),
            (string) ($service['service_identifier'] ?? ''),
        ];
        foreach ((array) ($service['service_aliases'] ?? []) as $alias) {
            if (is_scalar($alias)) {
                $haystack[] = (string) $alias;
            }
        }
        foreach ($haystack as $value) {
            if ($value !== '' && str_contains(mb_strtolower($value), $needle)) {
                return true;
            }
        }
        return false;
    }
}
