<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Compiler;

use Symfony\Component\PropertyInfo\Type;

/**
 * @internal
 */
final class TypeHelpers
{
    private function __construct()
    {
    }

    public static function requiresNormalization(?Type $type): bool
    {
        return null === $type || Type::BUILTIN_TYPE_ARRAY === $type->getBuiltinType() || Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType();
    }

    public static function requiresDenormalization(?Type $type): bool
    {
        return null !== $type && (Type::BUILTIN_TYPE_ARRAY === $type->getBuiltinType() || Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType()) && (!$type->isCollection() || self::requiresDenormalization($type->getCollectionValueType()));
    }

    public static function getDenormalizationClass(Type $type): string
    {
        return $type->isCollection() ? (self::getDenormalizationClass($type->getCollectionValueType()).'[]') : ($type->getClassName() ?? $type->getBuiltinType());
    }

    public static function isNullable(?Type $type): bool
    {
        return null === $type || $type->isNullable();
    }

    public static function isCollectionObject(?Type $type): bool
    {
        return null !== $type && $type->isCollection() && Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType();
    }
}
