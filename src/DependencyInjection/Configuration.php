<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('webf_fs_failover');
        $rootNodeChildren = $treeBuilder->getRootNode()->children();

        $this->addAdaptersSection($rootNodeChildren);
        $this->addBusTransportDsnSection($rootNodeChildren);

        return $treeBuilder;
    }

    private function addAdaptersSection(NodeBuilder $rootNodeChildren): void
    {
        $adapterPrototype = $rootNodeChildren
            ->arrayNode('adapters')
            ->useAttributeAsKey('name')
            ->arrayPrototype()
            ->children()
        ;

        $adapterPrototype
            ->scalarNode('name')
            ->cannotBeEmpty()
        ;

        $adapterPrototype
            ->arrayNode('adapters')
            ->validate()->ifArray()->then(function (array $adapters) {
                if (count($adapters) < 2) {
                    throw new \InvalidArgumentException('There must be at least 2 adapters');
                }

                return $adapters;
            })->end()
            ->cannotBeEmpty()
            ->scalarPrototype()
            ->cannotBeEmpty()
        ;
    }

    private function addBusTransportDsnSection(
        NodeBuilder $rootNodeChildren
    ): void {
        $rootNodeChildren->scalarNode('bus_transport_dsn')
            ->defaultValue('sync://')
        ;
    }
}
