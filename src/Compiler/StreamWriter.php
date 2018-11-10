<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Compiler;

class StreamWriter
{
    /** @var resource */
    private $fd;

    /** @var string */
    private $indent;

    public function __construct($fd)
    {
        $this->fd = $fd;
        $this->indent = '';
    }

    public function indent(): self
    {
        $this->indent .= '    ';

        return $this;
    }

    public function outdent(): self
    {
        $this->indent = \substr($this->indent, 0, -4);

        return $this;
    }

    public function printfln(string $format = '', ...$args)
    {
        if (!empty($format)) {
            \fwrite($this->fd, $this->indent);
            \fprintf($this->fd, $format, ...$args);
        }
        \fwrite($this->fd, "\n");

        return $this;
    }
}
