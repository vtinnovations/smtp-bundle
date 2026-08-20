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

namespace Vtinnovations\SmtpBundle\Http;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;

/**
 * The outbound half of the exchange: first activation of a key, and the refresh that picks up a
 * renewal, a tier change or a changed host set.
 *
 * This only fetches. Nothing here decides anything — the answer is signed, and it is the opener and
 * the inspector that judge it. Which is why there is no caller token or shared secret: the endpoint
 * proves itself with a signature, not by recognising us.
 *
 * The destination is a fixed constant. No configuration, no response and no redirect can point it
 * somewhere else, redirects are refused outright, TLS verification stays on, the body is capped
 * before parsing and the content type is checked before anything is decoded.
 */
final class ProvisioningClient
{
    public const ACTIVATE = 'activate';
    public const REFRESH  = 'refresh';

    /** Beyond this the two clocks disagree so badly that the answer cannot be correlated safely. */
    private const MAX_SERVER_SKEW_SECONDS = 3600;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param int|null $currentVersion the revision this installation holds, sent on a refresh so
     *                                 the issuer can spot an installation running ahead of its own
     *                                 records
     */
    public function exchange(string $key, string $host, string $action = self::ACTIVATE, ?int $currentVersion = null): ExchangeResult
    {
        $requestId = bin2hex(random_bytes(16));
        $startedAt = microtime(true);

        $payload = [
            'action'       => $action,
            'project'      => DeploymentProfile::PROJECT,
            'project_slug' => DeploymentProfile::PROJECT_SLUG,
            'product_id'   => DeploymentProfile::PRODUCT_ID,
            'license_key'  => $key,
            'domain'       => $host,
            'request_id'   => $requestId,
            'timestamp'    => time(),
            'nonce'        => bin2hex(random_bytes(16)),
        ];

        if (self::REFRESH === $action && null !== $currentVersion) {
            $payload['current_license_version'] = $currentVersion;
        }

        try {
            $response = $this->client->request('POST', DeploymentProfile::exchangeEndpoint(), [
                'json'         => $payload,
                'headers'      => ['Accept' => 'application/json'],
                'timeout'      => DeploymentProfile::CONNECT_TIMEOUT_SECONDS,
                'max_duration' => DeploymentProfile::TOTAL_TIMEOUT_SECONDS,
                'max_redirects' => 0,
                'verify_peer'  => true,
                'verify_host'  => true,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 500) {
                return $this->finish($requestId, $action, ExchangeResult::unavailable('server_error'), $status, $startedAt);
            }

            $contentType = (string) ($response->getHeaders(false)['content-type'][0] ?? '');

            if (!str_starts_with(strtolower($contentType), 'application/json')) {
                // An HTML error page reached a web server, not the API. Nothing is decoded.
                return $this->finish($requestId, $action, ExchangeResult::unavailable('unexpected_media_type'), $status, $startedAt);
            }

            $body = $response->getContent(false);

            if (\strlen($body) > DeploymentProfile::MAX_RESPONSE_BYTES) {
                return $this->finish($requestId, $action, ExchangeResult::unavailable('response_too_large'), $status, $startedAt);
            }

            $data = json_decode($body, true);

            if (!\is_array($data)) {
                return $this->finish($requestId, $action, ExchangeResult::unavailable('unreadable_response'), $status, $startedAt);
            }

            // An answer that is not about the question that was asked is not an answer.
            if (!hash_equals($requestId, (string) ($data['request_id'] ?? ''))) {
                return $this->finish($requestId, $action, ExchangeResult::unavailable('request_id_mismatch'), $status, $startedAt);
            }

            $serverTime = $data['server_time'] ?? null;

            if (\is_int($serverTime) && abs(time() - $serverTime) > self::MAX_SERVER_SKEW_SECONDS) {
                return $this->finish($requestId, $action, ExchangeResult::unavailable('clock_skew'), $status, $startedAt);
            }

            // The issuer's own outage, not a verdict on this key: the installation keeps what it has.
            if (503 === $status) {
                return $this->finish($requestId, $action, ExchangeResult::unavailable('server_error'), $status, $startedAt);
            }

            if ('valid' !== (string) ($data['status'] ?? '')) {
                return $this->finish($requestId, $action, ExchangeResult::denied('refused'), $status, $startedAt);
            }

            return $this->finish(
                $requestId,
                $action,
                ExchangeResult::valid(
                    (string) ($data['license_payload_b64'] ?? ''),
                    \is_array($data['integrity'] ?? null) ? $data['integrity'] : [],
                ),
                $status,
                $startedAt,
            );
        } catch (HttpExceptionInterface) {
            // Transport, TLS or decoding trouble. The exception is deliberately not logged: its
            // message carries the raw remote answer and internal paths.
            return $this->finish($requestId, $action, ExchangeResult::unavailable('transport_error'), 0, $startedAt);
        } catch (\Throwable) {
            return $this->finish($requestId, $action, ExchangeResult::unavailable('unexpected_error'), 0, $startedAt);
        }
    }

    /**
     * Records the outcome, and only the parts of it that are safe to keep.
     *
     * Nothing from the packet goes in: no body, no nonce, no payload, no checksum, no signature, no
     * key and nothing derived from a key. What is left is enough to answer "did it work, how long
     * did it take, which request was it" and nothing else.
     */
    private function finish(string $requestId, string $action, ExchangeResult $result, int $status, float $startedAt): ExchangeResult
    {
        $this->logger?->info('Licence exchange completed.', [
            'request_id' => $requestId,
            'operation'  => $action,
            'result'     => $result->outcome,
            'reason'     => $result->reason,
            'status'     => $status,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }
}
