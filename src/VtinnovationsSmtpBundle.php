<?php

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
