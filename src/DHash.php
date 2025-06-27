<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Strategies\DHashStrategy;

/**
 * DHash (Difference Hash) facade for simplified API.
 *
 * DHash is a gradient-based perceptual hash algorithm that compares
 * adjacent pixels to generate a hash. It's faster than DCT-based
 * perceptual hashing but may be slightly less accurate.
 */
class DHash
{
    private static ?DHashStrategy $strategy = null;

    private static function getStrategy(): DHashStrategy
    {
        if (self::$strategy === null) {
            self::$strategy = new DHashStrategy;
        }

        return self::$strategy;
    }

    public static function configure(array $config): void
    {
        self::getStrategy()->configure($config);
    }

    public static function hashFromFile(string $filePath, int $bits = 64): HashValue
    {
        return self::getStrategy()->hashFromFile($filePath, $bits);
    }

    public static function hashFromString(string $imageData, int $bits = 64, array $options = []): HashValue
    {
        return self::getStrategy()->hashFromString($imageData, $bits, $options);
    }

    public static function hashFromVipsImage(VipsImage $image, int $bits = 64): HashValue
    {
        return self::getStrategy()->hashFromVipsImage($image, $bits);
    }

    public static function distance(HashValue $hash1, HashValue $hash2): int
    {
        return self::getStrategy()->distance($hash1, $hash2);
    }
}
