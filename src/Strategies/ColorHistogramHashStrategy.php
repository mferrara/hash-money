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
            $hBin = min((int)($hPixels[$i] / 360.0 * $this->hBins), $this->hBins - 1);

            // Quantize saturation (0-1 to 0-3)
            $sBin = min((int)($sPixels[$i] * $this->sBins), $this->sBins - 1);

            // Quantize value (0-255 to 0-3)
            $vBin = min((int)($vPixels[$i] / 255.0 * $this->vBins), $this->vBins - 1);

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
        
        // For grayscale images, set special marker bits
        if ($this->isGrayscale) {
            // Set bits 60-63 to indicate this is a grayscale image (pattern: 1111)
            $hash |= (0xF << 60);
            
            // Use bits 4-63 for brightness distribution
            $bitOffset = 4;
            
            // Only use value bins for grayscale
            $valueBins = $this->vBins;
            for ($v = 0; $v < $valueBins && $bitOffset < 64; $v++) {
                $binSum = 0;
                // Sum all bins with this value level
                for ($i = 0; $i < $totalBins; $i++) {
                    if ($i % $this->vBins === $v) {
                        $binSum += $histogram[$i];
                    }
                }
                
                // Encode value distribution
                $threshold = 1.0 / $valueBins;
                if ($binSum > $threshold * 0.5) {
                    $hash |= (1 << $bitOffset);
                }
                $bitOffset++;
            }
            
            return $hash;
        }
        
        // Original color encoding for color images
        // Ensure bits 60-63 are NOT set for color images
        // Calculate mean value for thresholding
        $mean = array_sum($histogram) / $totalBins;
        
        // Group bins by color characteristics
        // We'll encode presence of significant colors in different regions
        $bitsPerRegion = 16;
        $regions = [
            'hue_low' => [],     // Low hue values (reds)
            'hue_mid' => [],     // Mid hue values (greens)
            'hue_high' => [],    // High hue values (blues)
            'saturation' => [],  // Saturation characteristics
        ];
        
        // Distribute bins into regions based on their position
        for ($i = 0; $i < $totalBins; $i++) {
            $hBin = (int)($i / ($this->sBins * $this->vBins));
            $sBin = (int)(($i % ($this->sBins * $this->vBins)) / $this->vBins);
            
            if ($hBin < $this->hBins / 3) {
                $regions['hue_low'][] = ['index' => $i, 'value' => $histogram[$i]];
            } elseif ($hBin < 2 * $this->hBins / 3) {
                $regions['hue_mid'][] = ['index' => $i, 'value' => $histogram[$i]];
            } else {
                $regions['hue_high'][] = ['index' => $i, 'value' => $histogram[$i]];
            }
            
            // Also track saturation separately
            if ($sBin < $this->sBins / 2) {
                $regions['saturation'][] = ['index' => $i, 'value' => $histogram[$i]];
            }
        }
        
        // Encode each region into the hash
        $bitOffset = 0;
        foreach ($regions as $regionName => $regionBins) {
            // Sort bins in this region by value
            usort($regionBins, function ($a, $b) {
                return $b['value'] <=> $a['value'];
            });
            
            // Encode top bins from this region
            $regionBitsUsed = 0;
            foreach ($regionBins as $bin) {
                if ($regionBitsUsed >= $bitsPerRegion || $bitOffset >= 64) {
                    break;
                }
                
                // Set bit if this bin has significant value
                if ($bin['value'] > $mean * 0.5) {
                    $hash |= (1 << $bitOffset);
                }
                
                $bitOffset++;
                $regionBitsUsed++;
            }
            
            // Skip to next region's bit offset
            $bitOffset = (($bitOffset / $bitsPerRegion) + 1) * $bitsPerRegion;
            if ($bitOffset >= 64) break;
        }
        
        return $hash;
    }
}