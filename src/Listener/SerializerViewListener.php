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

use EXSyst\NormalizerExtraBundle\Reflection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;

class SerializerViewListener
{
    /** @var SerializerInterface */
    private $serializer;

    /** @var \SplObjectStorage */
    private $controllers;

    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
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
                $event->setResponse(new Response('', 204));
            }

            return;
        }

        $groups = ['default'];
        if ($request->attributes->has('_route')) {
            $groups[] = 'default.'.$request->attributes->get('_route');
        }
        $format = $request->getRequestFormat('json');
        try {
            $serialized = $this->serializer->serialize($data, $format, [
                'groups'               => $groups,
                'allowed_groups'       => array_merge(['public'], $groups),
                'shape'                => $request->attributes->get('shape'),
                'check_authorizations' => true,
            ]);
        } catch (NotEncodableValueException $e) {
            throw new NotFoundHttpException('This resource is not available in the requested format.', $e);
        }

        $response = new Response($serialized, 200, [
            'Content-Type' => $request->getMimeType($format),
        ]);
        $event->setResponse($response);
    }
}
