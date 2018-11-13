<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Compatibility;

use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface as Original;

\class_alias(Original::class, CacheableSupportsMethodInterface::class, true);
