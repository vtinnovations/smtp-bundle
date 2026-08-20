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

namespace Vtinnovations\SmtpBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vtinnovations_smtp');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('php_binary')
                    ->info('Path to PHP binary used for cache:clear subprocess. Defaults to PHP_BINARY constant.')
                    ->defaultValue('')
                ->end()
                ->integerNode('process_timeout')
                    ->info('Seconds before cache:clear subprocess times out.')
                    ->defaultValue(120)
                    ->min(30)
                ->end()
                ->arrayNode('domains')
                    ->info(
                        'Hostnames this installation is configured to serve, used when root pages '
                        .'carry no DNS entry. Exact hostnames only: "example.com" does not cover '
                        .'"www.example.com" or any subdomain.'
                    )
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
