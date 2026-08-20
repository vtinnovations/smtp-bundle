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

namespace Vtinnovations\SmtpBundle\Exception;

/**
 * A delivered package did not survive verification.
 *
 * The reason is a machine code for internal diagnosis and safe operational logging — never shown to
 * a caller and never returned over HTTP. Telling whoever sent the packet which check it failed is a
 * map for getting past the next one.
 */
final class PackageRejectedException extends \RuntimeException
{
    public const PAYLOAD_NOT_BASE64  = 'payload_not_base64';
    public const ENVELOPE_MALFORMED  = 'envelope_malformed';
    public const KEY_STORE_EMPTY     = 'signing_key_store_empty';
    public const UNKNOWN_KEY         = 'unknown_signing_key';
    public const BAD_ENVELOPE_SIG    = 'envelope_signature_invalid';
    public const CHECKSUM_MISMATCH   = 'checksum_mismatch';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
