<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle;

final class Reflection
{
    private function __construct()
    {
    }

    public static function getCallableReflector(callable $callable): \ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            return new \ReflectionMethod($callable[0], $callable[1]);
        } elseif (is_string($callable)) {
            return new \ReflectionFunction($callable);
        } elseif (is_object($callable)) {
            $objReflector = new \ReflectionObject($callable);

            return $objReflector->getMethod('__invoke');
        }
    }

    public static function getNonPublicProperty(\ReflectionClass $reflectionClass, string $propertyName): ?\ReflectionProperty
    {
        do {
            try {
                $property = $reflectionClass->getProperty($propertyName);
                $property->setAccessible(true);

                return $property;
            } catch (\ReflectionException $e) {
                $reflectionClass = $reflectionClass->getParentClass();
            }
        } while (false !== $reflectionClass);

        return null;
    }
}
