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
use Vtinnovations\SmtpBundle\Support\DetachedSignature;
use Vtinnovations\SmtpBundle\Support\TrustedKeys;

/**
 * The pinned ring is the trust anchor. An empty or wrong ring does not degrade gracefully — it
 * means nothing can be verified at all — so these tests assert both that the shipped ring is real
 * and that every way of not having one fails closed.
 */
final class TrustedKeysTest extends TestCase
{
    public function testShippedRingIsNotEmpty(): void
    {
        // A build with no pinned key could never verify a genuine response. That must never ship.
        self::assertFalse((new TrustedKeys())->isEmpty());
    }

    public function testShippedKeyMatchesThePublishedFingerprint(): void
    {
        $keys = (new TrustedKeys())->keysFor(TrustedKeys::PURPOSE_RECORD);

        self::assertCount(1, $keys);
        self::assertSame('edcd614e70c59ce0', TrustedKeys::fingerprint($keys[0]));
        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, \strlen($keys[0]));
    }

    public function testShippedRingResolvesItsAdvertisedKeyId(): void
    {
        $keys = new TrustedKeys();

        self::assertNotNull($keys->keyFor('vtone-2026a', TrustedKeys::PURPOSE_ENVELOPE, 'ed25519'));
        // The derived id is accepted too, for the case where no label is published.
        self::assertNotNull($keys->keyFor('edcd614e70c59ce0', TrustedKeys::PURPOSE_ENVELOPE, 'ed25519'));
    }

    public function testProductionReadinessPassesForTheShippedRing(): void
    {
        (new TrustedKeys())->assertProductionReady();

        $this->addToAssertionCount(1);
    }

    public function testProductionReadinessRejectsAnEmptyRing(): void
    {
        $this->expectException(\RuntimeException::class);

        TrustedKeys::withoutKeys()->assertProductionReady();
    }

    public function testEmptyRingResolvesNothing(): void
    {
        $keys = TrustedKeys::withoutKeys();

        self::assertTrue($keys->isEmpty());
        self::assertNull($keys->keyFor('vtone-2026a', TrustedKeys::PURPOSE_RECORD));
        self::assertSame([], $keys->keysFor(TrustedKeys::PURPOSE_RECORD));
    }

    /**
     * @dataProvider placeholderKeys
     */
    public function testPlaceholderAndMalformedKeyMaterialIsRefused(string $encoded): void
    {
        $keys = new TrustedKeys($encoded, $encoded, 'label', 'label');

        self::assertTrue($keys->isEmpty(), 'Placeholder key material must not populate the ring.');
    }

    /**
     * A blank string is deliberately excluded here: it is the "no override configured" signal the
     * constructor treats as falling back to the baked-in key (see {@see testShippedRingIsNotEmpty}),
     * not a placeholder to be refused. These are values an operator might paste in by mistake that
     * are not blank and must still not resolve to a usable key.
     *
     * @return iterable<string, array{string}>
     */
    public static function placeholderKeys(): iterable
    {
        yield 'todo text'        => ['REPLACE_ME'];
        yield 'not base64'       => ['!!!!'];
        yield 'wrong length'     => [base64_encode('short')];
        yield 'too long'         => [base64_encode(random_bytes(64))];
    }

    public function testUnknownKeyIdIsRefusedRatherThanFallingBack(): void
    {
        $keys = new TrustedKeys(base64_encode(random_bytes(32)), '', 'known', '');

        self::assertNull($keys->keyFor('some-other-id', TrustedKeys::PURPOSE_RECORD));
    }

    public function testAlgorithmMismatchIsRefused(): void
    {
        $keys = new TrustedKeys(base64_encode(random_bytes(32)), '', 'known', '');

        self::assertNull($keys->keyFor('known', TrustedKeys::PURPOSE_RECORD, 'rsa-sha256'));
        self::assertNotNull($keys->keyFor('known', TrustedKeys::PURPOSE_RECORD, DetachedSignature::ED25519));
    }

    public function testACallerSuppliedKeyDoesNotInheritTheBakedInLabel(): void
    {
        // Pairing the pinned label with unrelated material would make every lookup silently wrong.
        $keys = new TrustedKeys(base64_encode($raw = random_bytes(32)));

        self::assertNull($keys->keyFor('vtone-2026a', TrustedKeys::PURPOSE_RECORD));
        self::assertSame($raw, $keys->keyFor(TrustedKeys::fingerprint($raw), TrustedKeys::PURPOSE_RECORD));
    }

    public function testKeyIdLookupIsCaseInsensitive(): void
    {
        $keys = new TrustedKeys(base64_encode(random_bytes(32)), '', 'Vtone-Label', '');

        self::assertNotNull($keys->keyFor('vtone-label', TrustedKeys::PURPOSE_RECORD));
    }

    public function testBothSlotsAreOfferedDuringRotation(): void
    {
        $keys = new TrustedKeys(
            base64_encode(random_bytes(32)),
            base64_encode(random_bytes(32)),
            'new',
            'old',
        );

        self::assertCount(2, $keys->keysFor(TrustedKeys::PURPOSE_RECORD));
        self::assertNotNull($keys->keyFor('old', TrustedKeys::PURPOSE_RECORD));
    }
}
