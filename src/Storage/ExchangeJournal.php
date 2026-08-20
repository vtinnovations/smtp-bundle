<?php

/*
 * SMTP Konfigurator
 *
 * Package: vtinnovations/smtp-bundle
 * Copyright: VT Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://github.com/vtinnovations/smtp-bundle
 */

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Storage;

use Vtinnovations\SmtpBundle\Config\DeploymentProfile;

/**
 * Remembers which pushes have already been dealt with, so a redelivery is answered rather than
 * applied twice — and so a replay dressed up as a redelivery is caught.
 *
 * Two different things are being defended here, which is why two different values are kept:
 *
 *   - the request id makes a genuine retry idempotent. The same id carrying the same authenticated
 *     content gets the same answer as the first time, no work done.
 *   - a digest of that content makes the id *binding*. The same id carrying different content is
 *     not a retry, it is someone reusing an id that was already accepted, and it is refused.
 *
 * Nonces are kept as digests only, never in the clear: a nonce is authentication material, and a
 * file full of them is worth stealing. Nothing about the record itself is stored here.
 */
final class ExchangeJournal
{
    /** Hard cap, in case pushes ever arrive far more often than expected. */
    private const MAX_ENTRIES = 500;

    private readonly string $path;

    public function __construct(string $projectDir)
    {
        $this->path = rtrim($projectDir, '/\\').'/var/vtinnovations-smtp/state/exchanges.json';
    }

    /**
     * @return array{fingerprint: string, version: int, result: string, at: int}|null
     */
    public function find(string $requestId): ?array
    {
        $entry = $this->load()['requests'][$requestId] ?? null;

        return \is_array($entry) ? $entry : null;
    }

    public function nonceSeen(string $nonce): bool
    {
        return isset($this->load()['nonces'][self::digest($nonce)]);
    }

    /**
     * @param string $fingerprint a digest of the authenticated body, so a reused id carrying
     *                            different content can be told apart from a genuine retry
     */
    public function record(string $requestId, string $nonce, string $fingerprint, int $version, string $result): void
    {
        $data = $this->load();

        $data['requests'][$requestId] = [
            'fingerprint' => $fingerprint,
            'version'     => $version,
            'result'      => $result,
            'at'          => time(),
        ];

        $data['nonces'][self::digest($nonce)] = time();

        $this->persist($this->prune($data));
    }

    public static function digest(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @param array{requests: array<string, array<string, mixed>>, nonces: array<string, int>} $data
     *
     * @return array{requests: array<string, array<string, mixed>>, nonces: array<string, int>}
     */
    private function prune(array $data): array
    {
        $cutoff = time() - DeploymentProfile::REPLAY_RETENTION_SECONDS;

        $data['requests'] = array_filter(
            $data['requests'],
            static fn (array $e): bool => (int) ($e['at'] ?? 0) >= $cutoff,
        );

        $data['nonces'] = array_filter($data['nonces'], static fn (int $at): bool => $at >= $cutoff);

        foreach (['requests', 'nonces'] as $bucket) {
            if (\count($data[$bucket]) > self::MAX_ENTRIES) {
                // Newest survive: those are the ones a retry could still reference.
                uasort($data[$bucket], static function ($a, $b): int {
                    $left  = \is_array($a) ? (int) ($a['at'] ?? 0) : (int) $a;
                    $right = \is_array($b) ? (int) ($b['at'] ?? 0) : (int) $b;

                    return $left <=> $right;
                });

                $data[$bucket] = \array_slice($data[$bucket], -self::MAX_ENTRIES, null, true);
            }
        }

        return $data;
    }

    /**
     * @return array{requests: array<string, array<string, mixed>>, nonces: array<string, int>}
     */
    private function load(): array
    {
        $empty = ['requests' => [], 'nonces' => []];

        if (!is_file($this->path)) {
            return $empty;
        }

        $json = @file_get_contents($this->path);

        if (false === $json || '' === $json) {
            return $empty;
        }

        $data = json_decode($json, true);

        if (!\is_array($data)) {
            return $empty;
        }

        return [
            'requests' => \is_array($data['requests'] ?? null) ? $data['requests'] : [],
            'nonces'   => \is_array($data['nonces'] ?? null) ? array_map('intval', $data['nonces']) : [],
        ];
    }

    /** @param array{requests: array<string, array<string, mixed>>, nonces: array<string, int>} $data */
    private function persist(array $data): void
    {
        $dir = \dirname($this->path);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES);

        if (false === $json) {
            return;
        }

        // Written atomically: a half-written journal reads back as empty, and every already-applied
        // push would look new again.
        $tmp = $this->path.'.tmp';

        if (false === @file_put_contents($tmp, $json, LOCK_EX)) {
            return;
        }

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
        }

        @chmod($this->path, 0640);
    }
}
