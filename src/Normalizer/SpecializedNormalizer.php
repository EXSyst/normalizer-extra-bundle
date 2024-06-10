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

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

abstract class SpecializedNormalizer implements NormalizerAwareInterface, DenormalizerAwareInterface, NormalizerInterface, DenormalizerInterface
{
    use NormalizerAwareTrait;
    use DenormalizerAwareTrait;

    /**
     * @param string[][] $propertyNames
     * @param int[][]    $propertyIndices
     * @param int[][]    $groupMasks
     * @param int[]      $fullMask
     * @param int[]      $emptyMask
     */
    protected function __construct(
        protected readonly ManagerRegistry $doctrine,
        protected readonly ?AuthorizationCheckerInterface $authorizationChecker,
        protected readonly array $propertyNames,
        protected readonly array $propertyIndices,
        protected readonly array $groupMasks,
        protected readonly array $fullMask,
        protected readonly array $emptyMask,
    ) {
    }

    /**
     * @param int[] $mask
     * @param int[] $mask2
     */
    abstract protected static function unionMask(array &$mask, array $mask2): void;

    /**
     * @param int[] $mask
     * @param int[] $mask2
     */
    abstract protected static function intersectMask(array &$mask, array $mask2): void;

    /**
     * @param int[] $mask
     * @param int[] $subtrahend
     */
    abstract protected static function subtractMask(array &$mask, array $subtrahend): void;

    /**
     * @param int[] $mask
     *
     * @return true[]
     */
    abstract protected static function decodeMaskProperties(array $mask): array;

    /**
     * @param int[] $mask
     * @param int[] $index
     */
    protected static function setMaskBit(array &$mask, ?array $index, bool $value): void
    {
        if (null === $index) {
            return;
        }

        $wordIndex = $index[0];
        $word = 1 << $index[1];
        if ($value) {
            $mask[$wordIndex] |= $word;
        } else {
            $mask[$wordIndex] &= ~$word;
        }
    }

    /**
     * @return int[]
     */
    protected function getReadMask(array $context): array
    {
        if (isset($context['shape'])) {
            $mask = $this->getShapeMask($context['shape']);
            if (isset($context['allowed_groups'])) {
                static::intersectMask($mask, $this->getGroupsMask($context['allowed_groups']));
            } elseif (isset($context['groups'])) {
                static::intersectMask($mask, $this->getGroupsMask($context['groups']));
            }

            return $mask;
        }

        if (isset($context['groups'])) {
            $mask = $this->getGroupsMask($context['groups']);
        } else {
            $mask = $this->fullMask;
        }
        if (isset($context['exclude_groups'])) {
            static::subtractMask($mask, $this->getGroupsMask($context['exclude_groups']));
        }

        return $mask;
    }

    protected function getWriteMask(array $context, array $data): array
    {
        if (isset($context['groups'])) {
            $mask = $this->getGroupsMask($context['groups']);
        } else {
            $mask = $this->fullMask;
        }
        if (isset($context['force_properties'])) {
            foreach ($context['force_properties'] as $property => $_) {
                self::setMaskBit($mask, $this->propertyIndices[$property] ?? null, true);
            }
        }
        static::unionMask($mask, $this->groupMasks['identity'] ?? $this->emptyMask);
        static::intersectMask($mask, $this->getPropertiesMask($data));

        return $mask;
    }

    /**
     * @return int[]
     */
    private function getShapeMask(array $shape): array
    {
        $included = [];
        $excluded = [];
        foreach ($shape as $attribute => $_) {
            if (\strlen($attribute) >= 1 && 0 === \substr_compare($attribute, '-', 0, 1)) {
                $excluded[\substr($attribute, 1)] = true;
            } else {
                $included[$attribute] = true;
            }
        }
        $mask = $this->getHalfShapeMask($included);
        static::subtractMask($mask, $this->getHalfShapeMask($excluded));

        return $mask;
    }

    /**
     * @return int[]
     */
    private function getHalfShapeMask(array $halfShape): array
    {
        $mask = $this->emptyMask;
        foreach ($halfShape as $attribute => $_) {
            if ('*' === $attribute) {
                return $this->fullMask;
            } elseif (\strlen($attribute) >= 3 && 0 === \substr_compare($attribute, '...', 0, 3)) {
                static::unionMask($mask, $this->groupMasks[\substr($attribute, 3)] ?? $this->emptyMask);
            } else {
                self::setMaskBit($mask, $this->propertyIndices[$attribute] ?? null, true);
            }
        }

        return $mask;
    }

    /**
     * @return int[]
     */
    protected function getPropertiesMask(array $data): array
    {
        $mask = $this->emptyMask;
        foreach ($data as $property => $_) {
            self::setMaskBit($mask, $this->propertyIndices[$property] ?? null, true);
        }

        return $mask;
    }

    /**
     * @param string[] $groups
     *
     * @return int[]
     */
    private function getGroupsMask(array $groups): array
    {
        $mask = $this->emptyMask;
        foreach ($groups as $group) {
            static::unionMask($mask, $this->groupMasks[$group] ?? $this->emptyMask);
        }

        return $mask;
    }
}
