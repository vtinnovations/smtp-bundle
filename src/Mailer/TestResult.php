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

namespace Vtinnovations\SmtpBundle\Mailer;

final class TestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly float $duration = 0.0,
    ) {
    }
}
