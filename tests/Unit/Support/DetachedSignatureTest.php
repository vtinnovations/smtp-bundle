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

final class DetachedSignatureTest extends TestCase
{
    private DetachedSignature $signature;
    private string $secretKey;
    private string $publicKey;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();

        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        $this->signature = new DetachedSignature();
    }

    public function testAGenuineSignatureVerifies(): void
    {
        $message = 'canonical bytes';

        self::assertTrue($this->signature->verify($this->sign($message), $message, $this->publicKey));
    }

    public function testASingleAlteredByteFails(): void
    {
        $signed = $this->sign('canonical bytes');

        self::assertFalse($this->signature->verify($signed, 'canonical byteS', $this->publicKey));
    }

    public function testWhitespaceIsNotForgivenEither(): void
    {
        // The signature covers exact bytes. "Same JSON, different spacing" is a different message.
        $signed = $this->sign('{"a":1}');

        self::assertFalse($this->signature->verify($signed, '{"a": 1}', $this->publicKey));
    }

    public function testAnotherKeyDoesNotVerify(): void
    {
        $other  = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $signed = $this->sign('canonical bytes');

        self::assertFalse($this->signature->verify($signed, 'canonical bytes', $other));
    }

    public function testUnsupportedAlgorithmIsRefused(): void
    {
        $message = 'canonical bytes';

        self::assertFalse($this->signature->verify($this->sign($message), $message, $this->publicKey, 'rsa-sha256'));
        self::assertFalse(DetachedSignature::supports('none'));
        self::assertTrue(DetachedSignature::supports('ED25519'));
    }

    public function testMalformedSignatureMaterialIsRefused(): void
    {
        self::assertFalse($this->signature->verify('', 'x', $this->publicKey));
        self::assertFalse($this->signature->verify('!!!not base64!!!', 'x', $this->publicKey));
        self::assertFalse($this->signature->verify(base64_encode('too short'), 'x', $this->publicKey));
    }

    public function testMalformedKeyMaterialIsRefused(): void
    {
        $message = 'canonical bytes';

        self::assertFalse($this->signature->verify($this->sign($message), $message, 'not-a-key'));
    }

    public function testVerifyWithAnyTriesEveryHeldKey(): void
    {
        $other   = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $message = 'canonical bytes';

        self::assertTrue($this->signature->verifyWithAny($this->sign($message), $message, [$other, $this->publicKey]));
        self::assertFalse($this->signature->verifyWithAny($this->sign($message), $message, [$other]));
        self::assertFalse($this->signature->verifyWithAny($this->sign($message), $message, []));
    }

    private function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));
    }
}
