<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
;

$header = <<<'EOF'
This file is part of exsyst/normalizer-extra-bundle.

Copyright (C) EXSyst

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony'               => true,
        '@PHP83Migration'        => true,
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'header_comment'         => [
            'header' => $header,
        ],
        'simplified_null_return' => false,
        'array_syntax'           => [
            'syntax' => 'short',
        ],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setUsingCache(true)
    ;
