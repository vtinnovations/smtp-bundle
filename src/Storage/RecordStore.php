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

/**
 * Keeps the sealed record and the envelope that vouches for it, and swaps them as one unit.
 *
 * The pair is the invariant. An envelope that describes different bytes than the ones next to it is
 * worse than having neither: it either refuses a genuine record or, if the two were ever allowed to
 * be assembled from different deliveries, accepts a substituted one. So both files are written to a
 * temporary pair, re-read, backed up, renamed under an exclusive lock, re-read again, and rolled
 * back together if the state that lands is not the state that was verified.
 *
 * Everything lives under `var/`, outside the document root, and no path ever comes from a request.
 */
final class RecordStore
{
    private readonly string $dir;

    public function __construct(string $projectDir)
    {
        $this->dir = rtrim($projectDir, '/\\').'/var/vtinnovations-smtp/state';
    }

    /**
     * The stored pair, or null when nothing is stored or the two do not both exist.
     *
     * @return array{bytes: string, envelope: array<string, mixed>}|null
     */
    public function read(): ?array
    {
        $bytes = $this->readFile($this->recordPath());

        if (null === $bytes) {
            return null;
        }

        $envelopeJson = $this->readFile($this->envelopePath());

        if (null === $envelopeJson) {
            return null;
        }

        $envelope = json_decode($envelopeJson, true);

        if (!\is_array($envelope)) {
            return null;
        }

        return ['bytes' => $bytes, 'envelope' => $envelope];
    }

    /**
     * Installs a new pair, or leaves the old one exactly as it was.
     *
     * @param array<string, mixed> $envelope
     * @param \Closure(string, array<string, mixed>): bool $verify re-run against what actually
     *                                                            landed on disk; a false here
     *                                                            triggers the rollback
     */
    public function commit(string $bytes, array $envelope, \Closure $verify): bool
    {
        if (!$this->ensureDir()) {
            return false;
        }

        $lock = @fopen($this->dir.'/.lock', 'c');

        if (false === $lock) {
            return false;
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return false;
            }

            return $this->swap($bytes, $envelope, $verify);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** Removes the stored pair, under the same lock a commit would take. */
    public function discard(): void
    {
        if (!$this->ensureDir()) {
            return;
        }

        $lock = @fopen($this->dir.'/.lock', 'c');

        if (false === $lock) {
            return;
        }

        try {
            if (flock($lock, LOCK_EX)) {
                $this->remove($this->recordPath(), $this->envelopePath());
                $this->clearBackup();
            }
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param \Closure(string, array<string, mixed>): bool $verify
     */
    private function swap(string $bytes, array $envelope, \Closure $verify): bool
    {
        $envelopeJson = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $envelopeJson) {
            return false;
        }

        $recordTmp   = $this->recordPath().'.new';
        $envelopeTmp = $this->envelopePath().'.new';

        if (!$this->writeAtomicCandidate($recordTmp, $bytes) || !$this->writeAtomicCandidate($envelopeTmp, $envelopeJson)) {
            $this->remove($recordTmp, $envelopeTmp);

            return false;
        }

        // Read the candidate back before it is allowed anywhere near the live pair: a short write or
        // a full disk shows up here rather than after the swap.
        if ($this->readFile($recordTmp) !== $bytes || $this->readFile($envelopeTmp) !== $envelopeJson) {
            $this->remove($recordTmp, $envelopeTmp);

            return false;
        }

        $previous = $this->read();

        if (!$this->backup($previous)) {
            $this->remove($recordTmp, $envelopeTmp);

            return false;
        }

        if (!@rename($recordTmp, $this->recordPath())) {
            $this->remove($recordTmp, $envelopeTmp);

            return false;
        }

        if (!@rename($envelopeTmp, $this->envelopePath())) {
            // Half-swapped: the record moved, the envelope did not. Put the pair back before
            // anything reads a record its envelope does not describe.
            $this->rollback($previous);
            $this->remove($recordTmp, $envelopeTmp);

            return false;
        }

        $landed = $this->read();

        if (null === $landed || !$verify($landed['bytes'], $landed['envelope'])) {
            $this->rollback($previous);

            return false;
        }

        $this->clearBackup();

        return true;
    }

    private function writeAtomicCandidate(string $path, string $contents): bool
    {
        $handle = @fopen($path, 'wb');

        if (false === $handle) {
            return false;
        }

        try {
            if (\strlen($contents) !== @fwrite($handle, $contents)) {
                return false;
            }

            // Ask for the bytes to reach the device, so a crash between here and the rename cannot
            // leave a file that exists but is empty.
            @fflush($handle);

            if (\function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            @fclose($handle);
        }

        @chmod($path, 0640);

        return true;
    }

    /** @param array{bytes: string, envelope: array<string, mixed>}|null $previous */
    private function backup(?array $previous): bool
    {
        $this->clearBackup();

        if (null === $previous) {
            return true;
        }

        return false !== @copy($this->recordPath(), $this->recordPath().'.bak')
            && false !== @copy($this->envelopePath(), $this->envelopePath().'.bak');
    }

    /**
     * @param array{bytes: string, envelope: array<string, mixed>}|null $previous
     *
     * Called from inside {@see swap()} while {@see commit()} already holds the exclusive lock, so
     * this removes the pair directly rather than through {@see discard()} — which takes that same
     * lock itself and would deadlock waiting for a hold this call is nested inside of.
     */
    private function rollback(?array $previous): void
    {
        if (null === $previous) {
            $this->remove($this->recordPath(), $this->envelopePath());
            $this->clearBackup();

            return;
        }

        @rename($this->recordPath().'.bak', $this->recordPath());
        @rename($this->envelopePath().'.bak', $this->envelopePath());
        $this->clearBackup();
    }

    private function clearBackup(): void
    {
        $this->remove($this->recordPath().'.bak', $this->envelopePath().'.bak');
    }

    private function remove(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function readFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        return false === $raw || '' === $raw ? null : $raw;
    }

    private function ensureDir(): bool
    {
        return is_dir($this->dir) || (@mkdir($this->dir, 0750, true) || is_dir($this->dir));
    }

    private function recordPath(): string
    {
        return $this->dir.'/record.json';
    }

    private function envelopePath(): string
    {
        return $this->dir.'/record.seal.json';
    }
}
