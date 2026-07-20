<?php

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
