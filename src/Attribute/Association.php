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

/**
 * Describes an association, not necessarily managed by an ORM.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Association
{
    /**
     * @param string|null   $target      the target class of the association
     * @param string|null   $inversedBy  the property of the target class that refers back to the current class
     * @param bool          $toMany      whether this property is a collection of the target class (omit to determine automatically)
     * @param string|null   $inline      property of the target object (or its elements) to merge into the elements themselves
     * @param bool          $autoPersist whether to automatically persist added elements in the ORM
     * @param bool          $autoRemove  whether to automatically remove removed elements from the ORM, omit to use the same value as autoPersist
     * @param string[]|null $readGroups  groups to normalize in the elements (unless shape and allowed_groups are defined in the context), omit to inherit from the current object
     * @param string[]|null $writeGroups groups to denormalize in the elements, omit to inherit from the current object
     */
    public function __construct(
        public ?string $target = null,
        public ?string $inversedBy = null,
        public ?bool $toMany = null,
        public ?string $inline = null,
        public bool $autoPersist = false,
        public ?bool $autoRemove = null,
        public ?array $readGroups = null,
        public ?array $writeGroups = null,
    ) {
    }
}
