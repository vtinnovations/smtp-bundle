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

namespace Vtinnovations\SmtpBundle\Tests\Fixture;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\SmtpBundle\Config\HostInventory;
use Vtinnovations\SmtpBundle\Config\RuntimeState;
use Vtinnovations\SmtpBundle\Service\EntitlementReader;
use Vtinnovations\SmtpBundle\Service\RecordInspector;
use Vtinnovations\SmtpBundle\Storage\ExchangeJournal;
use Vtinnovations\SmtpBundle\Storage\RecordStore;
use Vtinnovations\SmtpBundle\Support\CanonicalForm;
use Vtinnovations\SmtpBundle\Support\DetachedSignature;
use Vtinnovations\SmtpBundle\Support\PackageOpener;
use Vtinnovations\SmtpBundle\Support\TrustedKeys;

/**
 * A whole installation on a temporary directory: real files, real signatures, real verification.
 *
 * Assembled by hand rather than mocked, because most of what is worth testing here is how the parts
 * refuse each other, and a mock would happily agree to anything.
 */
final class Installation
{
    public readonly string $projectDir;
    public readonly RecordFactory $factory;
    public readonly RequestStack $requestStack;
    public readonly HostInventory $hosts;
    public readonly TrustedKeys $keys;
    public readonly CanonicalForm $canonicalForm;
    public readonly DetachedSignature $signature;
    public readonly PackageOpener $opener;
    public readonly RecordInspector $inspector;
    public readonly RecordStore $store;
    public readonly RuntimeState $runtimeState;
    public readonly ExchangeJournal $journal;
    public readonly EntitlementReader $reader;

    /**
     * @param list<string> $configuredHosts
     */
    public function __construct(
        array $configuredHosts = ['example.com'],
        ?string $currentHost = null,
        ?RecordFactory $factory = null,
        ?TrustedKeys $keys = null,
    ) {
        $this->projectDir = sys_get_temp_dir().'/vt-smtp-install-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0775, true);

        $this->factory      = $factory ?? new RecordFactory();
        $this->requestStack = new RequestStack();

        if (null !== $currentHost) {
            $this->requestStack->push(Request::create('https://'.$currentHost.'/'));
        }

        $this->hosts         = new HostInventory($this->requestStack, null, $configuredHosts, '');
        $this->keys          = $keys ?? $this->factory->keys();
        $this->canonicalForm = new CanonicalForm();
        $this->signature     = new DetachedSignature();
        $this->opener        = new PackageOpener($this->canonicalForm, $this->keys, $this->signature);
        $this->inspector     = new RecordInspector($this->canonicalForm, $this->keys, $this->signature, $this->hosts);
        $this->store         = new RecordStore($this->projectDir);
        $this->runtimeState  = new RuntimeState($this->projectDir);
        $this->journal       = new ExchangeJournal($this->projectDir);
        $this->reader        = new EntitlementReader($this->store, $this->opener, $this->inspector, $this->runtimeState);
    }

    /**
     * Installs a genuine package, bypassing the exchange, so a test can start from "already
     * licensed" without going through the network path.
     *
     * @param array<string, mixed> $documentOverrides
     *
     * @return array{bytes: string, payload_b64: string, envelope: array<string, mixed>}
     */
    public function install(array $documentOverrides = []): array
    {
        $package = $this->factory->package($documentOverrides);

        $this->store->commit($package['bytes'], $package['envelope'], static fn (): bool => true);
        $this->runtimeState->rememberKey((string) (json_decode($package['bytes'], true)['license_key'] ?? ''));
        $this->runtimeState->rememberSuccess('example.com');
        $this->reader->reset();

        return $package;
    }

    public function statePath(string $file): string
    {
        return $this->projectDir.'/var/vtinnovations-smtp/state/'.$file;
    }

    public function cleanUp(): void
    {
        if (!is_dir($this->projectDir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->projectDir);
    }
}
