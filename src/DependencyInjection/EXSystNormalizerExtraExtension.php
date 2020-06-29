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

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class EXSystNormalizerExtraExtension extends Extension
{
    public function getAlias()
    {
        return 'exsyst_normalizer_extra';
    }

    /** {@inheritdoc} */
    public function load(array $configs, ContainerBuilder $container)
    {
        $processor = new Processor();
        $configuration = new Configuration();

        $config = $processor->processConfiguration($configuration, $configs);

        $container->setParameter('exsyst_normalizer_extra.implicit_breadth_first', $config['options']['implicit_breadth_first']);
        $container->setParameter('exsyst_normalizer_extra.default_context', ($config['options']['default_context'] ?? []) + [
            'json_decode_associative' => true,
            'yaml_inline'             => 2,
        ]);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
        if ($config['features']['request_decoder']) {
            $loader->load('services_request_decoder.yaml');
        }
        if ($config['features']['response_shape_header']) {
            $loader->load('services_response_shape_header.yaml');
        }
        if ($config['features']['serializer_view_listener']) {
            $loader->load('services_serializer_view_listener.yaml');
        }
        if ($config['features']['serializer_exception_listener']) {
            $loader->load('services_serializer_exception_listener.yaml');
        }
        if ($config['normalizers']['collection']) {
            $loader->load('services_normalizer_collection.yaml');
        }
        if ($config['normalizers']['specializing']) {
            $loader->load('services_normalizer_specializing.yaml');
        }
        if ($config['options']['auto_metadata']) {
            $loader->load('services_auto_metadata.yaml');
        }
        if ($config['unsafe_features']['collection_batching']) {
            $loader->load('services_collection_batching.yaml');
        }
        if ($config['unsafe_features']['entity_batching']) {
            $loader->load('services_entity_batching.yaml');
        }
    }
}
