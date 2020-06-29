<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder.
     *
     * @return TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('exsyst_normalizer_extra');

        $rootNode
            ->children()
                ->arrayNode('features')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('request_decoder')->defaultTrue()->end()
                        ->booleanNode('response_shape_header')->defaultTrue()->end()
                        ->booleanNode('serializer_exception_listener')->defaultFalse()->end()
                        ->booleanNode('serializer_view_listener')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('normalizers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('collection')->defaultFalse()->end()
                        ->booleanNode('specializing')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('options')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('implicit_breadth_first')->defaultFalse()->end()
                        ->booleanNode('auto_metadata')->defaultTrue()->end()
                        ->variableNode('default_context')->end()
                    ->end()
                ->end()
                ->arrayNode('unsafe_features')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('collection_batching')->defaultFalse()->end()
                        ->booleanNode('entity_batching')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
