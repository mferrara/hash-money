<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Strategies\BlockMeanHashStrategy;

/**
 * BlockMeanHash facade. See BlockMeanHashStrategy for algorithm details.
 *
 * Useful as a 4th chunk in a composite hash (alongside pHash, dHash, and a
 * color hash) because its spatial-domain signal is statistically independent
 * from DCT- and gradient-based hashes, which helps LSH candidate filtering.
 */
class BlockMeanHash
{
    private static ?BlockMeanHashStrategy $strategy = null;

    private static function getStrategy(): BlockMeanHashStrategy
    {
        if (self::$strategy === null) {
            self::$strategy = new BlockMeanHashStrategy;
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
