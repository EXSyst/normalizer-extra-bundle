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
    public string $name;
    public ?string $originalName = null;

    /** @var string[] */
    public array $groups = [];

    public bool $alwaysNormalize = false;
    public ?Type $type = null;
    public ?string $indexBySubProperty = null;
    public ?string $inlineSubProperty = null;
    public ?string $inverseSubProperty = null;
    public bool $autoPersist = false;
    public bool $autoRemove = false;
    public ?array $readGroups = null;
    public ?array $writeGroups = null;
    public ?string $getTemplate = null;
    public ?string $getHelper = null;
    public ?string $getForUpdateTemplate = null;
    public ?string $getForUpdateHelper = null;
    public ?string $setTemplate = null;
    public ?string $setHelper = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
