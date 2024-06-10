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

use EXSyst\NormalizerExtraBundle\Initializer\InitializerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class BreadthFirstHelper implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public const string INDEX_KEY = '#';

    private static int $nextBindObjectIndex = 1;

    /** @var InitializerInterface[] */
    private array $initializers = [];

    /** @var array[] */
    private array $queue = [];

    /** @var int[] */
    private array $objectBindIndices = [];

    /** @var array[] */
    private array $objectBinds = [];

    private mixed $currentBindPoint = null;
    private mixed $currentObject = null;
    private ?string $currentMergeKey = null;
    private ?int $currentBindIndex = null;

    public function __construct(
        private readonly mixed $root = null,
        private readonly bool $mergeObjects = false,
    ) {
    }

    public function getRoot(): mixed
    {
        return $this->root;
    }

    public function &getCurrentBindPoint(): mixed
    {
        return $this->currentBindPoint;
    }

    public function getCurrentObject(): mixed
    {
        return $this->currentObject;
    }

    public function getCurrentBindIndex(): ?int
    {
        if (null === $this->currentBindIndex && null !== $this->currentMergeKey) {
            $index = self::$nextBindObjectIndex++;
            $this->objectBindIndices[$this->currentMergeKey] = $index;
            $this->objectBinds[$index] = [&$this->currentBindPoint, $this->currentObject, 1, [], null];
            $this->currentBindIndex = $index;
        }

        return $this->currentBindIndex;
    }

    public function &getPrimaryBindPoint(int $index): mixed
    {
        if (!isset($this->objectBinds[$index])) {
            throw new \RuntimeException(\sprintf('Unknown bind index %d', $index));
        }

        return $this->objectBinds[$index][0];
    }

    public function &getNormalizationState(int $index): mixed
    {
        if (!isset($this->objectBinds[$index])) {
            throw new \RuntimeException(\sprintf('Unknown bind index %d', $index));
        }

        return $this->objectBinds[$index][4];
    }

    public function scheduleUnset(int $index, string $key): static
    {
        if (!isset($this->objectBinds[$index])) {
            throw new \RuntimeException(\sprintf('Unknown bind index %d', $index));
        }

        $this->objectBinds[$index][3][$key] = ($this->objectBinds[$index][3][$key] ?? 0) + 1;

        return $this;
    }

    public function registerInitializer(InitializerInterface $initializer): static
    {
        $this->initializers[\spl_object_id($initializer)] = $initializer;

        return $this;
    }

    public function bind(mixed &$point, NormalizerInterface $normalizer, mixed $object, ?string $format = null, array $context = []): static
    {
        $this->queue[] = [&$point, $normalizer, $object, $format, $context];

        return $this;
    }

    public function resolve(): static
    {
        while (\count($this->initializers) > 0 || \count($this->queue) > 0) {
            $initializers = $this->initializers;
            $queue = $this->queue;
            $this->initializers = [];
            $this->queue = [];

            foreach ($initializers as $initializer) {
                $initializer->process();
            }
            foreach ($queue as $instruction) {
                $this->currentBindPoint = &$instruction[0];
                $this->currentObject = $instruction[2];
                $this->currentMergeKey = null;
                $this->currentBindIndex = null;
                $result = $this->resolveInstruction($instruction[1], $instruction[2], $instruction[3], $instruction[4]);
                if (null !== $result) {
                    $this->currentBindPoint = $result;
                }
            }
            unset($this->currentBindPoint);
            $this->currentObject = null;
            $this->currentMergeKey = null;
            $this->currentBindIndex = null;
        }

        foreach ($this->objectBinds as &$objectBind) {
            $refCount = $objectBind[2];
            if (1 === $refCount && \is_array($objectBind[0])) {
                unset($objectBind[0][self::INDEX_KEY]);
            }
            foreach ($objectBind[3] as $key => $unsetCount) {
                if ($unsetCount === $refCount) {
                    unset($objectBind[0][$key]);
                }
            }
        }
        unset($objectBind);

        $this->objectBindIndices = [];
        $this->objectBinds = [];

        return $this;
    }

    private function resolveInstruction(NormalizerInterface $normalizer, mixed $object, ?string $format = null, array $context = []): mixed
    {
        if ($this->mergeObjects && \is_object($object)) {
            if (null === $this->currentBindPoint) {
                $inlineProperty = $context['inline_property'] ?? null;
                $mergeKey = \spl_object_id($this->currentObject);
                if (null !== $inlineProperty) {
                    $mergeKey = \sprintf('%d.%s', $mergeKey, $inlineProperty);
                }
                if (isset($this->objectBindIndices[$mergeKey])) {
                    $index = $this->objectBindIndices[$mergeKey];
                    $this->currentBindPoint = [self::INDEX_KEY => $index];
                    $this->currentBindIndex = $index;
                    ++$this->objectBinds[$index][2];
                    $this->currentBindPoint = &$this->objectBinds[$index][0];
                } else {
                    $this->currentMergeKey = $mergeKey;
                }
            } else {
                $index = self::tryGetStubBindIndex($this->currentBindPoint);
                if (null !== $index) {
                    $this->currentBindIndex = $index;
                    ++$this->objectBinds[$index][2];
                    $this->currentBindPoint = &$this->objectBinds[$index][0];
                }
            }
        }

        return $normalizer->normalize($object, $format, $context);
    }

    public static function tryGetStubBindIndex(mixed $value): ?int
    {
        return \is_array($value) && 1 === \count($value) && [self::INDEX_KEY] === \array_keys($value)
            ? $value[self::INDEX_KEY]
            : null;
    }
}
