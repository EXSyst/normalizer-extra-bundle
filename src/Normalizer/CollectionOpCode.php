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

enum CollectionOpCode
{
    /** @var self update common elements, don't add/remove anything from the target */
    case Update;

    /** @var self update common elements, add elements present only in source, don't remove anything from the target */
    case Add;

    /** @var self update common elements, remove elements present only in target, don't add anything to the target */
    case Retain;

    /** @var self update common elements, add elements present only in source, remove elements present only in target (full sync) */
    case Set;

    /** @var self remove elements present in source, don't update anything */
    case Remove;

    /** @var self perform several operations */
    case Merge;

    public static function from(string $opCode): ?self
    {
        return match (\strtoupper($opCode)) {
            'UPDATE' => self::Update,
            'ADD'    => self::Add,
            'RETAIN' => self::Retain,
            'SET'    => self::Set,
            'REMOVE' => self::Remove,
            'MERGE'  => self::Merge,
            default  => null,
        };
    }

    public static function hasUpdate(self $opCode): bool
    {
        return match ($opCode) {
            self::Update, self::Add, self::Retain, self::Set => true,
            default => false,
        };
    }

    public static function hasAdd(self $opCode): bool
    {
        return match ($opCode) {
            self::Add, self::Set => true,
            default => false,
        };
    }

    public static function hasRetain(self $opCode): bool
    {
        return match ($opCode) {
            self::Retain, self::Set => true,
            default => false,
        };
    }
}
