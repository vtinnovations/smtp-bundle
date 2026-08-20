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
 * Checks a detached signature over exact bytes.
 *
 * The allowlist is the point: an algorithm this build does not name is refused outright rather than
 * negotiated. Otherwise a packet could nominate something weak — or nothing at all — and talk its
 * way past verification without ever forging anything.
 */
final class DetachedSignature
{
    public const ED25519 = 'ed25519';

    /** @var list<string> */
    private const ALLOWED = [self::ED25519];

    public static function supports(string $algorithm): bool
    {
        return \in_array(strtolower(trim($algorithm)), self::ALLOWED, true);
    }

    /**
     * @param string $signatureB64 the signature as delivered
     * @param string $message      the exact canonical bytes it is supposed to cover
     * @param string $publicKey    raw 32-byte key, already resolved from the pinned ring
     */
    public function verify(string $signatureB64, string $message, string $publicKey, string $algorithm = self::ED25519): bool
    {
        if (!self::supports($algorithm)) {
            return false;
        }

        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            // Missing crypto is a refusal, never a pass. A build that cannot check signatures must
            // not behave as though every signature checked out.
            return false;
        }

        $signature = base64_decode(trim($signatureB64), true);

        if (false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature)) {
            return false;
        }

        if (SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($publicKey)) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Tries every candidate key, for the record document — which names no key of its own, so the
     * only way to honour a rotation is to try each one currently trusted.
     *
     * @param list<string> $publicKeys
     */
    public function verifyWithAny(string $signatureB64, string $message, array $publicKeys, string $algorithm = self::ED25519): bool
    {
        foreach ($publicKeys as $key) {
            if ($this->verify($signatureB64, $message, $key, $algorithm)) {
                return true;
            }
        }

        return false;
    }
}
