<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Normalizer;

use EXSyst\MiniEnum\Enum;

class CollectionOpCode extends Enum
{
    /** @var int update common elements, don't add/remove anything from the target */
    public const UPDATE = 0;

    /** @var int update common elements, add elements present only in source, don't remove anything from the target */
    public const ADD = 1;

    /** @var int update common elements, remove elements present only in target, don't add anything to the target */
    public const RETAIN = 2;

    /** @var int update common elements, add elements present only in source, remove elements present only in target (full sync) */
    public const SET = 3;

    /** @var int remove elements present in source, don't update anything */
    public const REMOVE = 4;

    /** @var int perform several operations */
    public const MERGE = 5;

    public static function hasUpdate(int $opCode): bool
    {
        return $opCode >= self::UPDATE && $opCode <= self::SET;
    }

    public static function hasAdd(int $opCode): bool
    {
        return self::ADD === $opCode || self::SET === $opCode;
    }

    public static function hasRetain(int $opCode): bool
    {
        return self::RETAIN === $opCode || self::SET === $opCode;
    }
}
