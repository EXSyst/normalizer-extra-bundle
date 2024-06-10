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
 * Tells the normalizer to use a property of the target object's elements as keys, instead of their position.
 * The target object must be a collection.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Collection
{
    /**
     * @param bool        $preserveKeys whether to preserve keys of the collection
     * @param string|null $indexBy      property of the elements to use as keys instead of their position, can be a property merged by inline
     */
    public function __construct(
        public bool $preserveKeys = true,
        public ?string $indexBy = null,
    ) {
    }
}
