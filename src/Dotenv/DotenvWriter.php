<?php

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Dotenv;

use Vtinnovations\SmtpBundle\Exception\DotenvWriteException;

final class DotenvWriter
{
    private string $path;

    public function __construct(string $projectDir)
    {
        $this->path = rtrim($projectDir, '/\\') . '/.env.local';
    }

    /**
     * Write or update a key in .env.local.
     * Creates the file if it does not exist.
     */
    public function write(string $key, string $value): void
    {
        $this->assertValidKey($key);

        $content = file_exists($this->path) ? (string) file_get_contents($this->path) : '';
        $line = $key . '=' . $this->quoteValue($value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $content)) {
            $content = (string) preg_replace($pattern, $line, $content);
        } else {
            $content = rtrim($content) . "\n" . $line . "\n";
        }

        if (false === file_put_contents($this->path, $content)) {
            throw new DotenvWriteException(\sprintf('Cannot write to "%s".', $this->path));
        }
    }

    /**
     * Remove a key from .env.local. No-op if file or key does not exist.
     */
    public function remove(string $key): void
    {
        $this->assertValidKey($key);

        if (!file_exists($this->path)) {
            return;
        }

        $content = (string) file_get_contents($this->path);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$\n?/m';
        $content = (string) preg_replace($pattern, '', $content);

        if (false === file_put_contents($this->path, $content)) {
            throw new DotenvWriteException(\sprintf('Cannot write to "%s".', $this->path));
        }
    }

    public function read(string $key): ?string
    {
        if (!file_exists($this->path)) {
            return null;
        }

        $content = (string) file_get_contents($this->path);
        $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';

        if (!preg_match($pattern, $content, $matches)) {
            return null;
        }

        return trim($matches[1], '"\'');
    }

    private function quoteValue(string $value): string
    {
        // Always quote — DSN strings contain :, @, ? which are safe unquoted
        // but quoting prevents edge cases with special shell chars
        if (preg_match('/[\s"\'#\\\\]/', $value) || $value === '') {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    private function assertValidKey(string $key): void
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid .env key "%s". Keys must be uppercase letters, digits, underscores.',
                $key,
            ));
        }
    }
}
