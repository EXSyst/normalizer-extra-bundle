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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;

class SerializerExceptionListener
{
    private SerializerInterface $serializer;
    private array $context;

    public function __construct(SerializerInterface $serializer, array $context = [])
    {
        $this->serializer = $serializer;
        $this->context = $context + [
            'shape' => ['code' => null, 'message' => null],
        ];
    }

    public function onKernelException(GetResponseForExceptionEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }

        $exception = $event->getException();
        if (!($exception instanceof HttpExceptionInterface)) {
            return;
        }

        $request = $event->getRequest();
        $format = $request->getRequestFormat('json');
        try {
            $serialized = $this->serializer->serialize($exception, $format, $this->getContext($request) + $this->context);
        } catch (NotEncodableValueException $e) {
            return;
        }

        $response = new Response($serialized, $exception->getStatusCode(), $exception->getHeaders());
        $event->setResponse($response);
    }

    protected function getContext(Request $request): array
    {
        return [];
    }
}
