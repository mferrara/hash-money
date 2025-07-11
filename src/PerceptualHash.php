<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Strategies\PerceptualHashStrategy;

/**
 * Perceptual Hash facade for backward compatibility and simplified API.
 *
 * This class provides a static interface to the perceptual hash algorithm,
 * delegating actual implementation to PerceptualHashStrategy.
 */
class PerceptualHash
{
    private static ?PerceptualHashStrategy $strategy = null;

    private static function getStrategy(): PerceptualHashStrategy
    {
        if (self::$strategy === null) {
            self::$strategy = new PerceptualHashStrategy;
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
