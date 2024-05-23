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

use EXSyst\NormalizerExtraBundle\Compatibility\CacheableSupportsMethodInterface;
use EXSyst\NormalizerExtraBundle\Compiler\EnumNormalizerCompiler;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class EnumNormalizer implements NormalizerInterface, DenormalizerInterface, CacheableSupportsMethodInterface
{
    /** @var NormalizerInterface[]&DenormalizerInterface[] */
    private array $enumNormalizers = [];

    public function __construct(
        private readonly EnumNormalizerCompiler $enumNormalizerCompiler,
    ) {
    }

    public function denormalize($data, $class, $format = null, array $context = []): mixed
    {
        if ($data instanceof $class) {
            return $data;
        }

        $enumNormalizer = $this->getEnumNormalizerForClass($class);

        return $enumNormalizer->denormalize($data, $class, $format, $context);
    }

    public function supportsDenormalization($data, $type, $format = null): bool
    {
        return \is_a($type, \UnitEnum::class, true);
    }

    public function normalize($object, $format = null, array $context = []): string|int|null
    {
        $enumNormalizer = $this->getEnumNormalizerForClass(\get_class($object));

        return $enumNormalizer->normalize($object, $format, $context);
    }

    public function supportsNormalization($data, $format = null): bool
    {
        return $data instanceof \UnitEnum;
    }

    public function hasCacheableSupportsMethod(): bool
    {
        return __CLASS__ === \get_class($this);
    }

    private function getEnumNormalizerForClass(string $className): NormalizerInterface&DenormalizerInterface
    {
        if (\is_a($className, \BackedEnum::class, true)) {
            $className = \BackedEnum::class;
        }

        if (!isset($this->enumNormalizers[$className])) {
            $this->enumNormalizers[$className] = $this->enumNormalizerCompiler->getForClass($className);
        }

        return $this->enumNormalizers[$className];
    }
}
