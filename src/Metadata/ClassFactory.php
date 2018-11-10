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

class ClassFactory
{
    /** @var string|null */
    public $service = null;

    /** @var string|null */
    public $class = null;

    /** @var string */
    public $method;
}
