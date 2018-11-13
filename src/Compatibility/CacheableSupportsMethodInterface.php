<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;

require_once __DIR__.DIRECTORY_SEPARATOR.(\interface_exists(CacheableSupportsMethodInterface::class) ? 'CacheableSupportsMethodInterface_real.php' : 'CacheableSupportsMethodInterface_dummy.php');
