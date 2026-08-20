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
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Http\InboundRequestAuthenticator;
use Vtinnovations\SmtpBundle\Service\PushedUpdateHandler;
use Vtinnovations\SmtpBundle\Service\PushOutcome;
use Vtinnovations\SmtpBundle\Tests\Fixture\Installation;
use Vtinnovations\SmtpBundle\Tests\Fixture\RecordFactory;

/**
 * The inbound path, where every check has to hold against a caller who has seen a genuine request
 * go past: replay, id reuse, downgrade, foreign packages, and requests aimed somewhere else.
 */
final class PushedUpdateHandlerTest extends TestCase
{
    private Installation $install;
    private string $path;

    protected function setUp(): void
    {
        $this->install = new Installation();
        $this->path    = DeploymentProfile::updaterPath();
        $this->install->install();
    }

    protected function tearDown(): void
    {
        $this->install->cleanUp();
    }

    public function testAGenuinePushIsApplied(): void
    {
        [$headers, $body] = $this->push(['license_version' => 9]);

        $outcome = $this->handler()->handle('POST', $this->path, $headers, $body);

        self::assertSame(PushOutcome::UPDATED, $outcome->status);
        self::assertSame(200, $outcome->httpStatus);
        self::assertSame(9, $outcome->version);
        self::assertSame(9, $this->install->reader->installedVersion());
    }

    public function testAnExactRetryIsIdempotent(): void
    {
        [$headers, $body] = $this->push(['license_version' => 9]);

        $this->handler()->handle('POST', $this->path, $headers, $body);
        $again = $this->handler()->handle('POST', $this->path, $headers, $body);

        self::assertSame(PushOutcome::ALREADY_PROCESSED, $again->status);
        self::assertSame(200, $again->httpStatus);
        self::assertSame(9, $again->version);
        self::assertSame(9, $this->install->reader->installedVersion());
    }

    public function testTheSameRequestIdWithDifferentContentIsRefused(): void
    {
        // Not a retry — someone reusing an id that was already accepted.
        [$headers, $body] = $this->push(['license_version' => 9]);
        $this->handler()->handle('POST', $this->path, $headers, $body);

        [$other, $otherBody] = $this->push(['license_version' => 10], ['request_id' => $headers['request_id']]);

        $outcome = $this->handler()->handle('POST', $this->path, $other, $otherBody);

        self::assertSame(PushOutcome::UNAUTHORIZED, $outcome->status);
        self::assertSame(9, $this->install->reader->installedVersion());
    }

    public function testAReusedNonceIsRefusedEvenUnderANewRequestId(): void
    {
        [$headers, $body] = $this->push(['license_version' => 9]);
        $this->handler()->handle('POST', $this->path, $headers, $body);

        [$replay, $replayBody] = $this->push(['license_version' => 10], ['nonce' => $headers['nonce']]);

        $outcome = $this->handler()->handle('POST', $this->path, $replay, $replayBody);

        self::assertSame(PushOutcome::UNAUTHORIZED, $outcome->status);
        self::assertSame(9, $this->install->reader->installedVersion());
    }

    public function testAnOlderOrEqualRevisionIsRefusedWithoutRollback(): void
    {
        foreach ([5, 7] as $version) {
            [$headers, $body] = $this->push(['license_version' => $version]);

            $outcome = $this->handler()->handle('POST', $this->path, $headers, $body);

            self::assertSame(PushOutcome::REJECTED, $outcome->status);
            self::assertSame(409, $outcome->httpStatus);
            self::assertSame(7, $this->install->reader->installedVersion());
        }
    }

    public function testAnUnsignedRequestIsRefused(): void
    {
        [$headers, $body] = $this->push(['license_version' => 9], ['signature' => '']);

        self::assertSame(PushOutcome::UNAUTHORIZED, $this->handler()->handle('POST', $this->path, $headers, $body)->status);
    }

    public function testHeaderAndBodyMetadataMustAgree(): void
    {
        foreach (['request_id', 'nonce', 'timestamp'] as $field) {
            [$headers, $body] = $this->push(['license_version' => 9]);

            // Signed headers stay valid; the body says something else. Without this check the
            // journal could be marked with one id while another is applied.
            $decoded = json_decode($body, true);
            $decoded[$field] = 'something-else';
            $tampered = (string) json_encode($decoded);

            // Re-signed, so this is genuinely a metadata disagreement and not just a broken
            // signature — the check under test is the cross-comparison, not the crypto.
            unset($headers['signature']);
            $resigned = $this->install->factory->requestHeaders('POST', $this->path, $tampered, $headers);

            $outcome = $this->handler()->handle('POST', $this->path, $resigned, $tampered);

            self::assertSame(PushOutcome::UNAUTHORIZED, $outcome->status, $field.' must be cross-checked');
        }
    }

    public function testAPushForAnotherProductIsRefused(): void
    {
        foreach (['project' => 'Brickie', 'project_slug' => 'brickie', 'product_id' => 'vt-brickie'] as $field => $value) {
            [$headers, $body] = $this->push(['license_version' => 9], [], [$field => $value]);

            self::assertSame(
                PushOutcome::UNAUTHORIZED,
                $this->handler()->handle('POST', $this->path, $headers, $body)->status,
                $field.' must be checked',
            );
        }
    }

    public function testAPushForAnotherActionIsRefused(): void
    {
        [$headers, $body] = $this->push(['license_version' => 9], [], ['action' => 'license_delete']);

        self::assertSame(PushOutcome::UNAUTHORIZED, $this->handler()->handle('POST', $this->path, $headers, $body)->status);
    }

    public function testAPushForAnInstallationThisIsNotIsRefused(): void
    {
        [$headers, $body] = $this->push(
            [
                'license_version'  => 9,
                'license_domain'   => 'someone-else.example.net',
                'license_domains'  => ['someone-else.example.net'],
            ],
            [],
            ['domain' => 'someone-else.example.net'],
        );

        // Correctly signed, correctly addressed — for a different installation. No host in the
        // signed set is one of ours, so it changes nothing here.
        self::assertSame(PushOutcome::UNAUTHORIZED, $this->handler()->handle('POST', $this->path, $headers, $body)->status);
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testAPushMayNameAnyBoundHostAsLongAsOneIsOurs(): void
    {
        // The operation host need not be the one this node serves; the intersection is what matters.
        [$headers, $body] = $this->push(
            ['license_version' => 9, 'license_domain' => 'staging.example.com'],
            [],
            ['domain' => 'staging.example.com'],
        );

        self::assertSame(PushOutcome::UPDATED, $this->handler()->handle('POST', $this->path, $headers, $body)->status);
        self::assertSame('example.com', $this->install->runtimeState->matchedHost());
    }

    public function testThePushDomainMustMatchTheSignedOne(): void
    {
        [$headers, $body] = $this->push(['license_version' => 9], [], ['domain' => 'staging.example.com']);

        self::assertSame(PushOutcome::UNAUTHORIZED, $this->handler()->handle('POST', $this->path, $headers, $body)->status);
    }

    public function testACorruptedPayloadIsRefused(): void
    {
        $package = $this->install->factory->package(['license_version' => 9]);
        $package['payload_b64'] = base64_encode($package['bytes'].' ');

        [$headers, $body] = $this->pushPackage($package);

        self::assertSame(PushOutcome::UNAUTHORIZED, $this->handler()->handle('POST', $this->path, $headers, $body)->status);
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testAPackageFromAnotherIssuerIsRefused(): void
    {
        $foreign = new RecordFactory();

        [$headers, $body] = $this->pushPackage($foreign->package(['license_version' => 9]));

        self::assertSame(PushOutcome::UNAUTHORIZED, $this->handler()->handle('POST', $this->path, $headers, $body)->status);
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testAMalformedBodyIsRefused(): void
    {
        $body    = 'not json';
        $headers = $this->install->factory->requestHeaders('POST', $this->path, $body);

        self::assertSame(PushOutcome::UNAUTHORIZED, $this->handler()->handle('POST', $this->path, $headers, $body)->status);
    }

    public function testASuccessfulPushClearsAnEarlierRefusal(): void
    {
        $this->install->runtimeState->rememberRefusal();
        $this->install->reader->reset();
        self::assertFalse($this->install->reader->isGranted());

        [$headers, $body] = $this->push(['license_version' => 9]);
        $this->handler()->handle('POST', $this->path, $headers, $body);

        self::assertTrue($this->install->reader->isGranted());
    }

    // --- helpers -----------------------------------------------------------------------------

    /**
     * @param array<string, mixed>  $documentOverrides
     * @param array<string, string> $headerOverrides
     * @param array<string, mixed>  $bodyOverrides
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function push(array $documentOverrides, array $headerOverrides = [], array $bodyOverrides = []): array
    {
        return $this->pushPackage($this->install->factory->package($documentOverrides), $headerOverrides, $bodyOverrides);
    }

    /**
     * @param array{bytes: string, payload_b64: string, envelope: array<string, mixed>} $package
     * @param array<string, string>                                                     $headerOverrides
     * @param array<string, mixed>                                                      $bodyOverrides
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function pushPackage(array $package, array $headerOverrides = [], array $bodyOverrides = []): array
    {
        $requestId = $headerOverrides['request_id'] ?? 'req-'.bin2hex(random_bytes(6));
        $nonce     = $headerOverrides['nonce'] ?? 'nonce-'.bin2hex(random_bytes(6));
        $timestamp = $headerOverrides['timestamp'] ?? (string) time();

        $body = (string) json_encode(array_merge([
            'action'              => 'license_update',
            'project'             => DeploymentProfile::PROJECT,
            'project_slug'        => DeploymentProfile::PROJECT_SLUG,
            'product_id'          => DeploymentProfile::PRODUCT_ID,
            'domain'              => 'example.com',
            'request_id'          => $requestId,
            'timestamp'           => $timestamp,
            'nonce'               => $nonce,
            'license_payload_b64' => $package['payload_b64'],
            'integrity'           => $package['envelope'],
        ], $bodyOverrides));

        $headers = $this->install->factory->requestHeaders('POST', $this->path, $body, array_merge([
            'request_id' => $requestId,
            'nonce'      => $nonce,
            'timestamp'  => $timestamp,
        ], $headerOverrides));

        return [$headers, $body];
    }

    private function handler(): PushedUpdateHandler
    {
        return new PushedUpdateHandler(
            new InboundRequestAuthenticator($this->install->keys, $this->install->signature),
            $this->install->opener,
            $this->install->inspector,
            $this->install->store,
            $this->install->journal,
            $this->install->reader,
            $this->install->runtimeState,
        );
    }
}
