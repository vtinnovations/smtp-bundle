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
use Vtinnovations\SmtpBundle\Support\CanonicalForm;
use Vtinnovations\SmtpBundle\Support\DetachedSignature;
use Vtinnovations\SmtpBundle\Support\TrustedKeys;

/**
 * Reads a record document and decides what it entitles this installation to — with no network
 * access at all.
 *
 * That is the point of the signed model: the common path never asks anyone anything, and the
 * document still cannot be forged, because only the issuer's private key produces a signature that
 * matches its contents. Editing the file — pushing the expiry out, adding a hostname, upgrading the
 * package — breaks the signature, and there is no checksum to recompute that would help.
 *
 * Order matters. Cheap structural checks first, the signature before any field is trusted, and
 * every field read afterwards is one the signature covers.
 */
final class RecordInspector
{
    public function __construct(
        private readonly CanonicalForm $canonicalForm,
        private readonly TrustedKeys $keys,
        private readonly DetachedSignature $signature,
        private readonly HostInventory $hosts,
    ) {
    }

    /**
     * @param string|null $expectedHost when given, the document must name exactly this host — used
     *                                  after an exchange so a package issued for one hostname
     *                                  cannot be accepted as an answer about another
     */
    public function inspect(string $bytes, ?string $expectedHost = null): Entitlement
    {
        $document = $this->authenticatedDocument($bytes);

        if (null === $document) {
            return Entitlement::withheld($this->documentFailure($bytes));
        }

        // Everything below is trustworthy, because it is covered by the signature.

        if (DeploymentProfile::SCHEMA_VERSION !== ($document['schema_version'] ?? null)) {
            return Entitlement::withheld(Entitlement::SCHEMA_UNSUPPORTED);
        }

        if ('valid' !== (string) ($document['validation_status'] ?? '')) {
            return Entitlement::withheld(Entitlement::MALFORMED);
        }

        if (DeploymentProfile::PROJECT_SLUG !== (string) ($document['project_slug'] ?? '')
            || DeploymentProfile::PROJECT !== (string) ($document['project'] ?? '')
        ) {
            return Entitlement::withheld(Entitlement::WRONG_PRODUCT);
        }

        $version = \is_int($document['license_version'] ?? null) ? $document['license_version'] : 0;

        // A record issued before the host set became part of the signed state cannot be widened
        // here — inventing the missing fields locally is exactly the forgery the signature exists to
        // prevent. It stays on disk as rollback material and a refresh is required.
        if (!isset($document['license_domains'], $document['license_max_domains'])) {
            return Entitlement::withheld(Entitlement::REFRESH_REQUIRED, $version);
        }

        $bound = $this->boundHosts($document['license_domains']);

        if (null === $bound) {
            return Entitlement::withheld(Entitlement::MALFORMED, $version);
        }

        $maxHosts = $document['license_max_domains'];

        if (!\is_int($maxHosts) || $maxHosts < 1) {
            return Entitlement::withheld(Entitlement::MALFORMED, $version);
        }

        $operationHost = (string) ($document['license_domain'] ?? '');

        // Representation must already be canonical: normalizing it here and then comparing would
        // accept a document whose signed spelling differs from the one being authorised.
        if ('' === $operationHost || $this->hosts->normalize($operationHost) !== $operationHost) {
            return Entitlement::withheld(Entitlement::MALFORMED, $version);
        }

        if (!\in_array($operationHost, $bound, true)) {
            return Entitlement::withheld(Entitlement::DOMAIN_MISMATCH, $version);
        }

        if (null !== $expectedHost && $operationHost !== $expectedHost) {
            return Entitlement::withheld(Entitlement::DOMAIN_MISMATCH, $version);
        }

        $configured = $this->hosts->configuredHosts();

        if ([] === $configured) {
            return Entitlement::withheld(Entitlement::NO_CONFIGURED_DOMAIN, $version);
        }

        // The activation predicate: one exact hostname present both in what this installation is
        // configured to be and in what the issuer signed. Never a suffix, a parent, a wildcard or an
        // apex/`www` equivalence — `license_max_domains` of 9999 does not authorise a host either,
        // it only reports an allowance.
        $matched = $this->hosts->matchedHost($bound);

        if (null === $matched) {
            return Entitlement::withheld(Entitlement::DOMAIN_MISMATCH, $version);
        }

        $startsAt = $document['license_starts_at'] ?? null;

        if (\is_int($startsAt) && $startsAt > time()) {
            return Entitlement::withheld(Entitlement::NOT_STARTED, $version);
        }

        $package   = (string) ($document['license_package'] ?? '');
        $lifetime  = true === ($document['license_lifetime'] ?? false);
        $expiresAt = $document['license_expires_at'] ?? null;

        // A missing expiry counts as perpetual only when the document says so outright. Without
        // this, stripping the expiry would read as "never expires" — so the two cases are
        // deliberately not allowed to share one representation.
        if (null === $expiresAt) {
            if (!$lifetime) {
                return Entitlement::withheld(Entitlement::MALFORMED, $version);
            }
        } elseif (!\is_int($expiresAt)) {
            return Entitlement::withheld(Entitlement::MALFORMED, $version);
        }

        // This build is issued under the Lifetime Free model only: the one accepted package is the
        // product's free identifier, granted permanently, with no expiry. A time-limited free
        // package, a paid tier or a trial belongs to a different licence model, and is refused here
        // even though it is genuinely signed — the same way a wrong host or wrong product is
        // refused. There is no fallback to compute: nothing survives a record this build will not
        // grant in the first place.
        if ('free' !== $package || !$lifetime || null !== $expiresAt) {
            return Entitlement::withheld(Entitlement::MODEL_INCOMPATIBLE, $version);
        }

        $features = array_values(array_filter(
            (array) ($document['license_features'] ?? []),
            static fn ($f): bool => \is_string($f),
        ));

        // Reported, not enforced: the "not yet started" case was already refused above. It exists so
        // the settings section can show the administrator when the record became valid.
        return Entitlement::granted(
            $package,
            $features,
            null,
            $version,
            $matched,
            $bound,
            $maxHosts,
            \is_int($startsAt) ? $startsAt : null,
        );
    }

    /**
     * The document, parsed, but only once its own signature checks out.
     *
     * Separate from {@see inspect()} because the full key is legitimately readable from an authentic
     * record whose entitlement is nonetheless withheld — an expired or wrong-host record is still a
     * genuine one. Nothing else may read the key.
     *
     * @return array<string, mixed>|null
     */
    public function authenticatedDocument(string $bytes): ?array
    {
        if ($this->keys->isEmpty()) {
            return null;
        }

        try {
            $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($document) || !isset($document['signature']) || !\is_string($document['signature'])) {
            return null;
        }

        try {
            $canonical = $this->canonicalForm->of($document);
        } catch (\JsonException) {
            return null;
        }

        // The document names no key, so every currently trusted record key is tried. That is what
        // keeps records signed before a rotation working until they are re-issued.
        if (!$this->signature->verifyWithAny(
            $document['signature'],
            $canonical,
            $this->keys->keysFor(TrustedKeys::PURPOSE_RECORD),
        )) {
            return null;
        }

        return $document;
    }

    /** Tells the three reasons a document can fail before it is trusted apart, for diagnosis. */
    private function documentFailure(string $bytes): string
    {
        if ($this->keys->isEmpty()) {
            return Entitlement::KEY_STORE_EMPTY;
        }

        $decoded = json_decode($bytes, true);

        if (!\is_array($decoded) || !isset($decoded['signature']) || !\is_string($decoded['signature'])) {
            return Entitlement::MALFORMED;
        }

        return Entitlement::BAD_SIGNATURE;
    }

    /**
     * The signed host set, validated as already canonical.
     *
     * Sorting or de-duplicating it here would paper over a list that arrived in a state the issuer
     * would not have signed, so a list that is not already sorted and unique is refused instead.
     *
     * @return list<string>|null
     */
    private function boundHosts(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value) || [] === $value) {
            return null;
        }

        foreach ($value as $host) {
            if (!\is_string($host) || $this->hosts->normalize($host) !== $host) {
                return null;
            }
        }

        if (\count(array_unique($value)) !== \count($value)) {
            return null;
        }

        $sorted = $value;
        sort($sorted, SORT_STRING);

        if ($sorted !== $value) {
            return null;
        }

        return $value;
    }
}
