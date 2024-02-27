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
class Factory
{
    public function __construct(
        public ?string $service = null,
        public ?string $class = null,
        public string $method = '__invoke',
    )
    {
    }
}
