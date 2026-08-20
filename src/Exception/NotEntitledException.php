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
 * A protected operation was reached without the entitlement it requires.
 *
 * Thrown at the operation itself rather than checked once at the front door. Hiding a button is not
 * authorisation, and neither is a single gate somewhere upstream: every path that reaches the
 * behaviour — backend screen, console command, direct service call — has to ask on its own behalf,
 * so that removing any one of them does not open the rest.
 */
final class NotEntitledException extends \RuntimeException
{
    public function __construct(string $operation, public readonly string $reason)
    {
        parent::__construct(sprintf('Operation "%s" is not available (%s).', $operation, $reason));
    }
}
