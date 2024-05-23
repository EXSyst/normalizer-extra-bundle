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
use EXSyst\NormalizerExtraBundle\Compiler\SpecializedNormalizerCompiler;
use EXSyst\NormalizerExtraBundle\Doctrine\EntityInitializer;
use Symfony\Component\Serializer\Exception\BadMethodCallException;
use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorResolverInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class SpecializingNormalizer implements NormalizerInterface, DenormalizerInterface, NormalizerAwareInterface, DenormalizerAwareInterface, CacheableSupportsMethodInterface
{
    use NormalizerAwareTrait;
    use DenormalizerAwareTrait;

    public const string TYPE_KEY = '^';

    /** @var SpecializedNormalizer[] */
    private array $specializedNormalizers = [];

    public function __construct(
        private readonly SpecializedNormalizerCompiler $specializedNormalizerCompiler,
        private readonly ?ClassDiscriminatorResolverInterface $classDiscriminatorResolver,
        private readonly ?EntityInitializer $initializer,
    ) {
    }

    public function denormalize($data, $class, $format = null, array $context = []): mixed
    {
        if ($data instanceof $class) {
            return $data;
        }

        if (isset($context['object_to_populate']) && $context['object_to_populate'] instanceof $class) {
            $class = \get_class($context['object_to_populate']);
        } elseif (null !== $this->classDiscriminatorResolver) {
            $mapping = $this->classDiscriminatorResolver->getMappingForClass($class);
            if (null !== $mapping && isset($data[$mapping->getTypeProperty()])) {
                $class = $mapping->getClassForType($data[$mapping->getTypeProperty()]) ?? $class;
            }
        }

        $specializedNormalizer = $this->getSpecializedNormalizerForClass($class);

        return $specializedNormalizer->denormalize($data, $class, $format, $context);
    }

    public function supportsDenormalization($data, $type, $format = null): bool
    {
        if (\class_exists($type)) {
            if (!(new \ReflectionClass($type))->isAbstract()) {
                return true;
            }
        } elseif (!\interface_exists($type, false)) {
            return false;
        }

        return null !== $this->classDiscriminatorResolver && null !== $this->classDiscriminatorResolver->getMappingForClass($type);
    }

    public function normalize($object, $format = null, array $context = []): array|\stdClass|null
    {
        if (null !== $this->initializer && isset($context['breadth_first_helper']) && $context['breadth_first_helper']->getCurrentObject() === $object && $this->initializer->collect($object)) {
            $helper = $context['breadth_first_helper'];
            $normalized = &$helper->getCurrentBindPoint();
            $helper->registerInitializer($this->initializer);
            $helper->bind($normalized, $this, $object, $format, $context);

            return null;
        }

        if (!isset($context['max_depth'])) {
            $context['max_depth'] = 16;
        }
        if (!isset($context['hl_stack_trace'])) {
            $context['hl_stack_trace'] = [];
        }

        $specializedNormalizer = $this->getSpecializedNormalizerForClass(\get_class($object));

        $context['hl_stack_trace'][] = [
            'class'    => \get_class($object),
            'identity' => $specializedNormalizer->normalize($object, 'json', ['groups' => ['identity'], 'force_groups' => true]),
            'groups'   => $context['groups'] ?? null,
            'inbound'  => $context['inbound_property'] ?? null,
        ];
        if (count($context['hl_stack_trace']) > $context['max_depth'] + 1) {
            throw new \Exception(\sprintf('Maximum normalization depth (%d) exceeded, while trying to normalize %s', $context['max_depth'], self::hlStackTraceToString($context['hl_stack_trace'])));
        }

        if (!isset($context['shape']) && !isset($context['breadth_first_helper'])) {
            $objectHash = \implode("\0", \array_merge([\spl_object_id($object)], $context['groups'] ?? []));
            if (isset($context['circular_reference_trace'][$objectHash])) {
                throw new CircularReferenceException(\sprintf('A circular reference has been detected, while trying to normalize %s', self::hlStackTraceToString($context['hl_stack_trace'])));
            } else {
                $context['circular_reference_trace'][$objectHash] = 1;
            }
        }

        return $specializedNormalizer->normalize($object, $format, $context);
    }

    public function supportsNormalization($data, $format = null): bool
    {
        return \is_object($data) && !($data instanceof \Traversable) && !($data instanceof \UnitEnum);
    }

    public function hasCacheableSupportsMethod(): bool
    {
        return __CLASS__ === \get_class($this);
    }

    private function hlStackTraceToString(array $hlStackTrace): string
    {
        return \implode('', \array_map(function (array $frame): string {
            return \sprintf('%s%s %s%s', $frame['inbound'] ? \sprintf(' -[%s]-> ', $frame['inbound']) : '', $frame['class'], \json_encode($frame['identity']), isset($frame['groups']) ? \sprintf(' (%s)', \implode(', ', $frame['groups'])) : '');
        }, $hlStackTrace));
    }

    private function getSpecializedNormalizerForClass(string $className): SpecializedNormalizer
    {
        if (!isset($this->specializedNormalizers[$className])) {
            if (!isset($this->normalizer)) {
                throw new BadMethodCallException('The owning normalizer must be set before attempting use of the SpecializingNormalizer');
            }
            if (!isset($this->denormalizer)) {
                throw new BadMethodCallException('The owning denormalizer must be set before attempting use of the SpecializingNormalizer');
            }

            $specializedNormalizer = $this->specializedNormalizerCompiler->getForClass($className);

            $specializedNormalizer->setNormalizer($this->normalizer);
            $specializedNormalizer->setDenormalizer($this->denormalizer);

            $this->specializedNormalizers[$className] = $specializedNormalizer;
        }

        return $this->specializedNormalizers[$className];
    }
}
