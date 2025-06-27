<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Strategies\ColorHistogramHashStrategy;

/**
 * Color Histogram Hash facade for backward compatibility and simplified API.
 *
 * This class provides a static interface to the color histogram hash algorithm,
 * delegating actual implementation to ColorHistogramHashStrategy.
 *
 * Color histogram hashing captures global color distribution patterns and is
 * particularly useful for:
 * - Finding images with similar color palettes
 * - Detecting color-shifted variants of images
 * - Complementing spatial hashes (pHash, dHash) with color information
 */
class ColorHistogramHash
{
    private static ?ColorHistogramHashStrategy $strategy = null;

    private static function getStrategy(): ColorHistogramHashStrategy
    {
        if (self::$strategy === null) {
            self::$strategy = new ColorHistogramHashStrategy;
        }

        return self::$strategy;
    }

    public static function configure(array $config): void
    {
        self::getStrategy()->configure($config);
    }

    /**
     * Configure the HSV quantization levels.
     *
     * @param  int  $hBins  Number of hue bins (default: 8)
     * @param  int  $sBins  Number of saturation bins (default: 4)
     * @param  int  $vBins  Number of value bins (default: 4)
     */
    public static function configureQuantization(int $hBins, int $sBins, int $vBins): void
    {
        self::getStrategy()->configureQuantization($hBins, $sBins, $vBins);
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
