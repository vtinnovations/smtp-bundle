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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Vtinnovations\SmtpBundle\Config\RuntimeState;
use Vtinnovations\SmtpBundle\Http\ProvisioningClient;
use Vtinnovations\SmtpBundle\Service\Entitlement;
use Vtinnovations\SmtpBundle\Service\ProvisioningOutcome;
use Vtinnovations\SmtpBundle\Service\ProvisioningService;
use Vtinnovations\SmtpBundle\Tests\Fixture\Installation;
use Vtinnovations\SmtpBundle\Tests\Fixture\RecordFactory;

/**
 * The rule under test throughout: a working installation is never made worse by an exchange. Not by
 * an outage, not by a refusal, not by a package that fails verification, not by a replayed old one.
 */
final class ProvisioningServiceTest extends TestCase
{
    private Installation $install;

    protected function setUp(): void
    {
        $this->install = new Installation();
    }

    protected function tearDown(): void
    {
        $this->install->cleanUp();
    }

    public function testActivationInstallsAVerifiedPackage(): void
    {
        $package = $this->install->factory->package();

        $outcome = $this->service($this->answering($package))->activate('MY-KEY');

        self::assertTrue($outcome->succeeded());
        self::assertTrue($this->install->reader->isGranted());
        self::assertSame(7, $this->install->reader->installedVersion());
        self::assertSame('MY-KEY', $this->install->runtimeState->key());
        self::assertSame('example.com', $this->install->runtimeState->matchedHost());
    }

    public function testActivationStoresTheExactDeliveredBytes(): void
    {
        $package = $this->install->factory->package();

        $this->service($this->answering($package))->activate('MY-KEY');

        // Re-encoding would change the bytes the signature covers, so the record is kept verbatim.
        self::assertSame($package['bytes'], (string) file_get_contents($this->install->statePath('record.json')));
    }

    public function testAnEmptyKeyNeverReachesTheNetwork(): void
    {
        $called = false;

        $client = new MockHttpClient(static function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('{}');
        });

        self::assertSame(
            ProvisioningOutcome::NO_KEY,
            $this->service($client)->activate('   ')->code,
        );
        self::assertFalse($called);
    }

    public function testAnInstallationWithNoConfiguredHostRefusesToAsk(): void
    {
        // Asking with a hostname taken from the request would let whoever sent that request choose
        // this installation's identity.
        $install = new Installation(configuredHosts: [], currentHost: 'attacker.example.net');

        try {
            $outcome = $this->service($this->answering($install->factory->package()), $install)->activate('MY-KEY');

            self::assertSame(ProvisioningOutcome::NO_CONFIGURED_DOMAIN, $outcome->code);
        } finally {
            $install->cleanUp();
        }
    }

    public function testAnOutageLeavesAWorkingInstallationAlone(): void
    {
        $this->install->install();

        $client  = new MockHttpClient(static fn (): MockResponse => new MockResponse('boom', ['http_code' => 500]));
        $outcome = $this->service($client)->refresh();

        self::assertSame(ProvisioningOutcome::UNAVAILABLE, $outcome->code);
        $this->install->reader->reset();
        self::assertTrue($this->install->reader->isGranted(), 'A network problem is not a licensing verdict.');
    }

    public function testARefusalWithholdsEntitlementButKeepsTheRecord(): void
    {
        $this->install->install();

        $outcome = $this->service($this->refusing())->refresh();

        self::assertSame(ProvisioningOutcome::REFUSED, $outcome->code);
        self::assertFalse($this->install->reader->isGranted());
        // Unsigned data does not get to destroy signed data — and the version is still needed to
        // refuse a rollback later.
        self::assertNotNull($this->install->store->read());
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testAPackageForAnotherHostIsNotInstalled(): void
    {
        $this->install->install();

        $foreign = $this->install->factory->package([
            'license_domain'  => 'someone-else.example.net',
            'license_domains' => ['someone-else.example.net'],
            'license_version' => 9,
        ]);

        $outcome = $this->service($this->answering($foreign))->refresh();

        self::assertSame(ProvisioningOutcome::NOT_ACCEPTED_LOCALLY, $outcome->code);
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testAPackageSignedByAnotherIssuerIsNotInstalled(): void
    {
        $this->install->install();

        $outcome = $this->service($this->answering((new RecordFactory())->package(['license_version' => 9])))->refresh();

        self::assertSame(ProvisioningOutcome::NOT_ACCEPTED_LOCALLY, $outcome->code);
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testACorruptedPackageIsNotInstalled(): void
    {
        $this->install->install();

        $package = $this->install->factory->package(['license_version' => 9]);
        $package['payload_b64'] = base64_encode($package['bytes'].' ');

        $outcome = $this->service($this->answering($package))->refresh();

        self::assertSame(ProvisioningOutcome::NOT_ACCEPTED_LOCALLY, $outcome->code);
        self::assertSame('checksum_mismatch', $outcome->detail);
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testAnOlderRevisionOfTheSameLicenceIsRefused(): void
    {
        // Replaying yesterday's record is how a downgrade would be staged.
        $this->install->install(['license_version' => 12]);

        $outcome = $this->service($this->answering($this->install->factory->package(['license_version' => 5])))->refresh();

        self::assertSame(ProvisioningOutcome::ROLLBACK_REFUSED, $outcome->code);
        self::assertSame(12, $this->install->reader->installedVersion());
    }

    public function testTheSameRevisionIsAcceptedSoARefreshCanRenewDates(): void
    {
        $this->install->install(['license_version' => 7]);

        $outcome = $this->service($this->answering($this->install->factory->package([
            'license_version'     => 7,
            'license_verified_at' => time(),
        ])))->refresh();

        self::assertTrue($outcome->succeeded());
    }

    public function testADifferentLicenceIsNotSubjectToTheOtherLicencesNumbering(): void
    {
        // Entering a new key is a deliberate change; its revision numbering has nothing to do with
        // the previous licence's.
        $this->install->install(['license_version' => 12]);

        $outcome = $this->service($this->answering($this->install->factory->package([
            'license_key'     => 'ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ',
            'license_version' => 1,
        ])))->activate('ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ');

        self::assertTrue($outcome->succeeded());
        self::assertSame(1, $this->install->reader->installedVersion());
    }

    public function testRemoveDiscardsTheLicenceAndRestoresDefaultBehaviour(): void
    {
        $this->install->install();
        self::assertTrue($this->install->reader->isGranted());

        $this->service($this->answering())->remove();

        self::assertFalse($this->install->reader->isGranted());
        self::assertNull($this->install->store->read(), 'The sealed record itself must be gone, not merely hidden.');
        self::assertSame('', $this->install->runtimeState->key(), 'The remembered key must not survive removal either.');
        self::assertSame(Entitlement::NO_STATE, $this->install->reader->current()->reason);
    }

    public function testRemoveIsHarmlessWhenNothingIsInstalled(): void
    {
        $this->service($this->answering())->remove();

        self::assertFalse($this->install->reader->isGranted());
        self::assertNull($this->install->store->read());
    }

    public function testRefreshDoesNothingWithoutAStoredKey(): void
    {
        self::assertSame(ProvisioningOutcome::NO_KEY, $this->service($this->answering())->refresh()->code);
    }

    public function testTheQuietRecheckOnlyRunsWhenTheConfirmationIsStale(): void
    {
        $calls = 0;

        $package = $this->install->factory->package();
        $client  = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, $package): MockResponse {
            ++$calls;

            return $this->packageResponse($package, $options);
        });

        $this->install->install();

        $this->service($client)->refreshIfStale();
        self::assertSame(0, $calls, 'A fresh confirmation is not re-asked.');

        $this->install->runtimeState->rememberSuccess('example.com');
        // Age the confirmation past the window without touching the clock.
        $path = $this->install->projectDir.'/var/vtinnovations-smtp/runtime.json';
        $data = json_decode((string) file_get_contents($path), true);
        $data['confirmed_at'] = time() - 200000;
        file_put_contents($path, json_encode($data));

        $this->service($client, runtimeState: new RuntimeState($this->install->projectDir))->refreshIfStale();

        self::assertSame(1, $calls);
    }

    // --- helpers -----------------------------------------------------------------------------

    private function service(MockHttpClient $client, ?Installation $install = null, ?RuntimeState $runtimeState = null): ProvisioningService
    {
        $install ??= $this->install;

        return new ProvisioningService(
            new ProvisioningClient($client),
            $install->opener,
            $install->inspector,
            $install->store,
            $runtimeState ?? $install->runtimeState,
            $install->reader,
            $install->hosts,
        );
    }

    /**
     * @param array{bytes: string, payload_b64: string, envelope: array<string, mixed>}|null $package
     */
    private function answering(?array $package = null): MockHttpClient
    {
        $package ??= $this->install->factory->package();

        return new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->packageResponse($package, $options));
    }

    private function refusing(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);

            return new MockResponse(
                (string) json_encode(['status' => 'invalid', 'request_id' => $body['request_id'] ?? '']),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
    }

    /**
     * @param array{bytes: string, payload_b64: string, envelope: array<string, mixed>} $package
     * @param array<string, mixed>                                                      $options
     */
    private function packageResponse(array $package, array $options): MockResponse
    {
        $body = json_decode((string) ($options['body'] ?? '{}'), true);

        return new MockResponse(
            (string) json_encode([
                'status'              => 'valid',
                'request_id'          => $body['request_id'] ?? '',
                'server_time'         => time(),
                'license_payload_b64' => $package['payload_b64'],
                'integrity'           => $package['envelope'],
            ]),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }
}
