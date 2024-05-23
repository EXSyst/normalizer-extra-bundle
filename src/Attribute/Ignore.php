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
 * Tell the normalizer to ignore a method entirely.
 *
 * Useful to mark methods that look like getters but actually aren't.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Ignore
{
}
