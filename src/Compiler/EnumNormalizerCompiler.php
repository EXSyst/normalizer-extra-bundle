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

use EXSyst\DynamicClassGenerationBundle\Compiler\ResolvedClassInfo;
use EXSyst\DynamicClassGenerationBundle\Helper\StreamWriter;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class EnumNormalizerCompiler extends AbstractNormalizerCompiler
{
    protected const string PREFIX = 'EXSyst\\NormalizerExtraBundle\\__CG__\\EnumNormalizer\\';

    public function getForClass(string $className): NormalizerInterface&DenormalizerInterface
    {
        $normalizerClass = self::getNormalizerClassFor($className);

        return new $normalizerClass();
    }

    protected function compileNormalizerClass(StreamWriter $fd, ResolvedClassInfo $class): void
    {
        $normalizerClass = $class->getClass();
        $nsPos = \strrpos($normalizerClass, '\\');
        $className = \substr($class->getRest(), 0, -\strlen(self::SUFFIX));

        /* ----- File header ----- */

        $fd
            ->printfln('<?php')
            ->printfln()
            ->printfln('namespace %s;', \substr($normalizerClass, 0, $nsPos))
            ->printfln()
            ->printfln('use %s;', NormalizerInterface::class)
            ->printfln('use %s;', DenormalizerInterface::class)
            ->printfln('use %s as T;', $className)
            ->printfln()
            ->printfln('class %s implements NormalizerInterface, DenormalizerInterface', \substr($normalizerClass, $nsPos + 1))
            ->printfln('{')
            ->indent()
        ;

        /* ----- Normalization ----- */

        $fd
            ->printfln('/** @param T $object */')
            ->printfln('public function normalize($object, $format = null, array $context = []): %s', \is_a($className, \BackedEnum::class, true) ? 'string|int|null' : '?string')
            ->printfln('{')
            ->indent()
            ->printfln('return $object->%s;', \is_a($className, \BackedEnum::class, true) ? 'value' : 'name')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;

        /* ----- Denormalization ----- */

        $fd
            ->printfln('public function denormalize($data, $class, $format = null, array $context = []): ?T')
            ->printfln('{')
            ->indent()
            ->printfln('if (null === $data || $data instanceof T) {')
            ->indent()
            ->printfln('return $data;')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;

        if (\is_a($className, \BackedEnum::class, true)) {
            $fd->printfln('return T::from($data);');
        } else {
            $fd
                ->printfln('return match ($data) {')
                ->indent()
            ;
            foreach ($className::cases() as $case) {
                $fd->printfln('%s => T::%s,', \var_export($case->name, true), $case->name);
            }
            $fd
                ->printfln('default => throw new \ValueError(\sprintf(\'"%%s" is not a valid case for enum "%%s"\', $data, T::class)),')
                ->outdent()
                ->printfln('};')
            ;
        }

        $fd
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;

        /* ----- Support checks ----- */

        $fd
            ->printfln('public function supportsNormalization($data, $format = null): bool')
            ->printfln('{')
            ->indent()
            ->printfln('return $data instanceof T;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('public function supportsDenormalization($data, $type, $format = null): bool')
            ->printfln('{')
            ->indent()
            ->printfln('return T::class === $type;')
            ->outdent()
            ->printfln('}')
        ;

        /* ----- File footer ----- */

        $fd
            ->outdent()
            ->printfln('}')
        ;
    }
}
