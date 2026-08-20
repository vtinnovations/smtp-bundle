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

namespace Vtinnovations\SmtpBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use Vtinnovations\SmtpBundle\VtinnovationsSmtpBundle;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(VtinnovationsSmtpBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }

    /** Registers the licence-updater endpoint V-T.ONE pushes licence changes to. */
    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $file   = __DIR__.'/../../config/routes.yaml';
        $loader = $resolver->resolve($file, 'yaml');

        return $loader ? $loader->load($file, 'yaml') : null;
    }
}
