<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ClientType
{
    public function __construct(
        public ?string $module = null,
        public ?string $class = null,
        public ?string $factory = null,
    ) {
    }
}
