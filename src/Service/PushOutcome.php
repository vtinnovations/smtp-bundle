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
 * What the public push endpoint should answer, decided away from the controller.
 *
 * Every refusal collapses to one shape and one status. Telling a caller *which* check it failed is
 * a map for getting past the next one, so the endpoint says only that it will not do this.
 */
final readonly class PushOutcome
{
    public const UPDATED           = 'updated';
    public const ALREADY_PROCESSED = 'already_processed';
    public const REJECTED          = 'rejected';
    public const UNAUTHORIZED      = 'unauthorized';
    public const ERROR             = 'error';

    private function __construct(
        public string $status,
        public int $httpStatus,
        public string $requestId = '',
        public int $version = 0,
        public string $detail = '',
    ) {
    }

    public static function updated(string $requestId, int $version): self
    {
        return new self(self::UPDATED, 200, $requestId, $version);
    }

    public static function alreadyProcessed(string $requestId, int $version): self
    {
        return new self(self::ALREADY_PROCESSED, 200, $requestId, $version);
    }

    /** A genuinely signed but older or conflicting revision. Refused without touching state. */
    public static function rejected(string $requestId, int $version): self
    {
        return new self(self::REJECTED, 409, $requestId, $version);
    }

    public static function unauthorized(string $detail = ''): self
    {
        return new self(self::UNAUTHORIZED, 401, detail: $detail);
    }

    public static function error(string $requestId): self
    {
        return new self(self::ERROR, 500, $requestId);
    }
}
