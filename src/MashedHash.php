<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Strategies\MashedHashStrategy;

/**
 * MashedHash - A comprehensive image fingerprint that "mashes" together multiple characteristics.
 * 
 * This hash combines color, texture, spatial, and structural features into a single 64-bit hash,
 * designed to reduce false positives when comparing images. It's particularly effective for
 * social media images where filters, borders, and overlays are common.
 * 
 * Components include:
 * - Colorfulness level (grayscale detection)
 * - Edge density (texture/detail)
 * - Entropy (complexity)
 * - Aspect ratio classification
 * - Border detection
 * - Color distribution
 * - Spatial layout
 * - Brightness patterns
 * 
 * Use MashedHash alongside pHash/dHash for comprehensive image matching.
 */
class MashedHash
{
    private static ?MashedHashStrategy $strategy = null;

    private static function getStrategy(): MashedHashStrategy
    {
        if (self::$strategy === null) {
            self::$strategy = new MashedHashStrategy;
        }

        return self::$strategy;
    }

    public static function configure(array $config): void
    {
        self::getStrategy()->configure($config);
    }

    /**
     * Generate a MashedHash from an image file.
     * 
     * @param string $filePath Path to the image file
     * @param int $bits Must be 64 (only 64-bit hashes supported)
     * @return HashValue The generated hash
     * @throws \InvalidArgumentException If bits != 64
     * @throws \Exception If hash generation fails
     */
    public static function hashFromFile(string $filePath, int $bits = 64): HashValue
    {
        return self::getStrategy()->hashFromFile($filePath, $bits);
    }

    /**
     * Generate a MashedHash from image data in memory.
     * 
     * @param string $imageData Raw image data
     * @param int $bits Must be 64 (only 64-bit hashes supported)
     * @param array $options Additional options for image loading
     * @return HashValue The generated hash
     * @throws \InvalidArgumentException If bits != 64
     * @throws \Exception If hash generation fails
     */
    public static function hashFromString(string $imageData, int $bits = 64, array $options = []): HashValue
    {
        return self::getStrategy()->hashFromString($imageData, $bits, $options);
    }

    /**
     * Generate a MashedHash from a VIPS image object.
     * 
     * @param VipsImage $image The VIPS image
     * @param int $bits Must be 64 (only 64-bit hashes supported)
     * @return HashValue The generated hash
     * @throws \InvalidArgumentException If bits != 64
     * @throws \Exception If hash generation fails
     */
    public static function hashFromVipsImage(VipsImage $image, int $bits = 64): HashValue
    {
        return self::getStrategy()->hashFromVipsImage($image, $bits);
    }

    /**
     * Calculate the Hamming distance between two MashedHash values.
     * 
     * @param HashValue $hash1 First hash
     * @param HashValue $hash2 Second hash
     * @return int The Hamming distance (0-64)
     * @throws \InvalidArgumentException If hashes are incompatible
     */
    public static function distance(HashValue $hash1, HashValue $hash2): int
    {
        return self::getStrategy()->distance($hash1, $hash2);
    }
}