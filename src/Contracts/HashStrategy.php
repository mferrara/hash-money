<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Contracts;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\HashValue;

interface HashStrategy
{
    /**
     * Generate a hash from a file path.
     */
    public function hashFromFile(string $filePath, int $bits = 64): HashValue;

    /**
     * Generate a hash from image data in memory.
     */
    public function hashFromString(string $imageData, int $bits = 64, array $options = []): HashValue;

    /**
     * Generate a hash from a VIPS image.
     */
    public function hashFromVipsImage(VipsImage $image, int $bits = 64): HashValue;

    /**
     * Calculate the Hamming distance between two hash values.
     *
     * @throws \InvalidArgumentException if hashes are not compatible
     */
    public function distance(HashValue $hash1, HashValue $hash2): int;

    /**
     * Get the algorithm name for this strategy.
     */
    public function getAlgorithmName(): string;

    /**
     * Configure VIPS settings for this strategy.
     */
    public function configure(array $config): void;
}
