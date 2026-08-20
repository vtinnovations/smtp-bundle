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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Cache\CacheClearService;
use Vtinnovations\SmtpBundle\Exception\CacheClearException;
use Vtinnovations\SmtpBundle\Exception\NotEntitledException;
use Vtinnovations\SmtpBundle\Tests\Fixture\Installation;

final class CacheClearServiceTest extends TestCase
{
    private string $tmpDir;
    private Installation $install;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/smtp_cache_test_' . uniqid('', true);
        mkdir($this->tmpDir . '/var', 0755, true);

        // Clearing the cache is a protected operation, so the service needs an entitled installation
        // before it will do anything at all.
        $this->install = new Installation();
        $this->install->install();
    }

    protected function tearDown(): void
    {
        $maintenance = $this->tmpDir . '/var/maintenance.html';
        if (file_exists($maintenance)) {
            unlink($maintenance);
        }

        if (is_dir($this->tmpDir . '/var')) {
            rmdir($this->tmpDir . '/var');
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        $this->install->cleanUp();
    }

    public function testAnUnentitledInstallationCannotClearTheCache(): void
    {
        $this->install->store->discard();
        $this->install->reader->reset();

        $this->expectException(NotEntitledException::class);

        (new CacheClearService($this->tmpDir, 'true', 10, $this->install->reader))->clearAndWarmup();
    }

    public function testMaintenanceFileRemovedAfterSuccess(): void
    {
        // Use 'true' as the binary — it exits 0 on Unix, simulating success
        $service = new CacheClearService($this->tmpDir, 'true', 10, $this->install->reader);
        $service->clearAndWarmup();

        self::assertFileDoesNotExist($this->tmpDir . '/var/maintenance.html');
    }

    public function testMaintenanceFileRemovedEvenOnFailure(): void
    {
        // 'false' exits 1 on Unix — simulates failed subprocess
        $service = new CacheClearService($this->tmpDir, 'false', 10, $this->install->reader);

        try {
            $service->clearAndWarmup();
        } catch (CacheClearException) {
            // expected
        }

        self::assertFileDoesNotExist($this->tmpDir . '/var/maintenance.html');
    }

    public function testThrowsCacheClearExceptionOnProcessFailure(): void
    {
        $this->expectException(CacheClearException::class);

        $service = new CacheClearService($this->tmpDir, 'false', 10, $this->install->reader);
        $service->clearAndWarmup();
    }

    public function testMaintenanceFileIsWrittenBeforeProcess(): void
    {
        // Capture whether maintenance.html existed during the process
        // by using a script that checks for the file
        $tmpScript = $this->tmpDir . '/check.sh';
        $flagFile = $this->tmpDir . '/maintenance_existed';

        file_put_contents($tmpScript, <<<BASH
            #!/bin/sh
            if [ -f "{$this->tmpDir}/var/maintenance.html" ]; then
                touch {$flagFile}
            fi
            exit 0
            BASH
        );
        chmod($tmpScript, 0755);

        $service = new CacheClearService($this->tmpDir, $tmpScript, 10, $this->install->reader);
        $service->clearAndWarmup();

        self::assertFileExists($flagFile);

        unlink($flagFile);
        unlink($tmpScript);
    }
}
