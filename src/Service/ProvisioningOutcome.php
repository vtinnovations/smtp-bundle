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
 * The result of an activation or refresh attempt, as a code rather than a sentence.
 *
 * The admin-facing wording is chosen from this locally, so nothing the remote side wrote ever gets
 * rendered in the backend, and the wording can stay coarse without losing the detail needed for
 * diagnosis.
 */
final readonly class ProvisioningOutcome
{
    public const OK                   = 'ok';
    public const NO_CONFIGURED_DOMAIN = 'no_configured_domain';
    public const NO_KEY               = 'no_key';
    public const UNAVAILABLE          = 'unavailable';
    public const REFUSED              = 'refused';
    public const NOT_ACCEPTED_LOCALLY = 'not_accepted_locally';
    public const ROLLBACK_REFUSED     = 'rollback_refused';
    public const STORE_FAILED         = 'store_failed';

    private function __construct(
        public string $code,
        public string $detail = '',
    ) {
    }

    public static function ok(): self
    {
        return new self(self::OK);
    }

    public static function of(string $code, string $detail = ''): self
    {
        return new self($code, $detail);
    }

    public function succeeded(): bool
    {
        return self::OK === $this->code;
    }
}
