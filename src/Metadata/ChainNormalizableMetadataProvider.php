<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Metadata;

class ChainNormalizableMetadataProvider implements NormalizableMetadataProviderInterface
{
    /** @var NormalizableMetadataProviderInterface[] */
    private $providers;

    public function __construct(array $providers)
    {
        $this->providers = $providers;
    }

    /** {@inheritdoc} */
    public function getFactory(string $className): ?ClassFactory
    {
        foreach ($this->providers as $provider) {
            $factory = $provider->getFactory($className);
            if (null !== $factory) {
                return $factory;
            }
        }

        return null;
    }

    /** {@inheritdoc} */
    public function getGroupsSecurity(string $className): ?GroupsSecurity
    {
        /** @var GroupsSecurity|null $security */
        $security = null;
        foreach ($this->providers as $provider) {
            $currentSecurity = $provider->getGroupsSecurity($className);
            if (null !== $currentSecurity) {
                if (null === $security) {
                    $security = clone $currentSecurity;
                } else {
                    $security->readAttributes += $currentSecurity->readAttributes;
                    $security->writeAttributes += $currentSecurity->writeAttributes;
                }
            }
        }

        return $security;
    }

    /** {@inheritdoc} */
    public function getGroupsInitializers(string $className): ?array
    {
        $initializers = null;
        foreach ($this->providers as $provider) {
            $currentInitializers = $provider->getGroupsInitializers($className);
            if (null !== $currentInitializers) {
                $initializers = ($initializers ?? []) + $currentInitializers;
            }
        }

        return $initializers;
    }

    /** {@inheritdoc} */
    public function getNormalizableProperties(string $className): array
    {
        $properties = [];
        foreach ($this->providers as $provider) {
            $properties += $provider->getNormalizableProperties($className);
        }

        return $properties;
    }
}
