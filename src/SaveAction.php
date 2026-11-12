<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

use Kirby\Http\Response;
use Throwable;

/**
 * The route the frontend script posts a decision to: checks the payload and
 * writes the consent log.
 *
 * The route is open to every visitor, so it takes nothing on trust: the user
 * id has a fixed form, only components of the banner are kept, texts are cut
 * to the length of a title and the body has a size limit.
 */
final class SaveAction
{
    public const MAX_BODY_BYTES = 65536;
    private const MAX_TEXT_LENGTH = 255;

    public function __construct(private readonly ConsentBanner $consentBanner) {}

    public function __invoke(): Response
    {
        if ($this->consentBanner->banner() === null) {
            return self::json(['error' => 'No consent banner'], 404);
        }
        $body = $this->consentBanner->kirby->request()->body()->contents();
        $body = is_string($body) ? $body : '';
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return self::json(['error' => 'Payload too large'], 413);
        }
        $data = json_decode($body, true);
        $payload = is_array($data) ? $this->payload($data) : null;
        if ($payload === null) {
            return self::json(['error' => 'Invalid payload'], 400);
        }
        try {
            $this->consentBanner->log()->save($payload['hash'], $payload['version'], $payload['services']);
        } catch (Throwable) {
            return self::json(['error' => 'The consent could not be logged'], 500);
        }
        return self::json(['success' => true]);
    }

    /**
     * The checked payload, or null when it is not one of the banner.
     *
     * @param array<mixed> $data
     * @return array{hash: string, version: int, services: array<string, array{title: string, consent: bool, groupId: string, groupName: string}>}|null
     */
    public function payload(array $data): ?array
    {
        $hash = $data['hash'] ?? null;
        $services = $data['services'] ?? null;
        if (is_string($hash) === false || ConsentLog::isValidKey($hash) === false || is_array($services) === false) {
            return null;
        }
        $known = [];
        foreach ($this->consentBanner->componentRows() as $row) {
            $known[$row['id']] = $row;
        }
        $clean = [];
        foreach ($services as $id => $service) {
            $id = (string) $id;
            if (isset($known[$id]) === false || is_array($service) === false) {
                continue;
            }
            $clean[$id] = [
                'title' => self::text($service['title'] ?? ''),
                'consent' => ($service['consent'] ?? false) === true,
                // A consent given at a placeholder comes without its group
                'groupId' => self::text($service['groupId'] ?? '') ?: $known[$id]['groupId'],
                'groupName' => self::text($service['groupName'] ?? '') ?: $known[$id]['groupTitle'],
            ];
            if (count($clean) >= ConsentLog::MAX_SERVICES) {
                break;
            }
        }
        if ($clean === []) {
            return null;
        }
        $version = $data['version'] ?? 0;
        $version = is_numeric($version) ? (int) $version : 0;
        return [
            'hash' => $hash,
            // A visitor cannot have agreed to a version that does not exist yet
            'version' => max(0, min($version, $this->consentBanner->version())),
            'services' => $clean,
        ];
    }

    private static function text(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return mb_substr($value, 0, self::MAX_TEXT_LENGTH);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $code = 200): Response
    {
        return new Response(
            json_encode($data, JSON_THROW_ON_ERROR),
            'application/json',
            $code,
            ['Cache-Control' => 'no-store'],
        );
    }
}
