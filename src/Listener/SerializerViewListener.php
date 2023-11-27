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

use EXSyst\NormalizerExtraBundle\Http\ResponseData;
use EXSyst\NormalizerExtraBundle\Reflection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;

class SerializerViewListener
{
    private SerializerInterface $serializer;
    private array $context;
    private \SplObjectStorage $controllers;

    public function __construct(SerializerInterface $serializer, array $context = [])
    {
        $this->serializer = $serializer;
        $this->context = $context + [
            'check_authorizations' => true,
        ];
        $this->controllers = new \SplObjectStorage();
    }

    public function onKernelController(FilterControllerEvent $event)
    {
        $this->controllers[$event->getRequest()] = $event->getController();
    }

    public function onKernelView(GetResponseForControllerResultEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }

        $data = $event->getControllerResult();
        $request = $event->getRequest();
        $controller = $this->controllers[$event->getRequest()];
        unset($this->controllers[$event->getRequest()]);
        if (null === $data) {
            $reflector = Reflection::getCallableReflector($controller);
            if (null !== $reflector->getReturnType()) {
                $event->setResponse(self::noContent());
            }

            return;
        }

        if (!($data instanceof ResponseData)) {
            $data = new ResponseData($data);
        }

        if ($data->hasNoContent()) {
            $event->setResponse(self::noContent($data->getHeaders()));

            return;
        }

        $format = $request->getRequestFormat('json');
        try {
            $serialized = $this->serializer->serialize($data->getContent(), $format, $data->getContext() + $this->getContext($request) + $this->context);
        } catch (NotEncodableValueException $e) {
            throw new NotFoundHttpException('This resource is not available in the requested format.', $e);
        }

        $response = new Response($serialized, $data->getStatus(), [
            'Content-Type' => $request->getMimeType($format),
        ] + $data->getHeaders());
        $event->setResponse($response);
    }

    protected function getContext(Request $request): array
    {
        $groups = ['default'];
        if ($request->attributes->has('_route')) {
            $groups[] = 'default.'.$request->attributes->get('_route');
        }

        return [
            'groups'         => $groups,
            'allowed_groups' => array_merge(['public'], $groups),
            'shape'          => $request->attributes->get('shape'),
        ];
    }

    public static function noContent(array $headers = []): Response
    {
        return new Response('', 204, $headers);
    }
}
