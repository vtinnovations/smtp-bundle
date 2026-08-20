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

use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Support\DetachedSignature;
use Vtinnovations\SmtpBundle\Support\TrustedKeys;

/**
 * Proves that an inbound push really came from the issuer.
 *
 * This installation holds no session with the issuer, so the request has to prove itself. Nothing
 * about where a request appears to come from is evidence: `Origin`, `Referer`, User-Agent, reverse
 * DNS and source IP are all attacker-controlled or attacker-reachable, and a browser origin says
 * nothing at all about a server-to-server call. The signature is the only thing that counts.
 *
 * The signatures inside the body prove the *payload* is genuine; this one proves the *delivery* is
 * — that this exact body was addressed to this exact path, at this time, once.
 *
 * The signed message is the fixed six-line form, joined with "\n" and no trailing newline:
 *
 *     POST
 *     /rest/api/v1/smtp-license-updater
 *     <X-VT-Request-ID>
 *     <X-VT-Timestamp>
 *     <X-VT-Nonce>
 *     <lowercase hex SHA-256 of the exact request body bytes>
 *
 * `X-VT-Key-ID` selects the key and is deliberately *not* one of those lines. It does not need to
 * be signed: naming a different key only sends the check to a key the signature will not verify
 * under, and naming an unknown one is refused outright.
 */
final class InboundRequestAuthenticator
{
    public function __construct(
        private readonly TrustedKeys $keys,
        private readonly DetachedSignature $signature,
    ) {
    }

    /**
     * @param string $body the raw request body exactly as received — never a re-encode
     */
    public function isAuthentic(
        string $method,
        string $path,
        string $requestId,
        string $timestamp,
        string $nonce,
        string $keyId,
        string $signatureB64,
        string $body,
    ): bool {
        if ('' === $requestId || '' === $nonce || '' === $timestamp || '' === $keyId) {
            return false;
        }

        // The path is signed, so a push aimed at a different route cannot be replayed onto this one.
        // It is also checked against the one this bundle actually serves.
        if ($path !== DeploymentProfile::updaterPath()) {
            return false;
        }

        if (!ctype_digit($timestamp)) {
            return false;
        }

        // Both directions: a request from the future is as suspect as a stale one, and allowing
        // future skew would let a captured request be parked and used later.
        if (abs(time() - (int) $timestamp) > DeploymentProfile::MAX_CLOCK_SKEW_SECONDS) {
            return false;
        }

        $key = $this->keys->keyFor($keyId, TrustedKeys::PURPOSE_REQUEST, DetachedSignature::ED25519);

        if (null === $key) {
            return false;
        }

        return $this->signature->verify(
            $signatureB64,
            $this->signedMessage($method, $path, $requestId, $timestamp, $nonce, $body),
            $key,
        );
    }

    /**
     * The exact bytes that were signed.
     *
     * Public because it is the contract with the issuer, and because that makes it testable as one
     * against a fixed vector.
     */
    public function signedMessage(string $method, string $path, string $requestId, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            strtoupper($method),
            $path,
            $requestId,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }
}
