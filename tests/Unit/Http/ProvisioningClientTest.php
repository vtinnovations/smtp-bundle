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
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Http\ExchangeResult;
use Vtinnovations\SmtpBundle\Http\ProvisioningClient;
use Vtinnovations\SmtpBundle\Tests\Fixture\RecordFactory;

/**
 * The distinction these tests protect is UNAVAILABLE versus DENIED. Getting it wrong in one
 * direction erases a valid installation whenever the network hiccups; in the other it keeps a
 * refused installation running.
 */
final class ProvisioningClientTest extends TestCase
{
    /** @var array{method: string, url: string, options: array<string, mixed>}|null */
    private ?array $seen = null;

    public function testTheDestinationIsFixedAndTheControlsAreExplicit(): void
    {
        $this->exchange($this->validResponse());

        self::assertSame('POST', $this->seen['method']);
        self::assertSame('https://www.v-t.one/api/v1/verify', $this->seen['url']);
        self::assertSame(DeploymentProfile::exchangeEndpoint(), $this->seen['url']);

        // Redirects refused, so nothing on the other end can move this request elsewhere.
        self::assertSame(0, $this->seen['options']['max_redirects']);
        self::assertTrue($this->seen['options']['verify_peer']);
        self::assertTrue($this->seen['options']['verify_host']);
        // Symfony's HttpClient option resolver normalises timeout/max_duration to float
        // regardless of the int type passed in, so the comparison must match that, not this
        // client's own constants' declared type.
        self::assertSame((float) DeploymentProfile::CONNECT_TIMEOUT_SECONDS, $this->seen['options']['timeout']);
        self::assertSame((float) DeploymentProfile::TOTAL_TIMEOUT_SECONDS, $this->seen['options']['max_duration']);
    }

    public function testTheActivationPacketCarriesExactlyTheDocumentedFields(): void
    {
        $this->exchange($this->validResponse(), 'ACTIVATION-KEY', 'example.com');

        $body = $this->sentBody();

        self::assertSame([
            'action', 'domain', 'license_key', 'nonce', 'product_id', 'project', 'project_slug',
            'request_id', 'timestamp',
        ], $this->sortedKeys($body));

        self::assertSame('activate', $body['action']);
        self::assertSame(DeploymentProfile::PROJECT, $body['project']);
        self::assertSame('smtp', $body['project_slug']);
        self::assertSame('vt-smtp', $body['product_id']);
        self::assertSame('ACTIVATION-KEY', $body['license_key']);
        self::assertSame('example.com', $body['domain']);
        self::assertIsInt($body['timestamp']);
        self::assertNotSame($body['request_id'], $body['nonce'], 'The nonce is single-use, not a copy of the id.');
    }

    public function testTheRefreshPacketAddsTheInstalledVersion(): void
    {
        $this->exchange($this->validResponse(), action: ProvisioningClient::REFRESH, version: 7);

        $body = $this->sentBody();

        self::assertSame('refresh', $body['action']);
        self::assertSame(7, $body['current_license_version']);
    }

    public function testAValidAnswerIsPassedThroughUnjudged(): void
    {
        $factory = new RecordFactory();
        $package = $factory->package();

        $result = $this->exchange($this->validResponse($package));

        self::assertTrue($result->isValid());
        self::assertSame($package['payload_b64'], $result->payloadB64);
        self::assertSame($package['envelope'], $result->envelope);
    }

    public function testAnAnswerAboutADifferentRequestIsNotAnAnswer(): void
    {
        $result = $this->exchange(static fn (): MockResponse => self::json([
            'status'     => 'valid',
            'request_id' => 'some-other-request',
        ]));

        self::assertSame(ExchangeResult::UNAVAILABLE, $result->outcome);
        self::assertSame('request_id_mismatch', $result->reason);
    }

    public function testAServerErrorPreservesTheInstallation(): void
    {
        $result = $this->exchange(static fn (): MockResponse => new MockResponse('boom', ['http_code' => 500]));

        self::assertSame(ExchangeResult::UNAVAILABLE, $result->outcome);
        self::assertFalse($result->isDenied());
    }

    public function testA503IsTreatedAsAnOutageNotAVerdict(): void
    {
        $result = $this->exchange(fn (string $id): MockResponse => self::json(
            ['status' => 'unavailable', 'request_id' => $id],
            503,
        ));

        self::assertSame(ExchangeResult::UNAVAILABLE, $result->outcome);
    }

    public function testAnHtmlErrorPageIsNeverParsed(): void
    {
        $result = $this->exchange(static fn (): MockResponse => new MockResponse(
            '<html><body>404</body></html>',
            ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']],
        ));

        self::assertSame(ExchangeResult::UNAVAILABLE, $result->outcome);
        self::assertSame('unexpected_media_type', $result->reason);
    }

    public function testAnOversizedAnswerIsDroppedRatherThanParsed(): void
    {
        $result = $this->exchange(fn (string $id): MockResponse => self::json([
            'status'     => 'valid',
            'request_id' => $id,
            'padding'    => str_repeat('x', DeploymentProfile::MAX_RESPONSE_BYTES),
        ]));

        self::assertSame(ExchangeResult::UNAVAILABLE, $result->outcome);
        self::assertSame('response_too_large', $result->reason);
    }

    public function testWildClockSkewIsRefused(): void
    {
        $result = $this->exchange(fn (string $id): MockResponse => self::json([
            'status'      => 'valid',
            'request_id'  => $id,
            'server_time' => time() + 90000,
        ]));

        self::assertSame('clock_skew', $result->reason);
    }

    public function testATransportFailurePreservesTheInstallation(): void
    {
        $result = $this->exchange(static function (): MockResponse {
            throw new TransportException('connection refused to 10.0.0.1 via /var/run/proxy.sock');
        });

        self::assertSame(ExchangeResult::UNAVAILABLE, $result->outcome);
        self::assertSame('transport_error', $result->reason);
    }

    public function testAnExplicitRefusalIsADenial(): void
    {
        $result = $this->exchange(fn (string $id): MockResponse => self::json([
            'status'     => 'invalid',
            'request_id' => $id,
            'message'    => 'Key revoked by <b>support</b>',
        ]));

        self::assertTrue($result->isDenied());
        // No remote text is carried through, so nothing written over there can be rendered here.
        self::assertSame('refused', $result->reason);
    }

    /**
     * @param \Closure(string): MockResponse $responder
     */
    private function exchange(
        \Closure $responder,
        string $key = 'KEY',
        string $host = 'example.com',
        string $action = ProvisioningClient::ACTIVATE,
        ?int $version = null,
    ): ExchangeResult {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($responder): MockResponse {
            $this->seen = ['method' => $method, 'url' => $url, 'options' => $options];

            $body = json_decode((string) ($options['body'] ?? '{}'), true);

            return $responder((string) ($body['request_id'] ?? ''));
        });

        return (new ProvisioningClient($client))->exchange($key, $host, $action, $version);
    }

    /**
     * @param array{bytes: string, payload_b64: string, envelope: array<string, mixed>}|null $package
     *
     * @return \Closure(string): MockResponse
     */
    private function validResponse(?array $package = null): \Closure
    {
        $package ??= (new RecordFactory())->package();

        return static fn (string $id): MockResponse => self::json([
            'status'              => 'valid',
            'request_id'          => $id,
            'server_time'         => time(),
            'license_payload_b64' => $package['payload_b64'],
            'integrity'           => $package['envelope'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(
            (string) json_encode($data),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        return (array) json_decode((string) ($this->seen['options']['body'] ?? '{}'), true);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function sortedKeys(array $body): array
    {
        $keys = array_keys($body);
        sort($keys);

        return $keys;
    }
}
