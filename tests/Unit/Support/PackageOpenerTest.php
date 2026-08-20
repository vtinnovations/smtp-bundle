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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Exception\PackageRejectedException;
use Vtinnovations\SmtpBundle\Support\CanonicalForm;
use Vtinnovations\SmtpBundle\Support\DetachedSignature;
use Vtinnovations\SmtpBundle\Support\PackageOpener;
use Vtinnovations\SmtpBundle\Support\TrustedKeys;
use Vtinnovations\SmtpBundle\Tests\Fixture\RecordFactory;

/**
 * The attack this class exists to stop: edit the record, recompute its MD5, and hand the pair over.
 * That has to fail, which means the envelope's signature must be what decides and the checksum must
 * only ever be compared against a value the issuer signed.
 */
final class PackageOpenerTest extends TestCase
{
    private RecordFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RecordFactory();
    }

    public function testAGenuinePackageOpens(): void
    {
        $package = $this->factory->package();

        $sealed = $this->opener()->open($package['payload_b64'], $package['envelope']);

        self::assertSame($package['bytes'], $sealed->bytes);
        self::assertSame(7, $sealed->version);
    }

    public function testEditedBytesWithARecomputedChecksumAreRefused(): void
    {
        $package = $this->factory->package();
        $tampered = str_replace('"free"', '"paid"', $package['bytes']);

        self::assertNotSame($package['bytes'], $tampered);

        // Exactly what an attacker would do: fix up the checksum to match the edit. The envelope
        // signature no longer covers the new checksum, so it fails there.
        $envelope = $package['envelope'];
        $envelope['license_md5'] = md5($tampered);

        $this->expectRejection(PackageRejectedException::BAD_ENVELOPE_SIG);
        $this->opener()->open(base64_encode($tampered), $envelope);
    }

    public function testEditedBytesWithTheOriginalChecksumAreRefused(): void
    {
        $package  = $this->factory->package();
        $tampered = str_replace('"free"', '"paid"', $package['bytes']);

        $this->expectRejection(PackageRejectedException::CHECKSUM_MISMATCH);
        $this->opener()->open(base64_encode($tampered), $package['envelope']);
    }

    public function testWhitespaceOnlyMutationIsRefused(): void
    {
        // Same data, different bytes. The record is stored verbatim precisely so this is caught.
        $package = $this->factory->package();

        $this->expectRejection(PackageRejectedException::CHECKSUM_MISMATCH);
        $this->opener()->open(base64_encode($package['bytes']."\n"), $package['envelope']);
    }

    public function testAForeignEnvelopeIsRefused(): void
    {
        $package = $this->factory->package();
        $foreign = new RecordFactory('other-key');

        $this->expectRejection(PackageRejectedException::UNKNOWN_KEY);
        $this->opener()->open($package['payload_b64'], $foreign->envelope($package['bytes']));
    }

    public function testUnknownKeyIdIsRefused(): void
    {
        $package = $this->factory->package([], ['key_id' => 'rotated-away']);

        $this->expectRejection(PackageRejectedException::UNKNOWN_KEY);
        $this->opener()->open($package['payload_b64'], $package['envelope']);
    }

    public function testUnknownAlgorithmIsRefused(): void
    {
        $package = $this->factory->package([], ['signature_algorithm' => 'rsa-sha256']);

        $this->expectRejection(PackageRejectedException::UNKNOWN_KEY);
        $this->opener()->open($package['payload_b64'], $package['envelope']);
    }

    public function testInvalidBase64IsRefused(): void
    {
        $package = $this->factory->package();

        $this->expectRejection(PackageRejectedException::PAYLOAD_NOT_BASE64);
        $this->opener()->open('!!! not base64 !!!', $package['envelope']);
    }

    public function testAnIncompleteEnvelopeIsRefused(): void
    {
        $package = $this->factory->package();
        unset($package['envelope']['license_md5']);

        $this->expectRejection(PackageRejectedException::ENVELOPE_MALFORMED);
        $this->opener()->open($package['payload_b64'], $package['envelope']);
    }

    public function testAnEmptyKeyRingFailsClosedWithItsOwnReason(): void
    {
        $package = $this->factory->package();

        $opener = new PackageOpener(new CanonicalForm(), TrustedKeys::withoutKeys(), new DetachedSignature());

        // Never a pass, and never confused with "bad licence": the remedy is a real key.
        $this->expectRejection(PackageRejectedException::KEY_STORE_EMPTY);
        $opener->open($package['payload_b64'], $package['envelope']);
    }

    public function testStoredPairIsCheckedTheSameWay(): void
    {
        $package = $this->factory->package();

        $sealed = $this->opener()->openBytes($package['bytes'], $package['envelope']);
        self::assertSame($package['bytes'], $sealed->bytes);

        $this->expectRejection(PackageRejectedException::CHECKSUM_MISMATCH);
        $this->opener()->openBytes($package['bytes'].' ', $package['envelope']);
    }

    private function opener(): PackageOpener
    {
        return new PackageOpener(new CanonicalForm(), $this->factory->keys(), new DetachedSignature());
    }

    private function expectRejection(string $reason): void
    {
        $this->expectException(PackageRejectedException::class);
        $this->expectExceptionMessage($reason);
    }
}
