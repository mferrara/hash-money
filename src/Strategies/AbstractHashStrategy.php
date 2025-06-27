<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Strategies;

use Exception;
use InvalidArgumentException;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Contracts\HashStrategy;
use LegitPHP\HashMoney\HashValue;

abstract class AbstractHashStrategy implements HashStrategy
{
    protected static bool $vipsInitialized = false;

    protected static array $config = [
        'cores' => null,
        'maxCacheSize' => 64,
        'maxMemory' => 256,
        'sequentialAccess' => true,
        'disableCache' => false,
    ];

    public function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);

        if (self::$vipsInitialized) {
            self::$vipsInitialized = false;
            $this->initVips();
        }
    }

    protected function initVips(): void
    {
        if (! self::$vipsInitialized) {
            $cores = self::$config['cores'] ?? min(8, max(2, $this->getNumCores()));
            Config::concurrencySet($cores);
            Config::cacheSetMax(self::$config['maxCacheSize']);
            Config::cacheSetMaxMem(self::$config['maxMemory']);

            if (self::$config['disableCache']) {
                Config::cacheSetMax(0);
            }

            self::$vipsInitialized = true;
        }
    }

    public function hashFromFile(string $filePath, int $bits = 64): HashValue
    {
        $this->initVips();

        try {
            $size = $this->getImageSizeForBits($bits);
            $image = VipsImage::thumbnail($filePath, $size['width'], [
                'height' => $size['height'],
                'size' => 'force',
                'linear' => true,
                'import_profile' => 'srgb',
                'export_profile' => 'srgb',
            ]);

            return $this->hashFromVipsImage($image, $bits);
        } catch (Exception $e) {
            throw new Exception('Failed to generate hash: '.$e->getMessage());
        }
    }

    public function hashFromString(string $imageData, int $bits = 64, array $options = []): HashValue
    {
        $this->initVips();

        try {
            $size = $this->getImageSizeForBits($bits);
            $image = VipsImage::thumbnail_buffer($imageData, $size['width'], array_merge([
                'height' => $size['height'],
                'size' => 'force',
                'linear' => true,
                'import_profile' => 'srgb',
                'export_profile' => 'srgb',
            ], $options));

            return $this->hashFromVipsImage($image, $bits);
        } catch (Exception $e) {
            throw new Exception('Failed to generate hash from buffer: '.$e->getMessage());
        }
    }

    public function distance(HashValue $hash1, HashValue $hash2): int
    {
        if (! $hash1->isCompatibleWith($hash2)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot compare hashes: algorithm mismatch (%s vs %s) or bit size mismatch (%d vs %d)',
                $hash1->getAlgorithm(),
                $hash2->getAlgorithm(),
                $hash1->getBits(),
                $hash2->getBits()
            ));
        }

        $diff = $hash1->getValue() ^ $hash2->getValue();
        $distance = 0;

        // Count set bits (Hamming distance)
        for ($i = 0; $i < $hash1->getBits(); $i++) {
            if (($diff >> $i) & 1) {
                $distance++;
            }
        }

        return $distance;
    }

    protected function getNumCores(): int
    {
        $cores = 2;

        if (is_file('/proc/cpuinfo') && is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $count = substr_count($cpuinfo, 'processor');
            if ($count > 0) {
                return $count;
            }
        }

        if (PHP_OS === 'Darwin' && function_exists('shell_exec')) {
            $count = intval(shell_exec('sysctl -n hw.ncpu'));
            if ($count > 0) {
                return $count;
            }
        }

        return $cores;
    }

    /**
     * Convert image to grayscale, handling alpha channels and ensuring proper colorspace.
     */
    protected function convertToGrayscale(VipsImage $image): VipsImage
    {
        // Flatten alpha channel if present (bands == 2 or 4)
        if ($image->hasAlpha()) {
            // Flatten against white background
            $image = $image->flatten(['background' => [255, 255, 255]]);
        }

        // Always convert to grayscale to ensure consistent colorspace
        $image = $image->colourspace('b-w');

        // Cast to uint8 for compatibility with older libvips versions
        if ($image->format !== 'uchar') {
            $image = $image->cast('uchar');
        }

        return $image;
    }

    /**
     * Get the required image dimensions for the specified bit size.
     * This method should be overridden by concrete strategies.
     */
    abstract protected function getImageSizeForBits(int $bits): array;

    /**
     * Extract pixels from image into a 2D array.
     */
    protected function extractPixelsAs2DArray(VipsImage $image, int $width, int $height): array
    {
        $pixels = $image->writeToArray();
        $expectedLength = $width * $height;

        if (count($pixels) !== $expectedLength) {
            throw new \RuntimeException(sprintf(
                'Pixel data size mismatch. Expected %d values, got %d values.',
                $expectedLength,
                count($pixels)
            ));
        }

        $matrix = [];
        $i = 0;

        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $row[] = $pixels[$i++];
            }
            $matrix[] = $row;
        }

        return $matrix;
    }
}
