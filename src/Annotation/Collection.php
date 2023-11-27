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
 * Tells the normalizer to use a property of the target object's elements as keys, instead of their position.
 * The target object must be a collection.
 *
 * @Annotation
 * @Target({ "PROPERTY", "METHOD" })
 */
class Collection
{
    /** @var string property of the elements to use as keys instead of their position, can be a property merged by inline */
    public ?string $indexBy = null;
}
