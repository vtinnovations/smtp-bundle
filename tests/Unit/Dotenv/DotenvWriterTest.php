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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Dotenv;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Dotenv\DotenvWriter;

final class DotenvWriterTest extends TestCase
{
    private string $tmpDir;
    private DotenvWriter $writer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/smtp_bundle_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->writer = new DotenvWriter($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/.env.local';
        if (file_exists($file)) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    public function testCreatesFileIfNotExists(): void
    {
        $this->writer->write('MAILER_DSN', 'smtp://user:pass@host:587');

        self::assertFileExists($this->tmpDir . '/.env.local');
    }

    public function testWritesKeyValue(): void
    {
        $this->writer->write('MAILER_DSN', 'smtp://user:pass@host:587');

        $content = file_get_contents($this->tmpDir . '/.env.local');
        self::assertStringContainsString('MAILER_DSN=smtp://user:pass@host:587', $content);
    }

    public function testUpdatesExistingKey(): void
    {
        file_put_contents($this->tmpDir . '/.env.local', "APP_ENV=prod\nMAILER_DSN=smtp://old:old@host:25\n");

        $this->writer->write('MAILER_DSN', 'smtp://new:new@host:587');

        $content = file_get_contents($this->tmpDir . '/.env.local');
        self::assertStringContainsString('MAILER_DSN=smtp://new:new@host:587', $content);
        self::assertStringNotContainsString('smtp://old', $content);
        self::assertStringContainsString('APP_ENV=prod', $content);
    }

    public function testPreservesOtherKeys(): void
    {
        file_put_contents($this->tmpDir . '/.env.local', "APP_ENV=prod\nDATABASE_URL=mysql://localhost\n");

        $this->writer->write('MAILER_DSN', 'smtp://user:pass@host:587');

        $content = file_get_contents($this->tmpDir . '/.env.local');
        self::assertStringContainsString('APP_ENV=prod', $content);
        self::assertStringContainsString('DATABASE_URL=mysql://localhost', $content);
    }

    public function testRemovesKey(): void
    {
        file_put_contents($this->tmpDir . '/.env.local', "APP_ENV=prod\nMAILER_DSN=smtp://user:pass@host:587\n");

        $this->writer->remove('MAILER_DSN');

        $content = file_get_contents($this->tmpDir . '/.env.local');
        self::assertStringNotContainsString('MAILER_DSN', $content);
        self::assertStringContainsString('APP_ENV=prod', $content);
    }

    public function testRemoveNoopsIfFileAbsent(): void
    {
        // No exception expected
        $this->writer->remove('MAILER_DSN');
        self::assertFileDoesNotExist($this->tmpDir . '/.env.local');
    }

    public function testReadsExistingKey(): void
    {
        file_put_contents($this->tmpDir . '/.env.local', "MAILER_DSN=smtp://user:pass@host:587\n");

        self::assertSame('smtp://user:pass@host:587', $this->writer->read('MAILER_DSN'));
    }

    public function testReadsReturnsNullIfAbsent(): void
    {
        self::assertNull($this->writer->read('MAILER_DSN'));
    }

    public function testValueWithSpacesGetsQuoted(): void
    {
        $this->writer->write('APP_NAME', 'My App Name');

        $content = file_get_contents($this->tmpDir . '/.env.local');
        self::assertStringContainsString('APP_NAME="My App Name"', $content);
    }

    public function testInvalidKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->writer->write('invalid-key', 'value');
    }
}
