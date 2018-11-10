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
    /**
     * @param string $className
     *
     * @return ClassFactory|null
     */
    public function getFactory(string $className): ?ClassFactory;

    /**
     * @param string $className
     *
     * @return GroupsSecurity|null
     */
    public function getGroupsSecurity(string $className): ?GroupsSecurity;

    /**
     * @param string $className
     *
     * @return string[]|null
     */
    public function getGroupsInitializers(string $className): ?array;

    /**
     * @param string $className
     *
     * @return NormalizableProperty[]
     */
    public function getNormalizableProperties(string $className): array;
}
