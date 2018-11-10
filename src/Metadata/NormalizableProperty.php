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

use Symfony\Component\PropertyInfo\Type;

class NormalizableProperty
{
    /** @var string */
    public $name;

    /** @var string|null */
    public $originalName = null;

    /** @var string[] */
    public $groups = [];

    /** @var bool */
    public $alwaysNormalize = false;

    /** @var Type|null */
    public $type = null;

    /** @var string|null */
    public $indexBySubProperty = null;

    /** @var string|null */
    public $inlineSubProperty = null;

    /** @var string|null */
    public $inverseSubProperty = null;

    /** @var bool */
    public $autoPersist = false;

    /** @var array|null */
    public $readGroups = null;

    /** @var array|null */
    public $writeGroups = null;

    /** @var string|null */
    public $getTemplate = null;

    /** @var string|null */
    public $getHelper = null;

    /** @var string|null */
    public $setTemplate = null;

    /** @var string|null */
    public $setHelper = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
