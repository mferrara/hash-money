<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Strategies\PdqHashStrategy;

/**
 * PDQ hash facade.
 *
 * 256-bit perceptual hash from Meta ThreatExchange, designed for industrial-scale
 * near-duplicate image detection. Uniquely among the algorithms in this package,
 * PDQ also reports a quality score in [0, 100] (accessible via
 * {@see PdqHash::quality()} or {@see HashValue::getMetadata()}); Meta recommends
 * discarding hashes with quality below {@see PdqHashStrategy::RECOMMENDED_QUALITY_THRESHOLD}
 * and treating two hashes as a near-duplicate when their Hamming distance is at
 * most {@see PdqHashStrategy::RECOMMENDED_DISTANCE_THRESHOLD} of 256 bits.
 */
class PdqHash
{
    private static ?PdqHashStrategy $strategy = null;

    private static function getStrategy(): PdqHashStrategy
    {
        if (self::$strategy === null) {
            self::$strategy = new PdqHashStrategy;
        }

        return self::$strategy;
    }

    public static function configure(array $config): void
    {
        self::getStrategy()->configure($config);
    }

    public static function hashFromFile(string $filePath, int $bits = PdqHashStrategy::HASH_BITS): HashValue
    {
        return self::getStrategy()->hashFromFile($filePath, $bits);
    }

    public static function hashFromString(string $imageData, int $bits = PdqHashStrategy::HASH_BITS, array $options = []): HashValue
    {
        return self::getStrategy()->hashFromString($imageData, $bits, $options);
    }

    public static function hashFromVipsImage(VipsImage $image, int $bits = PdqHashStrategy::HASH_BITS): HashValue
    {
        return self::getStrategy()->hashFromVipsImage($image, $bits);
    }

    public static function distance(HashValue $hash1, HashValue $hash2): int
    {
        return self::getStrategy()->distance($hash1, $hash2);
    }

    /**
     * Compute all eight dihedral hashes (original + 3 rotations + 4 flips) and
     * the shared quality score in one pass.
     *
     * @return array{hashes: array<string, HashValue>, quality: int}
     */
    public static function hashesFromFile(string $filePath): array
    {
        return self::getStrategy()->hashesFromFile($filePath);
    }

    /**
     * @return array{hashes: array<string, HashValue>, quality: int}
     */
    public static function hashesFromString(string $imageData, array $options = []): array
    {
        return self::getStrategy()->hashesFromString($imageData, $options);
    }

    /**
     * @return array{hashes: array<string, HashValue>, quality: int}
     */
    public static function hashesFromVipsImage(VipsImage $image): array
    {
        return self::getStrategy()->hashesFromVipsImage($image);
    }

    /**
     * Convenience accessor for the PDQ quality score stored in HashValue metadata.
     */
    public static function quality(HashValue $hash): int
    {
        return PdqHashStrategy::quality($hash);
    }
}
