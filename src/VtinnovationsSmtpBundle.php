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

namespace Vtinnovations\SmtpBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class VtinnovationsSmtpBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
