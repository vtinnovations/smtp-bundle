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

namespace Vtinnovations\SmtpBundle\Support;

use Vtinnovations\SmtpBundle\Exception\PackageRejectedException;

/**
 * Opens a delivered package: envelope first, then the bytes it vouches for.
 *
 * The order is the whole security property. The envelope carries a checksum of the exact record
 * bytes, and a checksum proves nothing on its own — recomputing MD5 over edited bytes is trivial,
 * which is precisely the attack. So the envelope's own signature is checked first; only once the
 * envelope is known to be genuine is its checksum worth comparing against. Checking the digest
 * first and the signature afterwards would still be safe here, but it invites the next reader to
 * "optimise" the signature away, so the order is fixed and tested.
 *
 * The record's own signature is a separate matter, checked later against the parsed document — this
 * class only establishes that these bytes are the ones that were published.
 */
final class PackageOpener
{
    public function __construct(
        private readonly CanonicalForm $canonicalForm,
        private readonly TrustedKeys $keys,
        private readonly DetachedSignature $signature,
    ) {
    }

    /**
     * @param string               $payloadB64 the delivered `license_payload_b64`
     * @param array<string, mixed> $envelope   the delivered `integrity` block
     *
     * @throws PackageRejectedException
     */
    public function open(string $payloadB64, array $envelope): SealedRecord
    {
        if ($this->keys->isEmpty()) {
            // Reached the signature stage with nothing to verify against. Distinct from a bad
            // record: the remedy is provisioning the approved public key, not a new licence.
            throw new PackageRejectedException(PackageRejectedException::KEY_STORE_EMPTY);
        }

        $bytes = base64_decode(trim($payloadB64), true);

        if (false === $bytes || '' === $bytes) {
            throw new PackageRejectedException(PackageRejectedException::PAYLOAD_NOT_BASE64);
        }

        return $this->openBytes($bytes, $envelope);
    }

    /**
     * The same checks against a pair already on disk, where there is no Base64 layer.
     *
     * Run on every read, not only on delivery: it is what notices that someone edited the stored
     * record, or dropped a genuine record next to someone else's envelope.
     *
     * @param array<string, mixed> $envelope
     *
     * @throws PackageRejectedException
     */
    public function openBytes(string $bytes, array $envelope): SealedRecord
    {
        if ($this->keys->isEmpty()) {
            throw new PackageRejectedException(PackageRejectedException::KEY_STORE_EMPTY);
        }

        if ('' === $bytes) {
            throw new PackageRejectedException(PackageRejectedException::PAYLOAD_NOT_BASE64);
        }

        $keyId     = (string) ($envelope['key_id'] ?? '');
        $algorithm = (string) ($envelope['signature_algorithm'] ?? '');
        $checksum  = strtolower(trim((string) ($envelope['license_md5'] ?? '')));
        $version   = $envelope['license_version'] ?? null;

        if ('' === $keyId || '' === $algorithm || '' === $checksum || !\is_int($version)) {
            throw new PackageRejectedException(PackageRejectedException::ENVELOPE_MALFORMED);
        }

        if (!DetachedSignature::supports($algorithm)) {
            throw new PackageRejectedException(PackageRejectedException::UNKNOWN_KEY);
        }

        $key = $this->keys->keyFor($keyId, TrustedKeys::PURPOSE_ENVELOPE, $algorithm);

        if (null === $key) {
            throw new PackageRejectedException(PackageRejectedException::UNKNOWN_KEY);
        }

        try {
            $canonical = $this->canonicalForm->of($envelope);
        } catch (\JsonException) {
            throw new PackageRejectedException(PackageRejectedException::ENVELOPE_MALFORMED);
        }

        if (!$this->signature->verify((string) ($envelope['signature'] ?? ''), $canonical, $key, $algorithm)) {
            throw new PackageRejectedException(PackageRejectedException::BAD_ENVELOPE_SIG);
        }

        // Now — and only now — the checksum is worth something, because the value it is compared
        // against is one the issuer signed. Constant-time, so a mismatch leaks no timing.
        if (!hash_equals($checksum, md5($bytes))) {
            throw new PackageRejectedException(PackageRejectedException::CHECKSUM_MISMATCH);
        }

        return new SealedRecord($bytes, $envelope, $version, strtolower($keyId));
    }
}
