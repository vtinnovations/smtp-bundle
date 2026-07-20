<?php

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Service;

final class HandleResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
    ) {
    }
}
