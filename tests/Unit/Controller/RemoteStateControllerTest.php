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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Controller\RemoteStateController;
use Vtinnovations\SmtpBundle\Http\InboundRequestAuthenticator;
use Vtinnovations\SmtpBundle\Service\PushedUpdateHandler;
use Vtinnovations\SmtpBundle\Tests\Fixture\Installation;

/**
 * The endpoint's public behaviour, end to end against the real handler.
 *
 * The status codes are part of the contract: a GET must say 405 rather than 404, so an operator can
 * confirm the endpoint exists without being able to drive it, and every refusal must look identical
 * so nothing can be learned from which one came back.
 */
final class RemoteStateControllerTest extends TestCase
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

    public function testTheRouteIsTheExactDocumentedPath(): void
    {
        self::assertSame('/rest/api/v1/smtp-license-updater', $this->path);

        // The attribute cannot call a method, so the literal there and the constant here must agree.
        $attribute = (new \ReflectionMethod(RemoteStateController::class, '__invoke'))
            ->getAttributes(\Symfony\Component\Routing\Attribute\Route::class)[0];

        self::assertSame($this->path, $attribute->getArguments()[0]);
    }

    public function testGetAnswers405RatherThan404(): void
    {
        $response = $this->call(Request::create($this->path, 'GET'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->headers->get('Allow'));
    }

    public function testAnUnsupportedMediaTypeIsRefusedBeforeParsing(): void
    {
        $request = Request::create($this->path, 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');

        self::assertSame(415, $this->call($request)->getStatusCode());
    }

    public function testAnOversizedBodyIsRefusedBeforeParsing(): void
    {
        $body    = str_repeat('x', DeploymentProfile::MAX_INBOUND_BYTES + 1);
        $request = Request::create($this->path, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertSame(413, $this->call($request)->getStatusCode());
    }

    public function testAnUnsignedRequestGetsAGenericRefusal(): void
    {
        $request = Request::create($this->path, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"action":"license_update"}');

        $response = $this->call($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['status' => 'unauthorized'], json_decode((string) $response->getContent(), true));
    }

    public function testEveryRefusalLooksTheSame(): void
    {
        $package = $this->install->factory->package(['license_version' => 9]);

        $bodies = [
            'wrong action'   => $this->body($package, ['action' => 'license_delete']),
            'wrong product'  => $this->body($package, ['project_slug' => 'brickie']),
            'wrong domain'   => $this->body($package, ['domain' => 'attacker.example.net']),
            'no domain'      => $this->body($package, ['domain' => '']),
        ];

        foreach ($bodies as $label => $body) {
            $response = $this->call($this->signedRequest($body));

            self::assertSame(401, $response->getStatusCode(), $label);
            self::assertSame('{"status":"unauthorized"}', (string) $response->getContent(), $label);
        }
    }

    public function testAGenuinePushIsAppliedAndReported(): void
    {
        $package  = $this->install->factory->package(['license_version' => 9]);
        $body     = $this->body($package);
        $response = $this->call($this->signedRequest($body));

        $decoded = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('updated', $decoded['status']);
        self::assertSame(9, $decoded['license_version']);
        self::assertSame(json_decode($body, true)['request_id'], $decoded['request_id']);
    }

    public function testAnExactRetryIsAnsweredWithoutReapplying(): void
    {
        $body    = $this->body($this->install->factory->package(['license_version' => 9]));
        $request = $this->signedRequest($body);

        $this->call($request);
        $second = $this->call($this->signedRequest($body, $this->headersFor($body)));

        self::assertSame(200, $second->getStatusCode());
        self::assertSame('already_processed', json_decode((string) $second->getContent(), true)['status']);
    }

    public function testAnOlderRevisionIsRefusedWithAConflict(): void
    {
        $body     = $this->body($this->install->factory->package(['license_version' => 3]));
        $response = $this->call($this->signedRequest($body));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('rejected', json_decode((string) $response->getContent(), true)['status']);
        self::assertSame(7, $this->install->reader->installedVersion());
    }

    public function testNoResponseEverLeaksPacketMaterial(): void
    {
        $package  = $this->install->factory->package(['license_version' => 9]);
        $body     = $this->body($package);
        $response = (string) $this->call($this->signedRequest($body))->getContent();

        foreach ([
            $package['payload_b64'],
            $package['envelope']['license_md5'],
            $package['envelope']['signature'],
            'AAAAA-BBBBB-CCCCC-DDDDD',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $response);
        }
    }

    // --- helpers -----------------------------------------------------------------------------

    /**
     * @param array{bytes: string, payload_b64: string, envelope: array<string, mixed>} $package
     * @param array<string, mixed>                                                      $overrides
     */
    private function body(array $package, array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'action'              => 'license_update',
            'project'             => DeploymentProfile::PROJECT,
            'project_slug'        => DeploymentProfile::PROJECT_SLUG,
            'product_id'          => DeploymentProfile::PRODUCT_ID,
            'domain'              => 'example.com',
            'request_id'          => 'req-'.bin2hex(random_bytes(6)),
            'timestamp'           => (string) time(),
            'nonce'               => 'nonce-'.bin2hex(random_bytes(6)),
            'license_payload_b64' => $package['payload_b64'],
            'integrity'           => $package['envelope'],
        ], $overrides));
    }

    /** @return array<string, string> */
    private function headersFor(string $body): array
    {
        $decoded = json_decode($body, true);

        return $this->install->factory->requestHeaders('POST', $this->path, $body, [
            'request_id' => (string) ($decoded['request_id'] ?? ''),
            'nonce'      => (string) ($decoded['nonce'] ?? ''),
            'timestamp'  => (string) ($decoded['timestamp'] ?? ''),
        ]);
    }

    /** @param array<string, string>|null $headers */
    private function signedRequest(string $body, ?array $headers = null): Request
    {
        $headers ??= $this->headersFor($body);

        return Request::create($this->path, 'POST', server: [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_X_VT_REQUEST_ID' => $headers['request_id'],
            'HTTP_X_VT_TIMESTAMP'  => $headers['timestamp'],
            'HTTP_X_VT_NONCE'      => $headers['nonce'],
            'HTTP_X_VT_KEY_ID'     => $headers['key_id'],
            'HTTP_X_VT_SIGNATURE'  => $headers['signature'],
        ], content: $body);
    }

    private function call(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $controller = new RemoteStateController(new PushedUpdateHandler(
            new InboundRequestAuthenticator($this->install->keys, $this->install->signature),
            $this->install->opener,
            $this->install->inspector,
            $this->install->store,
            $this->install->journal,
            $this->install->reader,
            $this->install->runtimeState,
        ));

        return $controller($request);
    }
}
