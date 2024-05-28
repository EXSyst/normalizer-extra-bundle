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
use Doctrine\Persistence\ManagerRegistry;
use EXSyst\NormalizerExtraBundle\Compatibility\CacheableSupportsMethodInterface;
use EXSyst\NormalizerExtraBundle\Doctrine\CollectionInitializer;
use EXSyst\NormalizerExtraBundle\Doctrine\FlushProofUpdater;
use Symfony\Component\Serializer\Normalizer\ContextAwareDenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class CollectionNormalizer implements NormalizerInterface, ContextAwareDenormalizerInterface, NormalizerAwareInterface, DenormalizerAwareInterface, CacheableSupportsMethodInterface
{
    use NormalizerAwareTrait;
    use DenormalizerAwareTrait;

    public function __construct(private readonly ManagerRegistry $doctrine, private readonly ?CollectionInitializer $initializer)
    {
    }

    public function hasCacheableSupportsMethod(): bool
    {
        return __CLASS__ === \get_class($this)
            && $this->normalizer instanceof CacheableSupportsMethodInterface && $this->normalizer->hasCacheableSupportsMethod()
            && $this->denormalizer instanceof CacheableSupportsMethodInterface && $this->denormalizer->hasCacheableSupportsMethod();
    }

    public function supportsDenormalization($data, $type, $format = null, array $context = []): bool
    {
        return \str_ends_with($type, '[]')
            && $this->denormalizer->supportsDenormalization($data, \substr($type, 0, -2), $format, $context);
    }

    public function denormalize($data, $class, $format = null, array $context = []): array|object
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
        if (\count($data) >= 1 && isset($data[0]) && \is_string($data[0]) && \strlen($data[0]) >= 1 && '$' === $data[0][0]) {
            $opCode = CollectionOpCode::from(\substr(\array_shift($data), 1)) ?? CollectionOpCode::Update;
            if (null === $opCode) {
                $opCode = CollectionOpCode::Update;
            }

            if (CollectionOpCode::Merge !== $opCode && isset($context['index_by_property']) && 1 === \count($data) && \is_array($data[0])) {
                $data = $data[0];
            }
        } else {
            $opCode = CollectionOpCode::Set;
        }

        if (CollectionOpCode::Merge === $opCode) {
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
            $hashedCollection[\spl_object_id($element)] = $element;
        }

        $preWrite = CollectionOpCode::hasAdd($opCode) ? null : ((CollectionOpCode::Remove === $opCode) ? function (): bool {
            return false;
        } : function ($element) use ($hashedCollection) {
            return isset($hashedCollection[\spl_object_id($element)]);
        });
        $hashedData = [];
        foreach ($data as $element) {
            $element = $this->denormalizer->denormalize($element, $class, $format, ['pre_write' => $preWrite] + $context);
            $hashedData[\spl_object_id($element)] = $element;
        }

        $onRemove = $context['on_remove'] ?? null;
        $autoPersist = $context['auto_persist'] ?? false;
        $manager = $autoPersist ? $this->doctrine->getManagerForClass($class) : null;
        $updater = isset($manager) ? FlushProofUpdater::fromManagerAndContext($manager, $context) : null;

        if (CollectionOpCode::hasRetain($opCode)) {
            foreach ($hashedCollection as $hash => $element) {
                if (!isset($hashedData[$hash])) {
                    $collection->removeElement($element);
                    if (isset($onRemove)) {
                        $onRemove($element);
                    }
                }
            }
        }

        if (CollectionOpCode::hasAdd($opCode)) {
            foreach ($hashedData as $hash => $element) {
                if (!isset($hashedCollection[$hash])) {
                    $collection[] = $element;
                    if ($autoPersist) {
                        $updater->persist($element);
                    }
                }
            }
        } elseif (CollectionOpCode::Remove === $opCode) {
            foreach ($hashedData as $hash => $element) {
                if (isset($hashedCollection[$hash])) {
                    $collection->removeElement($element);
                    if (isset($onRemove)) {
                        $onRemove($element);
                    }
                }
            }
        }

        FlushProofUpdater::updateCollectionUsingContext($collection, $context);
    }

    public function normalize($object, $format = null, array $context = []): array|\stdClass|null
    {
        if (isset($context['breadth_first_helper']) && $context['breadth_first_helper']->getCurrentObject() === $object) {
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

            if (empty($normalized)) {
                $normalized = new \stdClass();
            }
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

        $normalized ??= [];

        if (isset($context['index_by_property'])) {
            $empty = true;
            $key = $context['index_by_property'];
            foreach ($object as $element) {
                $empty = false;
                $normalizedElement = null;
                $helper->bind($normalizedElement, $this->normalizer, $element, $format, [
                    'continuation' => function () use (&$normalized, $key, &$normalizedElement, $helper): void {
                        if (isset($normalizedElement[$key])) {
                            $primaryElement = &$normalizedElement;
                        } else {
                            $index = BreadthFirstHelper::tryGetStubBindIndex($normalizedElement);
                            if (null !== $index) {
                                $primaryElement = &$helper->getPrimaryBindPoint($index);
                            } else {
                                throw new \RuntimeException(\sprintf('Missing key %s and the element is not an index stub', $key));
                            }
                        }
                        if (($normalized[$primaryElement[$key]] ?? null) === $primaryElement) {
                            return;
                        }
                        $normalized[$primaryElement[$key]] = &$normalizedElement;
                        if (isset($primaryElement[BreadthFirstHelper::INDEX_KEY])) {
                            $helper->scheduleUnset($primaryElement[BreadthFirstHelper::INDEX_KEY], $key);
                        } else {
                            unset($primaryElement[$key]);
                        }
                    },
                ] + $context);
                unset($normalizedElement);
            }
            if ($empty) {
                $normalized = new \stdClass();
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

    public function supportsNormalization($data, $format = null): bool
    {
        return \is_array($data) || $data instanceof \Traversable;
    }
}
