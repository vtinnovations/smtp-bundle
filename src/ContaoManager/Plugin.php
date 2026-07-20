<?php

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Vtinnovations\SmtpBundle\VtinnovationsSmtpBundle;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(VtinnovationsSmtpBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }
}
