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

interface NormalizableMetadataProviderInterface
{
    public function getFactory(string $className): ?ClassFactory;

    public function getGroupsSecurity(string $className): ?GroupsSecurity;

    /**
     * @return string[]|null
     */
    public function getGroupsInitializers(string $className): ?array;

    /**
     * @return NormalizableProperty[]
     */
    public function getNormalizableProperties(string $className): array;
}
