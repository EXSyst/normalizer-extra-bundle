<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle;

use EXSyst\NormalizerExtraBundle\DependencyInjection\Compiler\NormalizablePropertyProviderPass;
use EXSyst\NormalizerExtraBundle\DependencyInjection\EXSystNormalizerExtraExtension;
use EXSyst\NormalizerExtraBundle\Metadata\NormalizableMetadataProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class EXSystNormalizerExtraBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->registerForAutoconfiguration(NormalizableMetadataProviderInterface::class)
            ->addTag('exsyst.normalizer_extra.metadata_provider');
        $container->addCompilerPass(new NormalizablePropertyProviderPass());
    }

    public function getContainerExtension()
    {
        if (null === $this->extension) {
            $this->extension = new EXSystNormalizerExtraExtension();
        }

        return $this->extension;
    }
}
