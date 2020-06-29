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

use Doctrine\Common\Persistence\ManagerRegistry;
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

    /** @var array */
    protected $attributes;

    /** @var array[] */
    protected $groupsAttributes;

    /** @var ManagerRegistry */
    protected $doctrine;

    /** @var AuthorizationCheckerInterface|null */
    protected $authorizationChecker;

    protected function getReadAttributeSet(array $context): array
    {
        if (isset($context['shape'])) {
            $shape = self::parseShape($context['shape']);
            $attributes = \array_diff_key($this->resolveHalfShape($shape['included']), $this->resolveHalfShape($shape['excluded']));
            if (isset($context['allowed_groups'])) {
                $attributes = \array_intersect_key($attributes, $this->getGroupsAttributeSet($context['allowed_groups']));
            } elseif (isset($context['groups'])) {
                $attributes = \array_intersect_key($attributes, $this->getGroupsAttributeSet($context['groups']));
            }
        } elseif (isset($context['groups'])) {
            $attributes = $this->getGroupsAttributeSet($context['groups']);
        } else {
            $attributes = $this->attributes;
        }

        return $attributes;
    }

    protected function getWriteAttributeSet(array $context, array $data): array
    {
        if (isset($context['groups'])) {
            $attributes = $this->getGroupsAttributeSet($context['groups']);
        } else {
            $attributes = $this->attributes;
        }
        if (isset($context['force_properties'])) {
            foreach ($context['force_properties'] as $property => $_) {
                $attributes[$property] = true;
            }
        }
        $attributes += $this->groupsAttributes['identity'] ?? [];

        return \array_intersect_key($attributes, $data);
    }

    private static function parseShape(array $shape): array
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

        return [
            'included' => self::parseHalfShape($included),
            'excluded' => self::parseHalfShape($excluded),
        ];
    }

    private static function parseHalfShape(array $halfShape): array
    {
        $attributes = [];
        $groups = [];
        $all = false;
        foreach ($halfShape as $attribute => $_) {
            if ('*' === $attribute) {
                $all = true;
            } elseif (\strlen($attribute) >= 3 && 0 === \substr_compare($attribute, '...', 0, 3)) {
                $groups[\substr($attribute, 3)] = true;
            } else {
                $attributes[$attribute] = true;
            }
        }

        return [
            'attributes' => $attributes,
            'groups'     => $groups,
            'all'        => $all,
        ];
    }

    private function getGroupsAttributeSet(array $groups): array
    {
        $attributes = [];
        foreach ($groups as $group) {
            $attributes += $this->groupsAttributes[$group] ?? [];
        }

        return $attributes;
    }

    private function resolveHalfShape(array $parsedHalfShape): array
    {
        return \array_intersect_key(
            $parsedHalfShape['attributes'] +
            $this->getGroupsAttributeSet(\array_keys($parsedHalfShape['groups'])) +
            ($parsedHalfShape['all'] ? $this->attributes : []),
            $this->attributes);
    }
}
