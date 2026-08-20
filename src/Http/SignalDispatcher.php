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
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;

/**
 * The two fire-and-forget signals, queued during a request and sent after the response has gone
 * out.
 *
 * They are separate event shapes on purpose and are never merged into one broader packet:
 *
 *   invocation   {"project": …, "domain": …}   at most once per relevant invocation, no key
 *   module entry {"domain": …, "key": …}       once per authenticated backend session
 *
 * Both are server-to-server. Neither reads its response, neither affects entitlement, and neither
 * is allowed to influence what an admin sees. A failure is silence: the module still renders, the
 * record is still valid, and nothing is retried within the same session.
 *
 * The transport is Symfony's HTTP client, which uses native cURL when ext-curl is present and falls
 * back to a stream implementation otherwise. Either way the same controls are applied explicitly:
 * fixed host, TLS verification on, redirects refused, short timeouts, response ignored.
 */
final class SignalDispatcher
{
    /** @var list<array<string, string>> */
    private array $queue = [];

    /** Guards the per-invocation event, which is once per process by definition. */
    private bool $invocationQueued = false;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function queueInvocation(string $host): void
    {
        if ($this->invocationQueued || '' === $host) {
            return;
        }

        $this->invocationQueued = true;

        // Exactly these two fields. The key is not part of this event and must never be added to it.
        $this->queue[] = ['project' => DeploymentProfile::PROJECT, 'domain' => $host];
    }

    /**
     * The one event permitted to carry the full key.
     *
     * The caller must already have claimed the session slot before calling, so a transport failure
     * here cannot turn into a second attempt later in the same session.
     */
    public function queueModuleEntry(string $host, string $key): void
    {
        if ('' === $host || '' === $key) {
            return;
        }

        $this->queue[] = ['domain' => $host, 'key' => $key];
    }

    public function hasQueued(): bool
    {
        return [] !== $this->queue;
    }

    /** Sends whatever is queued and forgets it, whatever happened. */
    public function flush(): void
    {
        $queued      = $this->queue;
        $this->queue = [];

        foreach ($queued as $payload) {
            $this->send($payload);
        }
    }

    /** @param array<string, string> $payload */
    private function send(array $payload): void
    {
        try {
            $response = $this->client->request('POST', DeploymentProfile::signalEndpoint(), [
                'json'          => $payload,
                'timeout'       => DeploymentProfile::SIGNAL_TIMEOUT_SECONDS,
                'max_duration'  => DeploymentProfile::SIGNAL_TIMEOUT_SECONDS,
                'max_redirects' => 0,
                'verify_peer'   => true,
                'verify_host'   => true,
            ]);

            // Touch the status so the request is actually issued, then discard everything else.
            // Nothing about the body is read, parsed, stored or acted on.
            $response->getStatusCode();
        } catch (\Throwable) {
            // Silent by design. The exception is not logged either: for the module-entry shape its
            // context could carry the request, and the request carries the key.
            $this->logger?->debug('Signal delivery did not complete.');
        }
    }
}
