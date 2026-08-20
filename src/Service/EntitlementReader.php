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

use Vtinnovations\SmtpBundle\Config\RuntimeState;
use Vtinnovations\SmtpBundle\Exception\PackageRejectedException;
use Vtinnovations\SmtpBundle\Storage\RecordStore;
use Vtinnovations\SmtpBundle\Support\PackageOpener;

/**
 * The one place the stored state is turned into an answer, computed once per request.
 *
 * It fails closed and there is no cached-result fallback: any such cache would live in a file the
 * site owner can write, so honouring it would hand whoever deletes the record exactly the bypass
 * the signature exists to close.
 *
 * The seal is re-checked on every read, not only on delivery. That is what catches a record edited
 * in place, and a genuine record parked next to an envelope that describes different bytes.
 */
final class EntitlementReader
{
    private ?Entitlement $cached = null;

    public function __construct(
        private readonly RecordStore $store,
        private readonly PackageOpener $opener,
        private readonly RecordInspector $inspector,
        private readonly RuntimeState $runtimeState,
    ) {
    }

    public function isGranted(): bool
    {
        return $this->current()->granted;
    }

    public function current(): Entitlement
    {
        if (null !== $this->cached) {
            return $this->cached;
        }

        return $this->cached = $this->evaluate();
    }

    /**
     * The revision on disk, judged only by the seal — not by whether it is currently entitling
     * anything.
     *
     * Rollback prevention has to work even when the record is refused for an unrelated reason: a
     * record that is expired, or bound to a host this node is not serving, is still newer than the
     * one someone is trying to replay in its place.
     */
    public function installedVersion(): int
    {
        $stored = $this->store->read();

        if (null === $stored) {
            return 0;
        }

        try {
            return $this->opener->openBytes($stored['bytes'], $stored['envelope'])->version;
        } catch (PackageRejectedException) {
            return 0;
        }
    }

    /**
     * The full key, and only from a record whose signature checks out.
     *
     * Exists for the one signal that is allowed to carry it. Never for logs, never for rendering,
     * never for anything a browser can reach.
     */
    public function authenticatedKey(): ?string
    {
        $stored = $this->store->read();

        if (null === $stored) {
            return null;
        }

        try {
            $sealed = $this->opener->openBytes($stored['bytes'], $stored['envelope']);
        } catch (PackageRejectedException) {
            return null;
        }

        $document = $this->inspector->authenticatedDocument($sealed->bytes);
        $key      = \is_array($document) ? (string) ($document['license_key'] ?? '') : '';

        return '' === $key ? null : $key;
    }

    /** The host the record was matched against, for work that runs without a request. */
    public function matchedHost(): string
    {
        $current = $this->current();

        return '' !== $current->matchedHost ? $current->matchedHost : $this->runtimeState->matchedHost();
    }

    /** Forces the next read to go back to disk. Called after the state on disk changes. */
    public function reset(): void
    {
        $this->cached = null;
    }

    private function evaluate(): Entitlement
    {
        $stored = $this->store->read();

        if (null === $stored) {
            return Entitlement::withheld(Entitlement::NO_STATE);
        }

        try {
            $sealed = $this->opener->openBytes($stored['bytes'], $stored['envelope']);
        } catch (PackageRejectedException $e) {
            return Entitlement::withheld(
                PackageRejectedException::KEY_STORE_EMPTY === $e->reason
                    ? Entitlement::KEY_STORE_EMPTY
                    : Entitlement::SEAL_BROKEN,
            );
        }

        $entitlement = $this->inspector->inspect($sealed->bytes);

        // An explicit refusal from the issuer closes the gate without destroying the record. The
        // refusal itself arrives as ordinary unsigned JSON, so it is not allowed to delete
        // authenticated state — it only withholds, and the next successful refresh clears it.
        if ($entitlement->granted && $this->runtimeState->isRefused()) {
            return Entitlement::withheld(Entitlement::REVOKED, $entitlement->version);
        }

        return $entitlement;
    }
}
