<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Initializer;

interface InitializerInterface
{
    /**
     * @param object $object
     */
    public function collect($object): bool;

    public function process(): void;

    /**
     * @param object $object
     */
    public function initialize($object): void;
}
