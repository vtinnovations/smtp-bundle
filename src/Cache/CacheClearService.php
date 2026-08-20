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

namespace Vtinnovations\SmtpBundle\Cache;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Vtinnovations\SmtpBundle\Exception\CacheClearException;
use Vtinnovations\SmtpBundle\Exception\NotEntitledException;
use Vtinnovations\SmtpBundle\Service\EntitlementReader;

final class CacheClearService
{
    private string $maintenancePath;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $phpBinary,
        private readonly int $processTimeout,
        private readonly EntitlementReader $entitlement,
    ) {
        $this->maintenancePath = rtrim($projectDir, '/\\') . '/var/maintenance.html';
    }

    public function clearAndWarmup(): void
    {
        // Puts the site into maintenance and spawns a console process. Not something an unentitled
        // caller gets to trigger, however it reached here.
        if (!$this->entitlement->isGranted()) {
            throw new NotEntitledException('smtp.cache_clear', $this->entitlement->current()->reason);
        }

        $this->enableMaintenance();

        try {
            $binary = $this->resolveBinary();

            $this->run([$binary, 'bin/console', 'cache:clear', '--no-warmup', '--env=prod', '--no-interaction']);
            $this->run([$binary, 'bin/console', 'cache:warmup', '--env=prod', '--no-interaction']);
        } catch (ProcessFailedException $e) {
            throw new CacheClearException('Cache clear failed: ' . $e->getMessage(), 0, $e);
        } finally {
            // Always remove maintenance page — even on failure
            $this->disableMaintenance();
        }
    }

    private function enableMaintenance(): void
    {
        $varDir = \dirname($this->maintenancePath);

        if (!is_dir($varDir)) {
            mkdir($varDir, 0755, true);
        }

        file_put_contents($this->maintenancePath, $this->maintenanceHtml());
    }

    private function disableMaintenance(): void
    {
        if (file_exists($this->maintenancePath)) {
            unlink($this->maintenancePath);
        }
    }

    private function run(array $command): void
    {
        $process = new Process($command, $this->projectDir, null, null, $this->processTimeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function resolveBinary(): string
    {
        if ($this->phpBinary !== '') {
            return $this->phpBinary;
        }

        $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        // --- Candidates checked with is_file() first ---

        $raw = [];

        if (PHP_BINARY !== '') {
            $raw[] = PHP_BINARY;
        }

        if (is_readable('/proc/self/exe')) {
            $exe = readlink('/proc/self/exe');
            if ($exe !== false && $exe !== '') {
                $raw[] = $exe;
            }
        }

        foreach ($raw as $candidate) {
            $candidate = str_replace('php-fpm', 'php', $candidate);
            $candidate = str_replace('/sbin/', '/bin/', $candidate);
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        // PhpExecutableFinder (searches $PATH)
        $found = (new PhpExecutableFinder())->find(false);
        if ($found !== false && $found !== '') {
            return $found;
        }

        foreach (['/usr/bin/php' . $ver, '/usr/bin/php', '/usr/local/bin/php'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        // --- Trusted guesses: web user may lack stat perms, but binary is still executable ---

        // Plesk: /opt/plesk/php/X.Y/bin/php — derived from running PHP version, no stat check needed
        $pleskPath = '/opt/plesk/php/' . $ver . '/bin/php';

        // cPanel/EasyApache
        $cpanelPath = '/opt/cpanel/ea-php' . str_replace('.', '', $ver) . '/root/usr/bin/php';

        foreach ([$pleskPath, $cpanelPath] as $trusted) {
            // Verify by running `php --version` — cheapest real check
            $test = new Process([$trusted, '--version'], null, null, null, 5);
            $test->run();
            if ($test->isSuccessful()) {
                return $trusted;
            }
        }

        throw new CacheClearException(
            'PHP CLI binary not found automatically. Set vtinnovations_smtp.php_binary '
            . 'in config/config.yaml: php_binary: \'/path/to/php\''
        );
    }

    private function maintenanceHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="10">
<title>Wartung</title>
<style>
body { font-family: sans-serif; text-align: center; padding: 80px 20px; color: #444; background: #f9f9f9; }
h1 { font-size: 1.8em; margin-bottom: 0.5em; }
p { color: #777; }
</style>
</head>
<body>
<h1>Kurze Wartungspause</h1>
<p>Die Seite wird in Kürze wieder verfügbar sein.</p>
</body>
</html>
HTML;
    }
}
