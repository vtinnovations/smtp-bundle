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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Http\InboundRequestAuthenticator;
use Vtinnovations\SmtpBundle\Http\ProvisioningClient;
use Vtinnovations\SmtpBundle\Service\ProvisioningService;
use Vtinnovations\SmtpBundle\Service\PushedUpdateHandler;
use Vtinnovations\SmtpBundle\Tests\Fixture\CapturingLogger;
use Vtinnovations\SmtpBundle\Tests\Fixture\Installation;

/**
 * Ordinary logs may say what happened. They may not say what was in it.
 *
 * Redacting only the key does not make a packet dump safe: a nonce, a checksum, a signature or a
 * body hash is all authentication material or a fingerprint of it, and a log file is a far softer
 * target than the exchange it describes. So the captured logger is checked against both the actual
 * secret values and the field names they would travel under.
 */
final class PacketSecrecyTest extends TestCase
{
    private Installation $install;
    private CapturingLogger $logger;

    protected function setUp(): void
    {
        $this->install = new Installation();
        $this->logger  = new CapturingLogger();
    }

    protected function tearDown(): void
    {
        $this->install->cleanUp();
    }

    public function testASuccessfulExchangeLogsOnlySafeMetadata(): void
    {
        $package = $this->install->factory->package();

        $this->exchangeWith($package)->activate('AAAAA-BBBBB-CCCCC-DDDDD');

        $this->assertNothingSensitiveWasLogged($package, 'AAAAA-BBBBB-CCCCC-DDDDD');
        self::assertNotSame('', $this->logger->flatten(), 'Something operational should still be recorded.');
    }

    public function testARefusedExchangeLogsNothingSensitive(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);

            return new MockResponse(
                (string) json_encode([
                    'status'     => 'invalid',
                    'request_id' => $body['request_id'] ?? '',
                    'message'    => 'internal detail /var/www/secret/path.php',
                ]),
                ['http_code' => 403, 'response_headers' => ['content-type' => 'application/json']],
            );
        });

        $this->service($client)->activate('AAAAA-BBBBB-CCCCC-DDDDD');

        $flat = $this->logger->flatten();

        self::assertStringNotContainsString('AAAAA-BBBBB-CCCCC-DDDDD', $flat);
        self::assertStringNotContainsString('/var/www/secret/path.php', $flat);
    }

    public function testATransportFailureLogsNoInternalDetail(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('could not connect to 10.0.0.1 via /var/run/proxy.sock');
        });

        $this->service($client)->activate('AAAAA-BBBBB-CCCCC-DDDDD');

        $flat = $this->logger->flatten();

        // A raw remote or transport error carries internal paths and stack traces with it.
        self::assertStringNotContainsString('10.0.0.1', $flat);
        self::assertStringNotContainsString('/var/run/proxy.sock', $flat);
    }

    public function testAnAppliedPushLogsOnlySafeMetadata(): void
    {
        $this->install->install();

        $package = $this->install->factory->package(['license_version' => 9]);
        $body    = $this->pushBody($package);
        $headers = $this->install->factory->requestHeaders('POST', DeploymentProfile::updaterPath(), $body, [
            'request_id' => json_decode($body, true)['request_id'],
            'nonce'      => json_decode($body, true)['nonce'],
            'timestamp'  => json_decode($body, true)['timestamp'],
        ]);

        $this->pushHandler()->handle('POST', DeploymentProfile::updaterPath(), $headers, $body);

        $this->assertNothingSensitiveWasLogged($package, 'AAAAA-BBBBB-CCCCC-DDDDD', $headers, $body);
    }

    public function testARefusedPushLogsOnlySafeMetadata(): void
    {
        $this->install->install();

        $package = $this->install->factory->package(['license_version' => 9]);
        $body    = $this->pushBody($package);
        $headers = $this->install->factory->requestHeaders('POST', DeploymentProfile::updaterPath(), $body, ['signature' => 'forged']);

        $this->pushHandler()->handle('POST', DeploymentProfile::updaterPath(), $headers, $body);

        $this->assertNothingSensitiveWasLogged($package, 'AAAAA-BBBBB-CCCCC-DDDDD', $headers, $body);
    }

    /**
     * @dataProvider forbiddenContextKeys
     */
    public function testForbiddenContextKeysAreNeverEmitted(string $key): void
    {
        $this->install->install();

        $this->exchangeWith($this->install->factory->package())->refresh();

        foreach ($this->logger->records as $record) {
            self::assertArrayNotHasKey($key, $record['context'], sprintf('"%s" must never be logged.', $key));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenContextKeys(): iterable
    {
        foreach ([
            'request_packet', 'response_packet', 'request_body', 'response_body', 'body', 'nonce',
            'license_payload_b64', 'license_md5', 'signature', 'request_sha256', 'response_sha256',
            'licence_key_sha256', 'license_key_sha256', 'licence_key_length', 'license_key_length',
            'license_key', 'key', 'exception', 'trace',
        ] as $key) {
            yield $key => [$key];
        }
    }

    /**
     * A static sweep over the shipped source, so a future logger call cannot quietly reintroduce
     * what the runtime tests above only observe on the paths they happen to exercise.
     *
     * @dataProvider forbiddenContextKeys
     */
    public function testTheSourceNeverBuildsALoggerContextWithAForbiddenKey(string $key): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (!str_contains($contents, 'logger')) {
                continue;
            }

            // A logger context is an array literal, so the key would appear as a quoted array key.
            if (preg_match('/->(?:log|debug|info|notice|warning|error|critical|alert|emergency)\s*\([^;]*[\'"]'.preg_quote($key, '/').'[\'"]\s*=>/s', $contents)) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, sprintf('"%s" appears in a logger context in: %s', $key, implode(', ', $offenders)));
    }

    // --- helpers -----------------------------------------------------------------------------

    /**
     * @param array{bytes: string, payload_b64: string, envelope: array<string, mixed>} $package
     * @param array<string, string>                                                     $headers
     */
    private function assertNothingSensitiveWasLogged(array $package, string $licenceKey, array $headers = [], string $body = ''): void
    {
        $flat = $this->logger->flatten();

        $secrets = [
            'licence key'      => $licenceKey,
            'raw payload'      => $package['payload_b64'],
            'record bytes'     => $package['bytes'],
            'checksum'         => $package['envelope']['license_md5'],
            'record signature' => (string) json_decode($package['bytes'], true)['signature'],
            'envelope sig'     => $package['envelope']['signature'],
        ];

        if ([] !== $headers) {
            $secrets['nonce']             = $headers['nonce'];
            $secrets['request signature'] = $headers['signature'];
        }

        if ('' !== $body) {
            $secrets['request body'] = $body;
            $secrets['body hash']    = hash('sha256', $body);
        }

        foreach ($secrets as $label => $secret) {
            if ('' === $secret) {
                continue;
            }

            self::assertStringNotContainsString($secret, $flat, $label.' must not reach the log');
        }
    }

    /**
     * @param array{bytes: string, payload_b64: string, envelope: array<string, mixed>} $package
     */
    private function pushBody(array $package): string
    {
        return (string) json_encode([
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
        ]);
    }

    private function pushHandler(): PushedUpdateHandler
    {
        return new PushedUpdateHandler(
            new InboundRequestAuthenticator($this->install->keys, $this->install->signature),
            $this->install->opener,
            $this->install->inspector,
            $this->install->store,
            $this->install->journal,
            $this->install->reader,
            $this->install->runtimeState,
            $this->logger,
        );
    }

    /**
     * @param array{bytes: string, payload_b64: string, envelope: array<string, mixed>} $package
     */
    private function exchangeWith(array $package): ProvisioningService
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($package): MockResponse {
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
        });

        return $this->service($client);
    }

    private function service(MockHttpClient $client): ProvisioningService
    {
        return new ProvisioningService(
            new ProvisioningClient($client, $this->logger),
            $this->install->opener,
            $this->install->inspector,
            $this->install->store,
            $this->install->runtimeState,
            $this->install->reader,
            $this->install->hosts,
        );
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $root  = \dirname(__DIR__, 3).'/src';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
