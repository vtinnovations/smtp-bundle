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
use Vtinnovations\SmtpBundle\Service\Entitlement;
use Vtinnovations\SmtpBundle\Tests\Fixture\Installation;

/**
 * The reader fails closed, and it re-checks the seal on every read rather than trusting that what
 * was written is still what is there.
 */
final class EntitlementReaderTest extends TestCase
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

    public function testNoStateMeansNoEntitlement(): void
    {
        $state = $this->install->reader->current();

        self::assertFalse($state->granted);
        self::assertSame(Entitlement::NO_STATE, $state->reason);
        self::assertSame(0, $this->install->reader->installedVersion());
        self::assertNull($this->install->reader->authenticatedKey());
    }

    public function testAGenuineInstallationIsEntitled(): void
    {
        $this->install->install();

        self::assertTrue($this->install->reader->isGranted());
        self::assertSame(7, $this->install->reader->installedVersion());
        self::assertSame('example.com', $this->install->reader->matchedHost());
    }

    public function testDeletingTheRecordDoesNotFallBackToACachedResult(): void
    {
        // Any such cache would live in a file the site owner can write, which is the exact bypass
        // the signature exists to close.
        $this->install->install();
        self::assertTrue($this->install->reader->isGranted());

        $this->install->store->discard();
        $this->install->reader->reset();

        self::assertFalse($this->install->reader->isGranted());
    }

    public function testEditingTheStoredRecordBreaksTheSeal(): void
    {
        $this->install->install();

        $bytes = (string) file_get_contents($this->install->statePath('record.json'));
        file_put_contents($this->install->statePath('record.json'), str_replace('"free"', '"paid"', $bytes));
        $this->install->reader->reset();

        $state = $this->install->reader->current();

        self::assertFalse($state->granted);
        self::assertSame(Entitlement::SEAL_BROKEN, $state->reason);
    }

    public function testRecomputingTheStoredChecksumDoesNotHelp(): void
    {
        $this->install->install();

        $tampered = str_replace('"free"', '"paid"', (string) file_get_contents($this->install->statePath('record.json')));
        file_put_contents($this->install->statePath('record.json'), $tampered);

        $envelope = json_decode((string) file_get_contents($this->install->statePath('record.seal.json')), true);
        $envelope['license_md5'] = md5($tampered);
        file_put_contents($this->install->statePath('record.seal.json'), json_encode($envelope));

        $this->install->reader->reset();

        self::assertSame(Entitlement::SEAL_BROKEN, $this->install->reader->current()->reason);
    }

    public function testAnExplicitRefusalWithholdsEntitlementWithoutDestroyingTheRecord(): void
    {
        $this->install->install();

        $this->install->runtimeState->rememberRefusal();
        $this->install->reader->reset();

        self::assertSame(Entitlement::REVOKED, $this->install->reader->current()->reason);
        // Still on disk: an unsigned refusal does not get to delete signed state, and the version
        // is still needed to refuse a rollback.
        self::assertSame(7, $this->install->reader->installedVersion());
        self::assertNotNull($this->install->store->read());
    }

    public function testALaterSuccessClearsTheRefusal(): void
    {
        $this->install->install();
        $this->install->runtimeState->rememberRefusal();
        $this->install->reader->reset();
        self::assertFalse($this->install->reader->isGranted());

        $this->install->runtimeState->rememberSuccess('example.com');
        $this->install->reader->reset();

        self::assertTrue($this->install->reader->isGranted());
    }

    public function testTheInstalledVersionSurvivesARecordThatIsRefusedForOtherReasons(): void
    {
        // Rollback protection has to work even when the record is not currently entitling anything.
        $this->install->install(['license_expires_at' => time() - 10, 'free_available' => false]);

        self::assertFalse($this->install->reader->isGranted());
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testTheKeyIsAvailableOnlyFromAnAuthenticRecord(): void
    {
        $this->install->install();

        self::assertSame('AAAAA-BBBBB-CCCCC-DDDDD', $this->install->reader->authenticatedKey());

        $bytes = (string) file_get_contents($this->install->statePath('record.json'));
        file_put_contents($this->install->statePath('record.json'), str_replace('AAAAA', 'ZZZZZ', $bytes));
        $this->install->reader->reset();

        self::assertNull($this->install->reader->authenticatedKey());
    }

    public function testTheKeyIsStillAvailableWhenEntitlementIsWithheld(): void
    {
        // An expired record is still a genuine one, and the session signal is defined on the record
        // rather than on the entitlement.
        $this->install->install(['license_expires_at' => time() - 10, 'free_available' => false]);

        self::assertFalse($this->install->reader->isGranted());
        self::assertSame('AAAAA-BBBBB-CCCCC-DDDDD', $this->install->reader->authenticatedKey());
    }

    public function testTheResultIsComputedOnceUntilReset(): void
    {
        $this->install->install();
        self::assertTrue($this->install->reader->isGranted());

        $this->install->store->discard();

        self::assertTrue($this->install->reader->isGranted(), 'Still the cached answer within one request.');

        $this->install->reader->reset();

        self::assertFalse($this->install->reader->isGranted());
    }
}
