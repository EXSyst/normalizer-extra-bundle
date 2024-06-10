<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle;

/**
 * @internal
 */
final readonly class BitOperations
{
    private function __construct()
    {
    }

    /**
     * @param int[] $mask
     * @param int[] $mask2
     *
     * @return int[]
     */
    public static function unionMask(array $mask, array $mask2): array
    {
        foreach ($mask2 as $k => $v) {
            $mask[$k] = ($mask[$k] ?? 0) | $v;
        }

        return $mask;
    }

    /**
     * @param int[] $mask
     * @param int[] $mask2
     *
     * @return int[]
     */
    public static function intersectMask(array $mask, array $mask2): array
    {
        foreach ($mask2 as $k => $v) {
            $mask[$k] = ($mask[$k] ?? 0) & $v;
        }

        return $mask;
    }

    /**
     * @param int[] $mask
     * @param int[] $subtrahend
     *
     * @return int[]
     */
    public static function subtractMask(array $mask, array $subtrahend): array
    {
        foreach ($subtrahend as $k => $v) {
            $mask[$k] = ($mask[$k] ?? 0) & ~$v;
        }

        return $mask;
    }

    public static function countOnes(int $number): int
    {
        $c = $number - (($number >> 1) & 0x5555555555555555);
        $c = (($c >> 2) & 0x3333333333333333) + ($c & 0x3333333333333333);
        $c = (($c >> 4) + $c) & 0x0F0F0F0F0F0F0F0F;
        $c = (($c >> 8) + $c) & 0x00FF00FF00FF00FF;
        $c = (($c >> 16) + $c) & 0x0000FFFF0000FFFF;
        $c = (($c >> 32) + $c) & 0x00000000FFFFFFFF;

        return $c;
    }

    /**
     * @param int[] $mask
     */
    public static function countOnesInMask(array $mask): int
    {
        $ones = 0;
        foreach ($mask as $word) {
            $ones += self::countOnes($word);
        }

        return $ones;
    }

    public static function countTrailingZeros(int $number): int
    {
        static $deBruijn32 = [
            0, 1, 28, 2, 29, 14, 24, 3, 30, 22, 20, 15, 25, 17, 4, 8,
            31, 27, 13, 23, 21, 19, 16, 7, 26, 12, 18, 6, 11, 5, 10, 9,
        ];

        if (0 === $number) {
            return 64;
        }

        $number &= -$number;

        return 0 !== ($number & 0xFFFFFFFF)
            ? $deBruijn32[(($number * 0x077CB531) >> 27) & 0x1F]
            : 32 + $deBruijn32[((($number >> 32) * 0x077CB531) >> 27) & 0x1F];
    }

    public static function enumerateOnes(int $number): \Generator
    {
        while (0 !== $number) {
            $index = self::countTrailingZeros($number);
            yield $index;
            $number &= ~(1 << $index);
        }
    }

    /**
     * @param int[] $mask
     */
    public static function enumerateOnesInMask(array $mask): \Generator
    {
        foreach ($mask as $i => $word) {
            while (0 !== $word) {
                $index = self::countTrailingZeros($word);
                yield [$i, $index];
                $word &= ~(1 << $index);
            }
        }
    }

    public static function fnv1a32(string $str): int
    {
        $hash = 0x811C9DC5;
        foreach (\unpack('C*', $str) as $c) {
            $hash = (($hash ^ $c) * 0x01000193) & 0xFFFFFFFF;
        }

        return $hash;
    }
}
