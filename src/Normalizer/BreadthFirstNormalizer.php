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

use Symfony\Component\Serializer\Normalizer\ContextAwareNormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

class BreadthFirstNormalizer implements ContextAwareNormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private readonly bool $implicit;
    private readonly bool $mergeObjects;

    public function __construct(bool $implicit, bool $mergeObjects = false)
    {
        $this->implicit = $implicit;
        $this->mergeObjects = $mergeObjects;
    }

    public function supportsNormalization($data, $format = null, array $context = [])
    {
        if (isset($context['breadth_first_helper'])) {
            return false;
        }

        return $this->implicit || $data instanceof BreadthFirstHelper;
    }

    public function normalize($object, $format = null, array $context = [])
    {
        if (!($object instanceof BreadthFirstHelper)) {
            $object = new BreadthFirstHelper($object, $context['merge_objects'] ?? $this->mergeObjects);
        }

        $result = null;

        $object->bind($result, $this->normalizer, $object->getRoot(), $format, [
            'breadth_first_helper' => $object,
        ] + $context);
        $object->resolve();

        return $result;
    }
}
