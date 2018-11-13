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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Persistence\ManagerRegistry;
use EXSyst\NormalizerExtraBundle\Compatibility\CacheableSupportsMethodInterface;
use EXSyst\NormalizerExtraBundle\Initializer\CollectionInitializer;
use Symfony\Component\Serializer\Normalizer\ContextAwareDenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class CollectionNormalizer implements NormalizerInterface, ContextAwareDenormalizerInterface, NormalizerAwareInterface, DenormalizerAwareInterface, CacheableSupportsMethodInterface
{
    use NormalizerAwareTrait, DenormalizerAwareTrait;

    /** @var ManagerRegistry */
    private $doctrine;

    /** @var CollectionInitializer|null */
    private $initializer;

    public function __construct(ManagerRegistry $doctrine, ?CollectionInitializer $initializer)
    {
        $this->doctrine = $doctrine;
        $this->initializer = $initializer;
    }

    /** {@inheritdoc} */
    public function hasCacheableSupportsMethod(): bool
    {
        return __CLASS__ === \get_class($this)
            && $this->normalizer instanceof CacheableSupportsMethodInterface && $this->normalizer->hasCacheableSupportsMethod()
            && $this->denormalizer instanceof CacheableSupportsMethodInterface && $this->denormalizer->hasCacheableSupportsMethod();
    }

    /** {@inheritdoc} */
    public function supportsDenormalization($data, $type, $format = null, array $context = [])
    {
        return '[]' === \substr($type, -2)
            && $this->denormalizer->supportsDenormalization($data, \substr($type, 0, -2), $format, $context);
    }

    /** {@inheritdoc} */
    public function denormalize($data, $class, $format = null, array $context = [])
    {
        if (isset($context['object_to_populate']) && $context['object_to_populate'] instanceof Collection) {
            $unwrap = false;
            $collection = $context['object_to_populate'];
        } else {
            $unwrap = true;
            $collection = new ArrayCollection($context['object_to_populate'] ?? []);
        }

        $class = \substr($class, 0, -2);
        $this->updateCollection($collection, $data, $class, $format, $context);

        return $unwrap ? $collection->toArray() : $collection;
    }

    private function updateCollection(Collection $collection, array $data, string $class, ?string $format, array $context): void
    {
        if (\count($data) >= 1 && \is_string($data[0]) && \strlen($data[0]) >= 1 && '$' === $data[0][0]) {
            $opCode = \substr(\array_shift($data), 1);

            if ('merge' !== $opCode && isset($context['index_by_property']) && 1 === \count($data) && \is_array($data[0])) {
                $data = $data[0];
            }
        } else {
            $opCode = 'set';
        }

        if ('merge' === $opCode) {
            foreach ($data as $element) {
                $this->updateCollection($collection, $element, $class, $format, $context);
            }

            return;
        }

        if (isset($context['index_by_property'])) {
            $key = $context['index_by_property'];
            foreach ($data as $k => &$element) {
                $element[$key] = $k;
            }
            unset($element);
        }

        $hashedCollection = [];
        foreach ($collection as $element) {
            $hashedCollection[\spl_object_hash($element)] = $element;
        }

        $hashedData = [];
        foreach ($data as $element) {
            $element = $this->denormalizer->denormalize($element, $class, $format, $context);
            $hashedData[\spl_object_hash($element)] = $element;
        }

        $onRemove = $context['on_remove'] ?? null;
        $autoPersist = $context['auto_persist'] ?? false;
        $manager = $autoPersist ? $this->doctrine->getManagerForClass($class) : null;

        if ('set' === $opCode || 'retain' === $opCode) {
            foreach ($hashedCollection as $hash => $element) {
                if (!isset($hashedData[$hash])) {
                    $collection->removeElement($element);
                    if (isset($onRemove)) {
                        $onRemove($element);
                    }
                }
            }
        }

        if ('set' === $opCode || 'add' === $opCode) {
            foreach ($hashedData as $hash => $element) {
                if (!isset($hashedCollection[$hash])) {
                    $collection[] = $element;
                    if ($autoPersist) {
                        $manager->persist($element);
                    }
                }
            }
        } elseif ('remove' === $opCode) {
            foreach ($hashedData as $hash => $element) {
                if (isset($hashedCollection[$hash])) {
                    $collection->removeElement($element);
                    if (isset($onRemove)) {
                        $onRemove($element);
                    }
                }
            }
        }
    }

    /** {@inheritdoc} */
    public function normalize($object, $format = null, array $context = [])
    {
        if (isset($context['breadth_first_helper'])) {
            $this->normalizeBreadthFirst($object, $format, $context);

            return null;
        }

        $normalized = [];
        foreach ($object as $key => $element) {
            $normalized[$key] = $this->normalizer->normalize($element, $format, $context);
        }

        if (isset($context['index_by_property'])) {
            $key = $context['index_by_property'];
            $normalized = \array_column($normalized, null, $key);
            foreach ($normalized as &$element) {
                unset($element[$key]);
            }
            unset($element);
        }

        return $normalized;
    }

    private function normalizeBreadthFirst($object, ?string $format, array $context): void
    {
        $helper = $context['breadth_first_helper'];
        $normalized = &$helper->getCurrentBindPoint();

        if (null !== $this->initializer && $this->initializer->collect($object)) {
            $helper->registerInitializer($this->initializer);
            $helper->bind($normalized, $this, $object, $format, $context);

            return;
        }

        $normalized = [];

        if (isset($context['index_by_property'])) {
            $key = $context['index_by_property'];
            foreach ($object as $element) {
                $normalizedElement = null;
                $helper->bind($normalizedElement, $this->normalizer, $element, $format, [
                    'continuation' => function () use (&$normalized, $key, &$normalizedElement): void {
                        $normalized[$normalizedElement[$key]] = &$normalizedElement;
                    },
                ] + $context);
                unset($normalizedElement);
            }
        } else {
            foreach ($object as $key => $element) {
                $helper->bind($normalized[$key], $this->normalizer, $element, $format, [
                    'continuation' => null,
                ] + $context);
            }
        }

        if (isset($context['continuation'])) {
            $context['continuation']();
        }
    }

    /** {@inheritdoc} */
    public function supportsNormalization($data, $format = null)
    {
        return \is_array($data) || $data instanceof \Traversable;
    }
}
