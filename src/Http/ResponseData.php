<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Http;

class ResponseData
{
    private array $context = [];

    public function __construct(private mixed $content, private int $status = 200, private array $headers = [])
    {
    }

    public static function create($content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, $headers);
    }

    public static function noContent(array $headers = []): self
    {
        return new self(null, 204, $headers);
    }

    public function hasNoContent(): bool
    {
        return null === $this->content && 204 === $this->status;
    }

    public function getContent(): mixed
    {
        return $this->content;
    }

    public function setContent(mixed $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setHeaders(array $headers): static
    {
        $this->headers = $headers;

        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }
}
