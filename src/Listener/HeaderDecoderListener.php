<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Listener;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class HeaderDecoderListener
{
    /** @var string[] */
    private array $headerNames;

    private string $attributeName;
    private bool $allowFromQuery;
    private DecoderInterface $decoder;
    private ?DenormalizerInterface $denormalizer;
    private ?string $class;
    private string $format;
    private array $context;

    public function __construct(string $headerName, string $attributeName, bool $allowFromQuery, DecoderInterface $decoder, ?DenormalizerInterface $denormalizer = null, ?string $class = null, string $format = 'json', array $context = [], string ...$altHeaderNames)
    {
        if (null !== $class && null === $denormalizer) {
            throw new \LogicException(\sprintf('Cannot instantiate a HeaderDecoderListener(%s, %s, ...) with a class name (%s) but without a denormalizer', $headerName, $attributeName, $class));
        }

        $this->headerNames = array_merge([$headerName], $altHeaderNames);
        $this->attributeName = $attributeName;
        $this->allowFromQuery = $allowFromQuery;
        $this->decoder = $decoder;
        $this->denormalizer = $denormalizer;
        $this->class = $class;
        $this->format = $format;
        $this->context = $context;
    }

    public function getHeaderName(): string
    {
        return reset($this->headerNames);
    }

    public function getHeaderNames(): array
    {
        return $this->headerNames;
    }

    public function getAttributeName(): string
    {
        return $this->attributeName;
    }

    public function onKernelRequest(GetResponseEvent $event): void
    {
        $request = $event->getRequest();
        foreach ($this->headerNames as $headerName) {
            if ($request->headers->has($headerName)) {
                $value = $this->deserialize($request->headers->get($headerName));
                $request->attributes->set($this->attributeName, $value);

                return;
            }
        }

        if ($this->allowFromQuery && $request->query->has('_'.$this->attributeName)) {
            $value = $this->deserialize($request->query->get('_'.$this->attributeName));
            $request->attributes->set($this->attributeName, $value);
        }
    }

    private function deserialize(string $data)
    {
        $value = $this->decoder->decode($data, $this->format, $this->context);
        if (null !== $this->class) {
            $value = $this->denormalizer->denormalize($value, $this->class, $this->format, $this->context);
        }

        return $value;
    }
}
