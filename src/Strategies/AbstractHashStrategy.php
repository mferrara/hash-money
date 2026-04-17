<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Strategies;

use Exception;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Contracts\HashStrategy;
use LegitPHP\HashMoney\HashValue;

abstract class AbstractHashStrategy implements HashStrategy
{
    private static array $configs = [];

    private static array $initialized = [];

    private const DEFAULT_CONFIG = [
        'cores' => null,
        'maxCacheSize' => 64,        // Max cached operations (libvips vips_cache_set_max)
        'maxMemory' => 256,          // Max cache memory in MB (converted to bytes for libvips)
        'sequentialAccess' => true,
        'disableCache' => false,
    ];

    public function configure(array $config): void
    {
        $class = static::class;
        self::$configs[$class] = array_merge(self::getConfig(), $config);

        if (self::$initialized[$class] ?? false) {
            self::$initialized[$class] = false;
            $this->initVips();
        }
    }

    protected static function getConfig(): array
    {
        return self::$configs[static::class] ?? self::DEFAULT_CONFIG;
    }

    protected function initVips(): void
    {
        $class = static::class;
        if (! (self::$initialized[$class] ?? false)) {
            $config = self::getConfig();
            $cores = $config['cores'] ?? min(8, max(2, $this->getNumCores()));
            Config::concurrencySet($cores);
            Config::cacheSetMax($config['maxCacheSize']);
            Config::cacheSetMaxMem($config['maxMemory'] * 1024 * 1024);

            if ($config['disableCache']) {
                Config::cacheSetMax(0);
            }

            self::$initialized[$class] = true;
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
            throw new \RuntimeException('Failed to generate hash: '.$e->getMessage(), 0, $e);
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
            throw new \RuntimeException('Failed to generate hash from buffer: '.$e->getMessage(), 0, $e);
        }
    }

    public function distance(HashValue $hash1, HashValue $hash2): int
    {
        // Delegate to HashValue's built-in hammingDistance method
        return $hash1->hammingDistance($hash2);
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
