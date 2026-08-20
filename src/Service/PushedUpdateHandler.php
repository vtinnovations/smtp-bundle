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

namespace Vtinnovations\SmtpBundle\Service;

use Psr\Log\LoggerInterface;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Config\RuntimeState;
use Vtinnovations\SmtpBundle\Exception\PackageRejectedException;
use Vtinnovations\SmtpBundle\Http\InboundRequestAuthenticator;
use Vtinnovations\SmtpBundle\Storage\ExchangeJournal;
use Vtinnovations\SmtpBundle\Storage\RecordStore;
use Vtinnovations\SmtpBundle\Support\PackageOpener;

/**
 * The inbound half: the issuer pushes a change instead of waiting to be asked.
 *
 * Everything the controller is not allowed to know lives here — authentication, replay, idempotency,
 * identity, ordering and the atomic swap. The controller only enforces the shape of the request.
 *
 * The idempotency rules are the subtle part:
 *
 *   - the same request id carrying the same authenticated body is a retry. It gets the same answer
 *     and does no work.
 *   - the same request id carrying a *different* authenticated body is not a retry. It is someone
 *     reusing an id that was already accepted, and it is refused.
 *   - a nonce seen before is refused regardless of its id.
 *
 * And ordering: an older or equal revision is refused even though it is genuinely signed, because
 * replaying yesterday's record is exactly how a downgrade would be staged.
 */
final class PushedUpdateHandler
{
    public function __construct(
        private readonly InboundRequestAuthenticator $authenticator,
        private readonly PackageOpener $opener,
        private readonly RecordInspector $inspector,
        private readonly RecordStore $store,
        private readonly ExchangeJournal $journal,
        private readonly EntitlementReader $reader,
        private readonly RuntimeState $runtimeState,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, string> $headers the five X-VT-* values, already read from the request
     */
    public function handle(string $method, string $path, array $headers, string $body): PushOutcome
    {
        $requestId = $headers['request_id'] ?? '';
        $timestamp = $headers['timestamp'] ?? '';
        $nonce     = $headers['nonce'] ?? '';

        // Signature before parsing: an unauthenticated body is not worth decoding, and checking it
        // first keeps the cost of an unsigned flood down to one hash.
        if (!$this->authenticator->isAuthentic(
            $method,
            $path,
            $requestId,
            $timestamp,
            $nonce,
            $headers['key_id'] ?? '',
            $headers['signature'] ?? '',
            $body,
        )) {
            return $this->refuse('signature');
        }

        $data = json_decode($body, true);

        if (!\is_array($data)) {
            return $this->refuse('body');
        }

        // The signed headers and the signed body must agree about which request this is. Without
        // this, the journal could be marked with one id while a different one is applied.
        if (!hash_equals($requestId, (string) ($data['request_id'] ?? ''))
            || !hash_equals($nonce, (string) ($data['nonce'] ?? ''))
            || !hash_equals($timestamp, (string) ($data['timestamp'] ?? ''))
        ) {
            return $this->refuse('metadata_mismatch');
        }

        if ('license_update' !== (string) ($data['action'] ?? '')) {
            return $this->refuse('action');
        }

        if (DeploymentProfile::PROJECT !== (string) ($data['project'] ?? '')
            || DeploymentProfile::PROJECT_SLUG !== (string) ($data['project_slug'] ?? '')
            || DeploymentProfile::PRODUCT_ID !== (string) ($data['product_id'] ?? '')
        ) {
            return $this->refuse('identity');
        }

        $host = (string) ($data['domain'] ?? '');

        if ('' === $host) {
            return $this->refuse('domain');
        }

        $fingerprint = ExchangeJournal::digest($body);
        $seen        = $this->journal->find($requestId);

        if (null !== $seen) {
            if (hash_equals((string) ($seen['fingerprint'] ?? ''), $fingerprint)) {
                return $this->conclude(PushOutcome::alreadyProcessed($requestId, (int) ($seen['version'] ?? 0)));
            }

            // Same id, different content. Not a retry.
            return $this->refuse('request_id_reuse');
        }

        if ($this->journal->nonceSeen($nonce)) {
            return $this->refuse('nonce_replay');
        }

        try {
            $sealed = $this->opener->open((string) ($data['license_payload_b64'] ?? ''), \is_array($data['integrity'] ?? null) ? $data['integrity'] : []);
        } catch (PackageRejectedException $e) {
            return $this->refuse($e->reason);
        }

        // The host named in the push, the host signed into the record and one of this installation's
        // configured hosts must all be the same name.
        $candidate = $this->inspector->inspect($sealed->bytes, $host);

        if (!$candidate->granted) {
            return $this->refuse($candidate->reason);
        }

        $installedVersion = $this->reader->installedVersion();

        if ($sealed->version <= $installedVersion) {
            return $this->conclude(PushOutcome::rejected($requestId, $installedVersion));
        }

        $stored = $this->store->commit(
            $sealed->bytes,
            $sealed->envelope,
            fn (string $bytes, array $envelope): bool => $this->reinspect($bytes, $envelope, $host),
        );

        if (!$stored) {
            return $this->conclude(PushOutcome::error($requestId));
        }

        // Journalled only after the swap succeeded: marking it earlier would make a failed apply
        // look like an applied one to the next retry.
        $this->journal->record($requestId, $nonce, $fingerprint, $sealed->version, PushOutcome::UPDATED);
        $this->runtimeState->rememberSuccess($candidate->matchedHost);
        $this->reader->reset();

        return $this->conclude(PushOutcome::updated($requestId, $sealed->version));
    }

    /** @param array<string, mixed> $envelope */
    private function reinspect(string $bytes, array $envelope, string $host): bool
    {
        try {
            $sealed = $this->opener->openBytes($bytes, $envelope);
        } catch (PackageRejectedException) {
            return false;
        }

        return $this->inspector->inspect($sealed->bytes, $host)->granted;
    }

    private function refuse(string $detail): PushOutcome
    {
        return $this->conclude(PushOutcome::unauthorized($detail));
    }

    /**
     * Records the outcome with nothing from the packet in it: no body, no nonce, no payload, no
     * checksum, no signature, no key. `detail` is a fixed internal category, never a value.
     */
    private function conclude(PushOutcome $outcome): PushOutcome
    {
        $this->logger?->info('Licence push handled.', [
            'request_id' => $outcome->requestId,
            'operation'  => 'license_update',
            'result'     => $outcome->status,
            'reason'     => $outcome->detail,
            'status'     => $outcome->httpStatus,
            'version'    => $outcome->version,
        ]);

        return $outcome;
    }
}
