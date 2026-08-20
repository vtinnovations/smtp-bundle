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

/**
 * What came back from an activation or refresh, reduced to the three outcomes that lead to
 * different behaviour.
 *
 * The distinction that matters is UNAVAILABLE versus DENIED. A timeout, a TLS failure, an
 * unreadable answer or a 5xx says nothing about this installation's standing, so the state it
 * already holds must survive untouched. Only an answer that actually refuses the key is a refusal.
 *
 * No text from the remote side is carried through. Messages shown to an admin are chosen locally
 * from the reason code, so a remote string can never end up rendered in the backend.
 */
final readonly class ExchangeResult
{
    public const VALID       = 'valid';
    public const DENIED      = 'denied';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param array<string, mixed> $envelope
     */
    private function __construct(
        public string $outcome,
        public string $payloadB64 = '',
        public array $envelope = [],
        public string $reason = '',
    ) {
    }

    /** @param array<string, mixed> $envelope */
    public static function valid(string $payloadB64, array $envelope): self
    {
        return new self(self::VALID, $payloadB64, $envelope);
    }

    public static function denied(string $reason): self
    {
        return new self(self::DENIED, reason: $reason);
    }

    public static function unavailable(string $reason): self
    {
        return new self(self::UNAVAILABLE, reason: $reason);
    }

    public function isValid(): bool
    {
        return self::VALID === $this->outcome;
    }

    public function isDenied(): bool
    {
        return self::DENIED === $this->outcome;
    }
}
