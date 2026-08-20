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
 * An authenticated delivery: the exact record bytes, plus the envelope that vouched for them.
 *
 * The bytes are kept verbatim and never re-encoded. The signature covers a canonical byte sequence,
 * so pretty-printing or round-tripping the JSON would break verification even though the data is
 * unchanged.
 */
final readonly class SealedRecord
{
    /**
     * @param array<string, mixed> $envelope
     */
    public function __construct(
        public string $bytes,
        public array $envelope,
        public int $version,
        public string $keyId,
    ) {
    }
}
