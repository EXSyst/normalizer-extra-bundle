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

class JsonHeaderListener
{
    private $headerNames;
    private $attributeName;
    private $allowFromQuery;

    public function __construct(string $headerName, string $attributeName, bool $allowFromQuery, string ...$altHeaderNames)
    {
        $this->headerNames = array_merge([$headerName], $altHeaderNames);
        $this->attributeName = $attributeName;
        $this->allowFromQuery = $allowFromQuery;
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

    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        foreach ($this->headerNames as $headerName) {
            if ($request->headers->has($headerName)) {
                $value = json_decode($request->headers->get($headerName), true);
                $request->attributes->set($this->attributeName, $value);

                return;
            }
        }
        if ($this->allowFromQuery && $request->query->has('_'.$this->attributeName)) {
            $value = json_decode($request->query->get('_'.$this->attributeName), true);
            $request->attributes->set($this->attributeName, $value);
        }
    }
}
