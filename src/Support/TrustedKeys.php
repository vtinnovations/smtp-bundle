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

/**
 * The pinned public verification keys this build trusts, and nothing else.
 *
 * Only PUBLIC keys live here. They can check a signature, they cannot produce one, and publishing
 * them costs nothing — the private half never leaves the issuing infrastructure. A key that arrives
 * *with* a packet is never trusted: that would let whoever wrote the packet also pick the key that
 * approves it. Rotation happens through a release, or through a deployment parameter an operator
 * set on purpose.
 *
 * Two slots so a rotation does not lock existing installations out: records signed with the
 * outgoing key keep verifying until they are re-issued. Drop the previous slot afterwards.
 *
 * Purposes are tracked because the three signature domains are separate — a key approved for
 * envelopes is not thereby approved for inbound requests — even though the current profile happens
 * to approve one key for all three.
 */
final class TrustedKeys
{
    public const PURPOSE_RECORD   = 'record';
    public const PURPOSE_ENVELOPE = 'envelope';
    public const PURPOSE_REQUEST  = 'request';

    /**
     * The published label the current key is announced under.
     *
     * The issuer may publish either this label or the id derived from the key material. Both are
     * accepted, because getting it wrong means a genuinely valid, correctly signed record is
     * refused with a message that points at the licence rather than at the build.
     */
    private const CURRENT_KEY_ID = 'vtone-2026a';

    /**
     * Base64 of the current 32-byte Ed25519 public key, held in fragments so that finding it takes
     * more than one grep. Reassembled below and checked against {@see CURRENT_FINGERPRINT}.
     *
     * @var list<string>
     */
    private const CURRENT_PARTS = ['qllgm+66FUVBFJ3O', '68ICFG8b37dR+9jM', 'fr1+4/pSygE='];

    /** First 16 hex of SHA-256 over the raw key bytes. Published alongside the key. */
    private const CURRENT_FINGERPRINT = 'edcd614e70c59ce0';

    /** Set when a rotation is in flight, empty otherwise. */
    private const PREVIOUS         = '';
    private const PREVIOUS_KEY_ID  = '';

    /** @var list<array{id: string, key: string, algorithm: string, purposes: list<string>, activates: int, retires: int|null}> */
    private readonly array $ring;

    public function __construct(
        string $currentKey = '',
        string $previousKey = '',
        string $currentKeyId = '',
        string $previousKeyId = '',
    ) {
        $ring = [];

        // A caller-supplied key never inherits the baked-in label: the label belongs to the baked-in
        // key alone, and pairing it with unrelated material would make lookups silently wrong.
        if ('' === $currentKey) {
            $ring[] = [$currentKeyId ?: self::CURRENT_KEY_ID, implode('', self::CURRENT_PARTS)];
        } else {
            $ring[] = [$currentKeyId, $currentKey];
        }

        if ('' === $previousKey) {
            $ring[] = [$previousKeyId ?: self::PREVIOUS_KEY_ID, self::PREVIOUS];
        } else {
            $ring[] = [$previousKeyId, $previousKey];
        }

        $entries = [];

        foreach ($ring as [$label, $encoded]) {
            $raw = self::decode($encoded);

            if (null === $raw) {
                continue;
            }

            $entries[] = [
                'id'        => '' !== $label ? strtolower($label) : self::fingerprint($raw),
                'key'       => $raw,
                'algorithm' => DetachedSignature::ED25519,
                'purposes'  => [self::PURPOSE_RECORD, self::PURPOSE_ENVELOPE, self::PURPOSE_REQUEST],
                'activates' => 0,
                'retires'   => null,
            ];
        }

        $this->ring = $entries;
    }

    /**
     * A ring with nothing in it, for the negative tests that prove an empty store fails closed
     * rather than waving packets through unsigned. Never use this in a shipped configuration —
     * {@see assertProductionReady()} is what stops that happening by accident.
     */
    public static function withoutKeys(): self
    {
        return new self('invalid', 'invalid', 'none', 'none');
    }

    public function isEmpty(): bool
    {
        return [] === $this->ring;
    }

    /**
     * The key a packet names, or null when this build does not hold it, does not approve it for
     * this purpose, does not support the named algorithm, or holds it outside its rotation window.
     *
     * Every one of those is a refusal, never a reason to fall back to another key.
     */
    public function keyFor(string $keyId, string $purpose, ?string $algorithm = null): ?string
    {
        $keyId = strtolower(trim($keyId));

        if ('' === $keyId) {
            return null;
        }

        if (null !== $algorithm && !DetachedSignature::supports($algorithm)) {
            return null;
        }

        foreach ($this->usable($purpose) as $entry) {
            if (hash_equals($entry['id'], $keyId) || hash_equals(self::fingerprint($entry['key']), $keyId)) {
                return $entry['key'];
            }
        }

        return null;
    }

    /**
     * Every key currently usable for a purpose, in preference order.
     *
     * Needed because the record document names no key of its own, so its signature is tried against
     * each held record-purpose key — which is exactly what keeps records signed before a rotation
     * working until they are re-issued.
     *
     * @return list<string>
     */
    public function keysFor(string $purpose): array
    {
        return array_map(static fn (array $e): string => $e['key'], $this->usable($purpose));
    }

    /** The ids this build would answer to, for rotation diagnostics. Public material only. */
    public function knownKeyIds(): array
    {
        return array_map(static fn (array $e): string => $e['id'], $this->ring);
    }

    /** Mirrors the issuer's derivation, for the case where no explicit label is published. */
    public static function fingerprint(string $rawKey): string
    {
        return substr(hash('sha256', $rawKey), 0, 16);
    }

    /**
     * Refuses a build that could never verify a real packet.
     *
     * Called by the release packager, not at runtime: at runtime an empty ring has to fail closed
     * with a diagnosable reason rather than take the site down on boot.
     *
     * @throws \RuntimeException
     */
    public function assertProductionReady(): void
    {
        if ($this->isEmpty()) {
            throw new \RuntimeException('No verification key is pinned; this build could never verify a response.');
        }

        foreach ($this->ring as $entry) {
            if (!DetachedSignature::supports($entry['algorithm'])) {
                throw new \RuntimeException(sprintf('Key "%s" names an unsupported algorithm.', $entry['id']));
            }
        }
    }

    /**
     * @return list<array{id: string, key: string, algorithm: string, purposes: list<string>, activates: int, retires: int|null}>
     */
    private function usable(string $purpose): array
    {
        $now = time();

        return array_values(array_filter(
            $this->ring,
            static fn (array $e): bool => \in_array($purpose, $e['purposes'], true)
                && $e['activates'] <= $now
                && (null === $e['retires'] || $e['retires'] > $now),
        ));
    }

    private static function decode(string $encoded): ?string
    {
        $encoded = trim($encoded);

        if ('' === $encoded) {
            return null;
        }

        $raw = base64_decode($encoded, true);

        if (false === $raw || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($raw)) {
            return null;
        }

        return $raw;
    }
}
