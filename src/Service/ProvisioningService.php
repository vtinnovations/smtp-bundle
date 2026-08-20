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

use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Config\HostInventory;
use Vtinnovations\SmtpBundle\Config\RuntimeState;
use Vtinnovations\SmtpBundle\Exception\PackageRejectedException;
use Vtinnovations\SmtpBundle\Http\ProvisioningClient;
use Vtinnovations\SmtpBundle\Storage\RecordStore;
use Vtinnovations\SmtpBundle\Support\PackageOpener;

/**
 * Turning a delivered package into installed state — the one path that is allowed to change what is
 * on disk from the outbound side.
 *
 * The rule running through all of it: the issuer saying "valid" is not enough. The bytes still have
 * to survive their envelope, their own signature, this product's identity, this installation's
 * hostnames and the revision already installed, and they are only written once all of that holds.
 * A working record is never replaced by one that has not passed everything the old one passed.
 *
 * The failure modes are deliberately not symmetric. Unreachable, unreadable or 5xx leaves existing
 * state exactly as it was — a network problem is not a licensing verdict. An explicit refusal
 * withholds entitlement but still does not delete the record, because a refusal arrives as
 * unsigned JSON and unsigned data does not get to destroy signed data.
 */
final class ProvisioningService
{
    public function __construct(
        private readonly ProvisioningClient $client,
        private readonly PackageOpener $opener,
        private readonly RecordInspector $inspector,
        private readonly RecordStore $store,
        private readonly RuntimeState $runtimeState,
        private readonly EntitlementReader $reader,
        private readonly HostInventory $hosts,
    ) {
    }

    /** An admin entered a key. */
    public function activate(string $key): ProvisioningOutcome
    {
        $key = trim($key);

        if ('' === $key) {
            return ProvisioningOutcome::of(ProvisioningOutcome::NO_KEY);
        }

        // Remembered before the exchange, so a refused key stays visible in the form for correction
        // rather than vanishing on failure.
        $this->runtimeState->rememberKey($key);

        return $this->exchange($key, ProvisioningClient::ACTIVATE, null);
    }

    /** The admin asked for an update, or the quiet re-check came due. */
    public function refresh(): ProvisioningOutcome
    {
        $key = $this->runtimeState->key();

        if ('' === $key) {
            return ProvisioningOutcome::of(ProvisioningOutcome::NO_KEY);
        }

        return $this->exchange($key, ProvisioningClient::REFRESH, $this->reader->installedVersion());
    }

    /**
     * An admin deliberately removed the licence: discard the sealed record and its own bookkeeping,
     * under the same lock a commit would take, and restore this package to its unlicensed default.
     *
     * Nothing about this is signed, and nothing here needs to be — an operator with backend access
     * to this installation is already trusted to turn the package off. What it must not do is leave
     * the two stores disagreeing: the sealed record and the remembered key are cleared together, so
     * a later read cannot land on one without the other.
     */
    public function remove(): void
    {
        $this->store->discard();
        $this->runtimeState->clear();
        $this->reader->reset();
    }

    /**
     * The quiet re-check: once a day at most, so a renewal or a tier change lands without anyone
     * re-entering anything. Silent about its result — it is a background errand, not an answer to
     * anything the admin asked.
     */
    public function refreshIfStale(): void
    {
        if ('' === $this->runtimeState->key()) {
            return;
        }

        if (!$this->runtimeState->isStale(DeploymentProfile::RECHECK_INTERVAL_SECONDS)) {
            return;
        }

        $this->refresh();
    }

    private function exchange(string $key, string $action, ?int $currentVersion): ProvisioningOutcome
    {
        $host = $this->hosts->verificationHost();

        if (null === $host) {
            // Nothing trustworthy to claim to be. Asking with a hostname taken from the request
            // would let whoever sent that request choose this installation's identity.
            return ProvisioningOutcome::of(ProvisioningOutcome::NO_CONFIGURED_DOMAIN);
        }

        $result = $this->client->exchange($key, $host, $action, $currentVersion);

        if (!$result->isValid()) {
            if ($result->isDenied()) {
                $this->runtimeState->rememberRefusal();
                $this->reader->reset();

                return ProvisioningOutcome::of(ProvisioningOutcome::REFUSED);
            }

            // Unreachable or unreadable. Existing state survives untouched.
            return ProvisioningOutcome::of(ProvisioningOutcome::UNAVAILABLE, $result->reason);
        }

        try {
            $sealed = $this->opener->open($result->payloadB64, $result->envelope);
        } catch (PackageRejectedException $e) {
            return ProvisioningOutcome::of(ProvisioningOutcome::NOT_ACCEPTED_LOCALLY, $e->reason);
        }

        // The host asked about and the host signed for must be the same one, or this is an answer
        // about a different installation.
        $candidate = $this->inspector->inspect($sealed->bytes, $host);

        if (!$candidate->granted) {
            return ProvisioningOutcome::of(ProvisioningOutcome::NOT_ACCEPTED_LOCALLY, $candidate->reason);
        }

        if ($this->wouldRollBack($sealed->version, $sealed->bytes)) {
            return ProvisioningOutcome::of(ProvisioningOutcome::ROLLBACK_REFUSED);
        }

        $installed = $this->store->commit(
            $sealed->bytes,
            $sealed->envelope,
            // Run against whatever actually landed on disk, not against what was meant to land.
            fn (string $bytes, array $envelope): bool => $this->reinspect($bytes, $envelope, $host),
        );

        if (!$installed) {
            return ProvisioningOutcome::of(ProvisioningOutcome::STORE_FAILED);
        }

        $this->runtimeState->rememberSuccess($candidate->matchedHost);
        $this->reader->reset();

        return ProvisioningOutcome::ok();
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

    /**
     * Replaying an older signed record is how a downgrade would be staged, so a lower revision is
     * refused even though it is genuinely signed.
     *
     * Scoped to the same key: entering a *different* key is a deliberate change of licence, and its
     * revision numbering has nothing to do with the previous one's.
     */
    private function wouldRollBack(int $candidateVersion, string $candidateBytes): bool
    {
        $installedVersion = $this->reader->installedVersion();

        if (0 === $installedVersion || $candidateVersion >= $installedVersion) {
            return false;
        }

        $installedKey = $this->reader->authenticatedKey();

        if (null === $installedKey) {
            return false;
        }

        $document    = $this->inspector->authenticatedDocument($candidateBytes);
        $candidateKey = \is_array($document) ? (string) ($document['license_key'] ?? '') : '';

        return '' !== $candidateKey && hash_equals($installedKey, $candidateKey);
    }
}
