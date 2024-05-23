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

use EXSyst\DynamicClassGenerationBundle\Compiler\ClassGeneratorInterface;
use EXSyst\DynamicClassGenerationBundle\Compiler\ResolvedClassInfo;
use EXSyst\DynamicClassGenerationBundle\Helper\StreamWriter;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * @internal
 */
abstract class AbstractNormalizerCompiler implements ClassGeneratorInterface
{
    protected const string PREFIX = '';
    protected const string SUFFIX = 'Normalizer';

    public static function getNormalizerClassFor(string $className): string
    {
        return static::PREFIX.$className.static::SUFFIX;
    }

    abstract public function getForClass(string $className): NormalizerInterface&DenormalizerInterface;

    public function generate(ResolvedClassInfo $class): bool
    {
        $rest = $class->getRest();
        $suffixLength = \strlen(static::SUFFIX);
        if (\strlen($rest) < $suffixLength || 0 !== \substr_compare($rest, static::SUFFIX, -$suffixLength, $suffixLength)) {
            return false;
        }
        $fd = \fopen($class->getPath(), 'wb');
        if (false === $fd) {
            throw new IOException('Cannot open temporary file to compile class '.$class->getClass());
        }
        try {
            $this->compileNormalizerClass(new StreamWriter($fd), $class);
        } finally {
            \fclose($fd);
        }

        return true;
    }

    abstract protected function compileNormalizerClass(StreamWriter $fd, ResolvedClassInfo $class): void;
}
