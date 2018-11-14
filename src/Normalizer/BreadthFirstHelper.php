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

    /** @var mixed */
    private $root;

    /** @var InitializerInterface[] */
    private $initializers;

    /** @var array[] */
    private $queue;

    /** @var mixed */
    private $currentBindPoint;

    /** @var mixed */
    private $currentObject;

    public function __construct($root = null)
    {
        $this->root = $root;
        $this->initializers = [];
        $this->queue = [];
    }

    public function getRoot()
    {
        return $this->root;
    }

    public function &getCurrentBindPoint()
    {
        return $this->currentBindPoint;
    }

    public function getCurrentObject()
    {
        return $this->currentObject;
    }

    public function registerInitializer(InitializerInterface $initializer): self
    {
        $this->initializers[\spl_object_hash($initializer)] = $initializer;

        return $this;
    }

    public function bind(&$point, NormalizerInterface $normalizer, $object, ?string $format = null, array $context = []): self
    {
        $this->queue[] = [&$point, $normalizer, $object, $format, $context];

        return $this;
    }

    public function resolve()
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
                $result = $instruction[1]->normalize($instruction[2], $instruction[3], $instruction[4]);
                if (null !== $result) {
                    $this->currentBindPoint = $result;
                }
            }
            unset($this->currentBindPoint);
            unset($this->currentObject);
        }

        return $this;
    }
}
