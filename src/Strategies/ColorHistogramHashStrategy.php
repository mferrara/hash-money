<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Strategies;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\HashValue;

/**
 * Color Histogram Hash Strategy
 *
 * Generates a 64-bit hash based on HSV color histogram quantization.
 * This hash captures global color distribution patterns and is robust to:
 * - Illumination changes (by using HSV color space)
 * - Small color shifts
 * - JPEG compression artifacts
 *
 * The algorithm works by:
 * 1. Converting image to HSV color space
 * 2. Quantizing H,S,V channels (8x4x4 bins by default)
 * 3. Building normalized histogram
 * 4. Encoding histogram into 64-bit binary hash
 */
class ColorHistogramHashStrategy extends AbstractHashStrategy
{
    private const ALGORITHM_NAME = 'color-histogram';

    // Default quantization levels for H,S,V channels
    private const DEFAULT_H_BINS = 8;  // Hue: 8 bins (45° each)

    private const DEFAULT_S_BINS = 4;  // Saturation: 4 bins

    private const DEFAULT_V_BINS = 4;  // Value: 4 bins

    // Total bins = 8 * 4 * 4 = 128 bins
    // We'll select the most significant 64 bins for the hash

    private int $hBins = self::DEFAULT_H_BINS;

    private int $sBins = self::DEFAULT_S_BINS;

    private int $vBins = self::DEFAULT_V_BINS;

    private bool $isGrayscale = false;

    public function getAlgorithmName(): string
    {
        return self::ALGORITHM_NAME;
    }

    public function hashFromFile(string $filePath, int $bits = 64): HashValue
    {
        if ($bits !== 64) {
            throw new \InvalidArgumentException('Color histogram hash only supports 64-bit hashes');
        }

        $this->initVips();

        try {
            // Load image to check if it's grayscale
            $originalImage = VipsImage::newFromFile($filePath);
            $this->isGrayscale = $originalImage->bands === 1 || $originalImage->interpretation === 'b-w';

            // Now do the thumbnail
            $size = $this->getImageSizeForBits($bits);
            $image = VipsImage::thumbnail($filePath, $size['width'], [
                'height' => $size['height'],
                'size' => 'force',
                'linear' => true,
                'import_profile' => 'srgb',
                'export_profile' => 'srgb',
            ]);

            return $this->hashFromVipsImage($image, $bits);
        } catch (\Exception $e) {
            throw new \Exception('Failed to generate hash: '.$e->getMessage());
        }
    }

    public function hashFromString(string $imageData, int $bits = 64, array $options = []): HashValue
    {
        if ($bits !== 64) {
            throw new \InvalidArgumentException('Color histogram hash only supports 64-bit hashes');
        }

        $this->initVips();

        try {
            // Load image to check if it's grayscale
            $originalImage = VipsImage::newFromBuffer($imageData);
            $this->isGrayscale = $originalImage->bands === 1 || $originalImage->interpretation === 'b-w';

            // Now do the thumbnail
            $size = $this->getImageSizeForBits($bits);
            $image = VipsImage::thumbnail_buffer($imageData, $size['width'], array_merge([
                'height' => $size['height'],
                'size' => 'force',
                'linear' => true,
                'import_profile' => 'srgb',
                'export_profile' => 'srgb',
            ], $options));

            return $this->hashFromVipsImage($image, $bits);
        } catch (\Exception $e) {
            throw new \Exception('Failed to generate hash from buffer: '.$e->getMessage());
        }
    }

    protected function getImageSizeForBits(int $bits): array
    {
        if ($bits !== 64) {
            throw new \InvalidArgumentException('Color histogram hash only supports 64-bit hashes');
        }

        // For color histogram, we don't need a specific size
        // but we'll resize to a reasonable size for performance
        return ['width' => 256, 'height' => 256];
    }

    /**
     * Configure the quantization levels for HSV channels.
     */
    public function configureQuantization(int $hBins, int $sBins, int $vBins): void
    {
        $this->hBins = $hBins;
        $this->sBins = $sBins;
        $this->vBins = $vBins;
    }

    public function hashFromVipsImage(VipsImage $image, int $bits = 64): HashValue
    {
        if ($bits !== 64) {
            throw new \InvalidArgumentException('Color histogram hash only supports 64-bit hashes');
        }

        // Convert to HSV color space
        $hsvImage = $this->convertToHSV($image);

        // Extract and quantize histogram
        $histogram = $this->computeQuantizedHistogram($hsvImage);

        // Generate hash from histogram
        $hashValue = $this->generateHashFromHistogram($histogram);

        return new HashValue($hashValue, $bits, $this->getAlgorithmName());
    }

    /**
     * Convert image to HSV color space.
     */
    private function convertToHSV(VipsImage $image): VipsImage
    {
        // Flatten alpha channel if present
        if ($image->hasAlpha()) {
            $image = $image->flatten(['background' => [255, 255, 255]]);
        }

        if ($this->isGrayscale) {
            // For grayscale images, convert to RGB first to enable HSV conversion
            $image = $image->colourspace('srgb');
        } else {
            // Ensure we're in sRGB before converting to HSV
            $currentInterpretation = $image->interpretation;
            if ($currentInterpretation !== 'srgb' && $currentInterpretation !== 'rgb') {
                $image = $image->colourspace('srgb');
            }
        }

        // Convert to HSV using VIPS
        // Note: VIPS HSV conversion may have issues with sequential access
        // We'll use a workaround by copying to memory first
        $image = $image->copyMemory();

        return $image->colourspace('hsv');
    }

    /**
     * Compute quantized histogram from HSV image.
     */
    private function computeQuantizedHistogram(VipsImage $image): array
    {
        $width = $image->width;
        $height = $image->height;
        $totalPixels = $width * $height;

        // Initialize histogram bins
        $totalBins = $this->hBins * $this->sBins * $this->vBins;
        $histogram = array_fill(0, $totalBins, 0);

        // Extract HSV channels
        $hChannel = $image->extract_band(0);
        $sChannel = $image->extract_band(1);
        $vChannel = $image->extract_band(2);

        // Get pixel arrays
        $hPixels = $hChannel->writeToArray();
        $sPixels = $sChannel->writeToArray();
        $vPixels = $vChannel->writeToArray();

        // Quantize and build histogram
        for ($i = 0; $i < count($hPixels); $i++) {
            // HSV ranges in VIPS:
            // H: 0-360 (degrees)
            // S: 0-1 (normalized)
            // V: 0-255 (8-bit)

            // Quantize hue (0-360 to 0-7)
            $hBin = min((int) ($hPixels[$i] / 360.0 * $this->hBins), $this->hBins - 1);

            // Quantize saturation (0-1 to 0-3)
            $sBin = min((int) ($sPixels[$i] * $this->sBins), $this->sBins - 1);

            // Quantize value (0-255 to 0-3)
            $vBin = min((int) ($vPixels[$i] / 255.0 * $this->vBins), $this->vBins - 1);

            // Calculate bin index
            $binIndex = $hBin * ($this->sBins * $this->vBins) + $sBin * $this->vBins + $vBin;
            $histogram[$binIndex]++;
        }

        // Normalize histogram
        for ($i = 0; $i < $totalBins; $i++) {
            $histogram[$i] = $histogram[$i] / $totalPixels;
        }

        return $histogram;
    }

    /**
     * Generate 64-bit hash from histogram using statistical encoding.
     */
    private function generateHashFromHistogram(array $histogram): int
    {
        $hash = 0;
        $totalBins = count($histogram);

        // For grayscale images, use a special encoding
        if ($this->isGrayscale) {
            // Set bit 63 to indicate this is a grayscale image
            $hash |= (1 << 63);

            // For grayscale, focus on value distribution
            $valueBins = $this->vBins;
            $valueDistribution = array_fill(0, $valueBins, 0);

            // Sum up bins by value level
            for ($i = 0; $i < $totalBins; $i++) {
                $vBin = $i % $this->vBins;
                $valueDistribution[$vBin] += $histogram[$i];
            }

            // Encode value distribution across bits 0-31
            for ($v = 0; $v < $valueBins && $v < 32; $v++) {
                $threshold = 1.0 / $valueBins;
                if ($valueDistribution[$v] > $threshold * 0.5) {
                    $hash |= (1 << $v);
                }
                // Also encode relative magnitude in upper bits
                $magnitude = min((int) ($valueDistribution[$v] * 8), 7);
                $hash |= ($magnitude << (32 + $v * 2));
            }

            return $this->mixBits($hash);
        }

        // For color images: Create indexed bins with their values
        $indexedBins = [];
        for ($i = 0; $i < $totalBins; $i++) {
            if ($histogram[$i] > 0) {
                $indexedBins[] = [
                    'index' => $i,
                    'value' => $histogram[$i],
                    'hBin' => (int) ($i / ($this->sBins * $this->vBins)),
                    'sBin' => (int) (($i % ($this->sBins * $this->vBins)) / $this->vBins),
                    'vBin' => $i % $this->vBins,
                ];
            }
        }

        // Sort by value (most significant bins first)
        usort($indexedBins, function ($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        // Method 1: Encode top N bins' indices (bits 0-31)
        $topN = min(32, count($indexedBins));
        for ($i = 0; $i < $topN; $i++) {
            $bin = $indexedBins[$i];
            // Use bin index modulo 32 to determine which bit to set
            $bitPos = $bin['index'] % 32;
            $hash |= (1 << $bitPos);
        }

        // Method 2: Encode color channel dominance (bits 32-47)
        $hueSum = [0, 0, 0]; // Low, mid, high
        $satSum = [0, 0]; // Low, high
        $valSum = [0, 0]; // Low, high

        foreach ($indexedBins as $bin) {
            // Hue distribution
            if ($bin['hBin'] < $this->hBins / 3) {
                $hueSum[0] += $bin['value'];
            } elseif ($bin['hBin'] < 2 * $this->hBins / 3) {
                $hueSum[1] += $bin['value'];
            } else {
                $hueSum[2] += $bin['value'];
            }

            // Saturation distribution
            if ($bin['sBin'] < $this->sBins / 2) {
                $satSum[0] += $bin['value'];
            } else {
                $satSum[1] += $bin['value'];
            }

            // Value distribution
            if ($bin['vBin'] < $this->vBins / 2) {
                $valSum[0] += $bin['value'];
            } else {
                $valSum[1] += $bin['value'];
            }
        }

        // Encode channel dominance
        $bitOffset = 32;

        // Hue dominance (3 bits)
        $maxHue = max($hueSum);
        for ($i = 0; $i < 3; $i++) {
            if ($hueSum[$i] > $maxHue * 0.5) {
                $hash |= (1 << ($bitOffset + $i));
            }
        }
        $bitOffset += 3;

        // Saturation dominance (2 bits)
        if ($satSum[0] > $satSum[1]) {
            $hash |= (1 << $bitOffset);
        }
        if ($satSum[1] > $satSum[0] * 0.7) {
            $hash |= (1 << ($bitOffset + 1));
        }
        $bitOffset += 2;

        // Value dominance (2 bits)
        if ($valSum[0] > $valSum[1]) {
            $hash |= (1 << $bitOffset);
        }
        if ($valSum[1] > $valSum[0] * 0.7) {
            $hash |= (1 << ($bitOffset + 1));
        }

        // Method 3: Statistical features (bits 48-63)
        // Calculate and encode statistical properties
        $nonZeroBins = count($indexedBins);
        $maxBinValue = $indexedBins[0]['value'] ?? 0;
        $totalValue = array_sum(array_column($indexedBins, 'value'));

        // Encode number of active bins (6 bits)
        $activeBinsEncoded = min($nonZeroBins, 63);
        $hash |= ($activeBinsEncoded << 48);

        // Encode concentration (how concentrated the histogram is)
        if ($maxBinValue > $totalValue * 0.5) {
            $hash |= (1 << 54); // Highly concentrated
        } elseif ($maxBinValue > $totalValue * 0.25) {
            $hash |= (1 << 55); // Moderately concentrated
        }

        // Encode diversity
        if ($nonZeroBins > $totalBins * 0.75) {
            $hash |= (1 << 56); // High diversity
        } elseif ($nonZeroBins > $totalBins * 0.5) {
            $hash |= (1 << 57); // Medium diversity
        }

        // Apply bit mixing for better distribution
        return $this->mixBits($hash);
    }

    /**
     * Mix bits for better distribution using MurmurHash-inspired mixing.
     */
    private function mixBits(int $hash): int
    {
        // Simplified bit mixing for PHP to avoid overflow issues
        // Using XOR and rotation operations instead of multiplication

        // Mix high bits with low bits
        $hash ^= ($hash >> 33);

        // Rotate and mix
        $hash = (($hash << 13) | ($hash >> 51)) ^ $hash;
        $hash = (($hash << 17) | ($hash >> 47)) ^ $hash;

        // Final mix
        $hash ^= ($hash >> 32);
        $hash = (($hash << 5) | ($hash >> 59)) ^ $hash;
        $hash ^= ($hash >> 29);

        return $hash;
    }
}
