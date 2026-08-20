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

namespace Vtinnovations\SmtpBundle\Config;

/**
 * Bookkeeping around the sealed record: the key the admin entered, the host it was confirmed for,
 * when that last happened, and whether the remote side has since refused it.
 *
 * Deliberately not an authority on anything. It lives in a file the site owner can write, so it
 * only ever decides *when to ask again* — never whether this installation is entitled. That answer
 * comes from the signed record alone.
 *
 * The one thing it does gate is revocation: an explicit refusal is recorded here rather than being
 * allowed to delete the sealed record, because a refusal arrives as ordinary JSON with no signature
 * on it. Letting an unauthenticated answer destroy authenticated state would be the wrong trade,
 * and keeping the record means a later successful refresh can simply clear the mark.
 */
final class RuntimeState
{
    private readonly string $path;

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(string $projectDir)
    {
        $this->path = rtrim($projectDir, '/\\').'/var/vtinnovations-smtp/runtime.json';
        $this->data = $this->load();
    }

    public function key(): string
    {
        return trim((string) ($this->data['key'] ?? ''));
    }

    /** The host the last successful exchange was made for. */
    public function matchedHost(): string
    {
        return (string) ($this->data['matched_host'] ?? '');
    }

    public function confirmedAt(): int
    {
        return (int) ($this->data['confirmed_at'] ?? 0);
    }

    /** True once there is a confirmation on file and it is older than $maxAge seconds. */
    public function isStale(int $maxAge): bool
    {
        $confirmedAt = $this->confirmedAt();

        return 0 !== $confirmedAt && (time() - $confirmedAt) > $maxAge;
    }

    /** Set when the remote side explicitly refused this key, cleared by the next success. */
    public function isRefused(): bool
    {
        return true === ($this->data['refused'] ?? false);
    }

    public function rememberKey(string $key): void
    {
        $this->merge(['key' => trim($key)]);
    }

    public function rememberSuccess(string $host): void
    {
        $this->merge([
            'matched_host' => $host,
            'confirmed_at' => time(),
            'refused'      => false,
        ]);
    }

    public function rememberRefusal(): void
    {
        $this->merge([
            'confirmed_at' => 0,
            'refused'      => true,
        ]);
    }

    /** Wipes this bookkeeping entirely. Used when an operator deliberately removes the licence. */
    public function clear(): void
    {
        $this->data = [];

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /** @param array<string, mixed> $fields */
    private function merge(array $fields): void
    {
        $this->data = array_merge($this->data, $fields);

        $dir = \dirname($this->path);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $tmp = $this->path.'.tmp';

        if (false === @file_put_contents($tmp, json_encode($this->data, JSON_UNESCAPED_SLASHES), LOCK_EX)) {
            return;
        }

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
        }
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $json = @file_get_contents($this->path);

        if (false === $json || '' === $json) {
            return [];
        }

        $data = json_decode($json, true);

        return \is_array($data) ? $data : [];
    }
}
