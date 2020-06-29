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

class RequestDecoderListener
{
    /** @var DecoderInterface */
    private $decoder;

    /** @var array */
    private $context;

    public function __construct(DecoderInterface $decoder, array $context = [])
    {
        $this->decoder = $decoder;
        $this->context = [
            'json_decode_associative' => true,
        ] + $context;
    }

    public function onKernelRequest(GetResponseEvent $event): void
    {
        $request = $event->getRequest();
        // https://silex.symfony.com/doc/2.0/cookbook/json_request_body.html#parsing-the-request-body
        if (\preg_match('#^[a-z0-9.-]+/(?:[a-z0-9.-]+\+)?(?:vnd\.|x-)?([a-z0-9.-]+)#i', $request->headers->get('Content-Type'), $matches)) {
            if ($this->decoder->supportsDecoding($matches[1])) {
                $data = $this->decoder->decode($request->getContent(), $matches[1], $this->context);
                $request->request->replace((array) $data);
            }
        }
    }
}
