<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\DependencyInjection\Compiler;

use EXSyst\NormalizerExtraBundle\Metadata\ChainNormalizableMetadataProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class NormalizablePropertyProviderPass implements CompilerPassInterface
{
    /** {@inheritdoc} */
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition(ChainNormalizableMetadataProvider::class)) {
            return;
        }

        $chainDefinition = $container->getDefinition(ChainNormalizableMetadataProvider::class);

        $taggedServices = $container->findTaggedServiceIds('exsyst.normalizer_extra.metadata_provider');

        $prioritizedProviders = [];
        foreach ($taggedServices as $id => $tags) {
            $priority = null;
            foreach ($tags as $tag) {
                $tagPriority = \intval($tag['priority'] ?? 0);
                if (null === $priority || $tagPriority > $priority) {
                    $priority = $tagPriority;
                }
            }
            $prioritizedProviders[$priority ?? 0][] = new Reference($id);
        }
        \krsort($prioritizedProviders);

        $providers = [];
        foreach ($prioritizedProviders as $slice) {
            $providers = \array_merge($providers, $slice);
        }

        $chainDefinition->replaceArgument('$providers', $providers);
    }
}
