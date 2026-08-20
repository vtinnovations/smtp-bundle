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

/**
 * What the stored record entitles this installation to, decided entirely offline.
 *
 * Immutable and shared, so every gate reasons about the same answer — but it is only *input* to
 * those gates. Nothing flips this object; a protected operation asks for it and refuses on its own
 * behalf, so there is no one flag to set and no one service to unregister that opens everything at
 * once.
 *
 * `reason` is a machine code for internal diagnosis and for choosing what to tell an admin. It is
 * never rendered raw.
 */
final readonly class Entitlement
{
    public const OK                   = 'ok';
    public const NO_STATE             = 'no_state';
    public const KEY_STORE_EMPTY      = 'signing_key_store_empty';
    public const SEAL_BROKEN          = 'seal_broken';
    public const MALFORMED            = 'malformed';
    public const BAD_SIGNATURE        = 'bad_signature';
    public const WRONG_PRODUCT        = 'wrong_product';
    public const SCHEMA_UNSUPPORTED   = 'schema_unsupported';
    public const REFRESH_REQUIRED     = 'refresh_required';
    public const DOMAIN_MISMATCH      = 'domain_mismatch';
    public const NO_CONFIGURED_DOMAIN = 'no_configured_domain';
    public const NOT_STARTED          = 'not_started';
    public const MODEL_INCOMPATIBLE   = 'model_incompatible';
    public const REVOKED              = 'revoked';

    /**
     * @param list<string> $features
     * @param list<string> $boundHosts the exact hostnames the record authorises, as signed
     */
    private function __construct(
        public bool $granted,
        public string $reason,
        public string $tier = '',
        public string $package = '',
        public array $features = [],
        public ?int $expiresAt = null,
        public int $version = 0,
        public string $matchedHost = '',
        public array $boundHosts = [],
        public int $maxHosts = 0,
        public ?int $startsAt = null,
    ) {
    }

    /**
     * The document carries a package code but no tier, so the free/paid split is derived here once
     * rather than at every call site.
     *
     * @param list<string> $features
     * @param list<string> $boundHosts
     */
    public static function granted(
        string $package,
        array $features,
        ?int $expiresAt,
        int $version,
        string $matchedHost,
        array $boundHosts,
        int $maxHosts,
        ?int $startsAt = null,
        string $reason = self::OK,
    ): self {
        return new self(
            true,
            $reason,
            'free' === $package ? 'free' : 'paid',
            $package,
            $features,
            $expiresAt,
            $version,
            $matchedHost,
            $boundHosts,
            $maxHosts,
            $startsAt,
        );
    }

    public static function withheld(string $reason, int $version = 0): self
    {
        return new self(false, $reason, version: $version);
    }

    public function isFree(): bool
    {
        return 'free' === $this->tier;
    }

    public function isPaid(): bool
    {
        return 'paid' === $this->tier;
    }

    public function hasFeature(string $feature): bool
    {
        return $this->granted && \in_array($feature, $this->features, true);
    }
}
