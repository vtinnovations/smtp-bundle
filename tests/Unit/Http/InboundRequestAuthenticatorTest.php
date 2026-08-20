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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Http\InboundRequestAuthenticator;
use Vtinnovations\SmtpBundle\Support\DetachedSignature;
use Vtinnovations\SmtpBundle\Support\TrustedKeys;
use Vtinnovations\SmtpBundle\Tests\Fixture\RecordFactory;

/**
 * The signed message is a contract with the issuer, so it is asserted as a fixed string rather than
 * rebuilt by the same code that produces it.
 */
final class InboundRequestAuthenticatorTest extends TestCase
{
    private RecordFactory $factory;
    private string $path;

    protected function setUp(): void
    {
        $this->factory = new RecordFactory();
        $this->path    = DeploymentProfile::updaterPath();
    }

    public function testTheSignedMessageIsTheFixedSixLineForm(): void
    {
        $message = $this->authenticator()->signedMessage('post', '/rest/api/v1/smtp-license-updater', 'req-1', '1784882547', 'nonce-1', '{"a":1}');

        self::assertSame(
            "POST\n"
            ."/rest/api/v1/smtp-license-updater\n"
            ."req-1\n"
            ."1784882547\n"
            ."nonce-1\n"
            .hash('sha256', '{"a":1}'),
            $message,
        );
        self::assertStringEndsNotWith("\n", $message, 'No trailing newline.');
    }

    public function testTheKeyIdIsNotPartOfTheSignedMessage(): void
    {
        // It selects the key; naming a different one only sends verification to a key the signature
        // will not check out under.
        $message = $this->authenticator()->signedMessage('POST', $this->path, 'req-1', '1', 'n', '');

        self::assertStringNotContainsString($this->factory->keyId, $message);
    }

    public function testAGenuineRequestIsAccepted(): void
    {
        $body    = '{"action":"license_update"}';
        $headers = $this->factory->requestHeaders('POST', $this->path, $body);

        self::assertTrue($this->check($headers, $body));
    }

    public function testAChangedBodyFailsEvenWithTheOriginalHeaders(): void
    {
        $headers = $this->factory->requestHeaders('POST', $this->path, '{"action":"license_update"}');

        self::assertFalse($this->check($headers, '{"action":"license_update"} '));
    }

    public function testARequestSignedForAnotherPathIsNotReplayableOntoThisOne(): void
    {
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', '/rest/api/v1/other-license-updater', $body);

        self::assertFalse($this->check($headers, $body));
    }

    public function testARequestAimedAtAnUnexpectedPathIsRefused(): void
    {
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', '/somewhere/else', $body);

        self::assertFalse($this->check($headers, $body, path: '/somewhere/else'));
    }

    public function testAChangedMethodFails(): void
    {
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', $this->path, $body);

        self::assertFalse($this->check($headers, $body, method: 'PUT'));
    }

    public function testAStaleRequestIsRefused(): void
    {
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', $this->path, $body, [
            'timestamp' => (string) (time() - DeploymentProfile::MAX_CLOCK_SKEW_SECONDS - 60),
        ]);

        self::assertFalse($this->check($headers, $body));
    }

    public function testARequestFromTheFutureIsRefusedToo(): void
    {
        // Otherwise a captured request could be parked and used later.
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', $this->path, $body, [
            'timestamp' => (string) (time() + DeploymentProfile::MAX_CLOCK_SKEW_SECONDS + 60),
        ]);

        self::assertFalse($this->check($headers, $body));
    }

    public function testANonNumericTimestampIsRefused(): void
    {
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', $this->path, $body, ['timestamp' => 'now']);

        self::assertFalse($this->check($headers, $body));
    }

    public function testMissingMetadataIsRefused(): void
    {
        $body = '{}';

        foreach (['request_id', 'nonce', 'timestamp', 'key_id'] as $field) {
            $headers          = $this->factory->requestHeaders('POST', $this->path, $body);
            $headers[$field]  = '';

            self::assertFalse($this->check($headers, $body), $field.' must be required');
        }
    }

    public function testAnUnknownKeyIdIsRefused(): void
    {
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', $this->path, $body, ['key_id' => 'rotated-away']);

        self::assertFalse($this->check($headers, $body));
    }

    public function testAnUnsignedRequestIsRefused(): void
    {
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', $this->path, $body, ['signature' => '']);

        self::assertFalse($this->check($headers, $body));
    }

    public function testAnEmptyKeyRingRefusesEverything(): void
    {
        $body    = '{}';
        $headers = $this->factory->requestHeaders('POST', $this->path, $body);

        $authenticator = new InboundRequestAuthenticator(TrustedKeys::withoutKeys(), new DetachedSignature());

        self::assertFalse($authenticator->isAuthentic(
            'POST',
            $this->path,
            $headers['request_id'],
            $headers['timestamp'],
            $headers['nonce'],
            $headers['key_id'],
            $headers['signature'],
            $body,
        ));
    }

    /** @param array<string, string> $headers */
    private function check(array $headers, string $body, string $method = 'POST', ?string $path = null): bool
    {
        return $this->authenticator()->isAuthentic(
            $method,
            $path ?? $this->path,
            $headers['request_id'],
            $headers['timestamp'],
            $headers['nonce'],
            $headers['key_id'],
            $headers['signature'],
            $body,
        );
    }

    private function authenticator(): InboundRequestAuthenticator
    {
        return new InboundRequestAuthenticator($this->factory->keys(), new DetachedSignature());
    }
}
