<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Annotation;

use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Describes an association, not necessarily managed by an ORM.
 *
 * @Annotation
 * @Target({ "PROPERTY", "METHOD" })
 */
class Association
{
    /** @var string the target class of the association */
    public ?string $target = null;

    /** @var string the property of the target class that refers back to the current class */
    public ?string $inversedBy = null;

    /** @var bool whether this property is a collection of the target class (omit to determine automatically) */
    public ?bool $toMany = null;

    /** @var string property of the target object (or its elements) to merge into the elements themselves */
    public ?string $inline = null;

    /** @var bool whether to automatically persist added elements in the ORM */
    public bool $autoPersist = false;

    /** @var bool whether to automatically remove removed elements from the ORM, omit to use the same value as autoPersist */
    public ?bool $autoRemove = null;

    /** @var array groups to normalize in the elements (unless shape and allowed_groups are defined in the context), omit to inherit from the current object */
    public ?array $readGroups = null;

    /** @var array groups to denormalize in the elements, omit to inherit from the current object */
    public ?array $writeGroups = null;
}
