<?php

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
            ->end()
        ;

        return $treeBuilder;
    }
}
