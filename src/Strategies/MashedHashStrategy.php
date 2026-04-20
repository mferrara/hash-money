<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Strategies;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\HashValue;

/**
 * MashedHash Strategy - A comprehensive image fingerprint combining multiple characteristics.
 *
 * Bit allocation (64 bits total):
 * - Bits 0-3:    Colorfulness level (0-15)      [Gray-coded]
 * - Bits 4-7:    Edge density (0-15)            [Gray-coded]
 * - Bits 8-11:   Entropy/complexity (0-15)      [Gray-coded]
 * - Bits 12-14:  Aspect ratio class (0-7)       [Gray-coded]
 * - Bit 15:      Border flag
 * - Bits 16-31:  Color distribution (16 bits)   [5+5+5 Gray-coded RGB, 1 dominance flag]
 * - Bits 32-39:  Spatial color layout (8 bits)  [categorical, not Gray-coded]
 * - Bits 40-47:  Brightness pattern (8 bits)    [4+4 Gray-coded mean/range]
 * - Bits 48-55:  Texture features (8 bits)      [4+4 Gray-coded H/V]
 * - Bits 56-59:  Dominant color count (0-15)    [Gray-coded]
 * - Bits 60-63:  Special indicators (4 bits)    [bit-flags, not Gray-coded]
 *
 * Ordinal integer fields are stored in reflected-binary Gray code so that
 * adjacent quantization levels differ by exactly one bit. Without Gray
 * coding, a one-level quantization difference between two similar images
 * (e.g. colorfulness 7 vs 8, binary 0111 vs 1000) would flip all four bits
 * in that field, wrecking Hamming-distance-based similarity.
 *
 * Use {@see decode()} to read semantic field values; reading the raw bits
 * directly will see the Gray-coded representation.
 */
class MashedHashStrategy extends AbstractHashStrategy
{
    private const ALGORITHM_NAME = 'mashed';

    // Component bit positions and masks
    private const COLORFULNESS_BITS = 0;

    private const COLORFULNESS_MASK = 0xF;

    private const EDGE_DENSITY_BITS = 4;

    private const EDGE_DENSITY_MASK = 0xF;

    private const ENTROPY_BITS = 8;

    private const ENTROPY_MASK = 0xF;

    private const ASPECT_RATIO_BITS = 12;

    private const ASPECT_RATIO_MASK = 0x7;

    private const BORDER_FLAG_BIT = 15;

    private const COLOR_DIST_BITS = 16;

    private const COLOR_DIST_MASK = 0xFFFF;

    private const SPATIAL_LAYOUT_BITS = 32;

    private const SPATIAL_LAYOUT_MASK = 0xFF;

    private const BRIGHTNESS_BITS = 40;

    private const BRIGHTNESS_MASK = 0xFF;

    private const TEXTURE_BITS = 48;

    private const TEXTURE_MASK = 0xFF;

    private const DOMINANT_COLOR_BITS = 56;

    private const DOMINANT_COLOR_MASK = 0xF;

    private const SPECIAL_BITS = 60;

    private const SPECIAL_MASK = 0xF;

    public function getAlgorithmName(): string
    {
        return self::ALGORITHM_NAME;
    }

    public function hashFromFile(string $filePath, int $bits = 64): HashValue
    {
        if ($bits !== 64) {
            throw new \InvalidArgumentException('MashedHash only supports 64-bit hashes');
        }

        $this->initVips();

        try {
            // Load original image to check grayscale status
            $originalImage = VipsImage::newFromFile($filePath);
            $isGrayscale = $originalImage->bands === 1 || $originalImage->interpretation === 'b-w';

            // Now do thumbnail
            $size = $this->getImageSizeForBits($bits);
            $image = VipsImage::thumbnail($filePath, $size['width'], [
                'height' => $size['height'],
                'size' => 'force',
                'linear' => true,
                'import_profile' => 'srgb',
                'export_profile' => 'srgb',
            ]);

            return $this->hashFromVipsImageWithGrayscale($image, $bits, $isGrayscale);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate hash: '.$e->getMessage(), 0, $e);
        }
    }

    public function hashFromString(string $imageData, int $bits = 64, array $options = []): HashValue
    {
        if ($bits !== 64) {
            throw new \InvalidArgumentException('MashedHash only supports 64-bit hashes');
        }

        $this->initVips();

        try {
            // Load original image to check grayscale status
            $originalImage = VipsImage::newFromBuffer($imageData);
            $isGrayscale = $originalImage->bands === 1 || $originalImage->interpretation === 'b-w';

            // Now do thumbnail
            $size = $this->getImageSizeForBits($bits);
            $image = VipsImage::thumbnail_buffer($imageData, $size['width'], array_merge([
                'height' => $size['height'],
                'size' => 'force',
                'linear' => true,
                'import_profile' => 'srgb',
                'export_profile' => 'srgb',
            ], $options));

            return $this->hashFromVipsImageWithGrayscale($image, $bits, $isGrayscale);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate hash from buffer: '.$e->getMessage(), 0, $e);
        }
    }

    protected function getImageSizeForBits(int $bits): array
    {
        if ($bits !== 64) {
            throw new \InvalidArgumentException('MashedHash only supports 64-bit hashes');
        }

        // Use a moderate size for analysis
        return ['width' => 256, 'height' => 256];
    }

    public function hashFromVipsImage(VipsImage $image, int $bits = 64): HashValue
    {
        $isGrayscale = $image->bands === 1 || $image->interpretation === 'b-w';

        return $this->hashFromVipsImageWithGrayscale($image, $bits, $isGrayscale);
    }

    private function hashFromVipsImageWithGrayscale(VipsImage $image, int $bits = 64, bool $isGrayscale = false): HashValue
    {
        if ($bits !== 64) {
            throw new \InvalidArgumentException('MashedHash only supports 64-bit hashes');
        }

        // Store original dimensions for aspect ratio
        $originalWidth = $image->width;
        $originalHeight = $image->height;

        // Prepare working images
        $workingImage = $this->prepareImage($image);

        // Compute all components
        $components = [
            'colorfulness' => $this->computeColorfulness($workingImage, $isGrayscale),
            'edgeDensity' => $this->computeEdgeDensity($workingImage),
            'entropy' => $this->computeEntropy($workingImage),
            'aspectRatio' => $this->encodeAspectRatio($originalWidth, $originalHeight),
            'hasBorder' => $this->detectBorder($workingImage),
            'colorDistribution' => $this->computeColorDistribution($workingImage),
            'spatialLayout' => $this->computeSpatialLayout($workingImage),
            'brightness' => $this->computeBrightnessPattern($workingImage),
            'texture' => $this->computeTextureFeatures($workingImage),
            'dominantColors' => $this->computeDominantColorCount($workingImage),
            'special' => $this->computeSpecialIndicators($workingImage),
        ];

        // Pack components into hash
        $hashValue = $this->packComponents($components);

        return new HashValue($hashValue, $bits, $this->getAlgorithmName());
    }

    /**
     * Prepare image for analysis (handle alpha, etc).
     */
    private function prepareImage(VipsImage $image): VipsImage
    {
        // Flatten alpha channel if present
        if ($image->hasAlpha()) {
            $image = $image->flatten(['background' => [255, 255, 255]]);
        }

        // Copy to memory to avoid sequential access issues
        // since we'll be doing multiple operations on the same image
        return $image->copyMemory();
    }

    /**
     * Compute colorfulness level (0-15).
     * 0-3: Grayscale, 4-7: Low color, 8-11: Medium color, 12-15: High color
     */
    private function computeColorfulness(VipsImage $image, bool $isGrayscale): int
    {
        // Use the grayscale flag from before conversions
        if ($isGrayscale) {
            return 0; // Definitely grayscale
        }

        // Convert to RGB if needed
        if ($image->interpretation !== 'srgb' && $image->interpretation !== 'rgb') {
            $image = $image->colourspace('srgb');
        }

        // Calculate colorfulness using color channel differences
        // Note: We avoid using deviation to ensure consistent hashes
        $stats = $image->stats();

        // Get mean and range for each channel
        $rMean = $stats->getpoint(5, 1)[0];
        $gMean = $stats->getpoint(5, 2)[0];
        $bMean = $stats->getpoint(5, 3)[0];

        $rMin = $stats->getpoint(1, 1)[0];
        $gMin = $stats->getpoint(1, 2)[0];
        $bMin = $stats->getpoint(1, 3)[0];

        $rMax = $stats->getpoint(2, 1)[0];
        $gMax = $stats->getpoint(2, 2)[0];
        $bMax = $stats->getpoint(2, 3)[0];

        // Calculate channel ranges
        $rRange = $rMax - $rMin;
        $gRange = $gMax - $gMin;
        $bRange = $bMax - $bMin;

        $meanDiff = abs($rMean - $gMean) + abs($gMean - $bMean) + abs($rMean - $bMean);

        // If channels are very similar, it's effectively grayscale
        if ($meanDiff < 5 && ($rRange + $gRange + $bRange) < 30) {
            return 1; // Grayscale or very desaturated
        }

        // Use average range and mean differences to estimate colorfulness
        $avgRange = ($rRange + $gRange + $bRange) / 3;
        $colorfulness = 4 + (int) min(11, ($avgRange + $meanDiff) / 20);

        return $colorfulness;
    }

    /**
     * Compute edge density (0-15).
     */
    private function computeEdgeDensity(VipsImage $image): int
    {
        // Convert to grayscale for edge detection
        $gray = $this->convertToGrayscale($image);

        // Apply Sobel edge detection
        $edges = $gray->sobel();

        // Calculate average edge magnitude
        $stats = $edges->stats();
        $meanEdge = $stats->getpoint(5, 1)[0]; // Mean of first band

        // Normalize to 0-15 range
        // Typical edge values range from 0 to ~50
        $edgeDensity = (int) min(15, $meanEdge / 3.2);

        return $edgeDensity;
    }

    /**
     * Compute image entropy/complexity (0-15).
     */
    private function computeEntropy(VipsImage $image): int
    {
        // Convert to grayscale
        $gray = $this->convertToGrayscale($image);

        // Generate histogram
        $hist = $gray->hist_find();

        // Calculate entropy
        $entropy = $hist->hist_entropy();

        // Normalize to 0-15 range
        // Entropy typically ranges from 0 to ~8
        $complexity = (int) min(15, $entropy * 2);

        return $complexity;
    }

    /**
     * Encode aspect ratio into 3 bits (0-7).
     */
    private function encodeAspectRatio(int $width, int $height): int
    {
        $ratio = $width / $height;

        return match (true) {
            $ratio < 0.5 => 0,   // Very tall
            $ratio < 0.8 => 1,   // Portrait
            $ratio < 1.2 => 2,   // Square-ish
            $ratio < 1.6 => 3,   // Landscape
            $ratio < 2.0 => 4,   // Wide
            $ratio < 2.5 => 5,   // Very wide
            default => 6         // Ultra wide
        };
    }

    /**
     * Detect if image has a border (common in social media).
     */
    private function detectBorder(VipsImage $image): bool
    {
        $width = $image->width;
        $height = $image->height;

        // Sample edge pixels
        $borderSize = (int) min(20, $width * 0.05, $height * 0.05);

        // Extract edge regions
        $top = $image->crop(0, 0, $width, $borderSize);
        $bottom = $image->crop(0, $height - $borderSize, $width, $borderSize);
        $left = $image->crop(0, 0, $borderSize, $height);
        $right = $image->crop($width - $borderSize, 0, $borderSize, $height);

        // Calculate range for each edge (avoiding deviation)
        $topStats = $top->stats();
        $topRange = $topStats->getpoint(2, 0)[0] - $topStats->getpoint(1, 0)[0]; // Max - Min of all bands

        $bottomStats = $bottom->stats();
        $bottomRange = $bottomStats->getpoint(2, 0)[0] - $bottomStats->getpoint(1, 0)[0];

        $leftStats = $left->stats();
        $leftRange = $leftStats->getpoint(2, 0)[0] - $leftStats->getpoint(1, 0)[0];

        $rightStats = $right->stats();
        $rightRange = $rightStats->getpoint(2, 0)[0] - $rightStats->getpoint(1, 0)[0];

        // Low range on all edges suggests a uniform border
        $threshold = 30.0;

        return $topRange < $threshold && $bottomRange < $threshold &&
               $leftRange < $threshold && $rightRange < $threshold;
    }

    /**
     * Compute color distribution (16 bits).
     */
    private function computeColorDistribution(VipsImage $image): int
    {
        // Convert to RGB if needed
        if ($image->interpretation !== 'srgb' && $image->interpretation !== 'rgb') {
            $image = $image->colourspace('srgb');
        }

        // Simple approach: analyze color channel statistics
        $stats = $image->stats();

        // Get mean values for each channel
        // Note: We use only mean values (not deviation) to ensure consistent hashes
        // VIPS stats() can return slightly different deviation values between runs
        $rMean = $stats->getpoint(5, 1)[0];
        $gMean = $stats->getpoint(5, 2)[0];
        $bMean = $stats->getpoint(5, 3)[0];

        // Encode color characteristics into 16 bits
        $distribution = 0;

        // Bits 0-4: Red channel mean (0-255 mapped to 0-31)
        $rChar = (int) min(31, $rMean / 8);
        $distribution |= ($rChar & 0x1F);

        // Bits 5-9: Green channel mean (0-255 mapped to 0-31)
        $gChar = (int) min(31, $gMean / 8);
        $distribution |= (($gChar & 0x1F) << 5);

        // Bits 10-14: Blue channel mean (0-255 mapped to 0-31)
        $bChar = (int) min(31, $bMean / 8);
        $distribution |= (($bChar & 0x1F) << 10);

        // Bit 15: Color dominance flag (set if one channel is significantly higher)
        $maxMean = max($rMean, $gMean, $bMean);
        $minMean = min($rMean, $gMean, $bMean);
        if ($maxMean - $minMean > 50) {
            $distribution |= (1 << 15);
        }

        return $distribution;
    }

    /**
     * Compute spatial color layout (8 bits, 2 per quadrant).
     */
    private function computeSpatialLayout(VipsImage $image): int
    {
        $width = $image->width;
        $height = $image->height;
        $halfW = (int) ($width / 2);
        $halfH = (int) ($height / 2);

        // Extract quadrants
        $quadrants = [
            $image->crop(0, 0, $halfW, $halfH),           // Top-left
            $image->crop($halfW, 0, $halfW, $halfH),      // Top-right
            $image->crop(0, $halfH, $halfW, $halfH),      // Bottom-left
            $image->crop($halfW, $halfH, $halfW, $halfH), // Bottom-right
        ];

        $layout = 0;
        $bitOffset = 0;

        foreach ($quadrants as $quad) {
            // Get dominant color characteristic (simplified)
            $stats = $quad->stats();
            $meanR = $stats->getpoint(5, 1)[0];
            $meanG = $stats->getpoint(5, 2)[0];
            $meanB = $stats->getpoint(5, 3)[0];

            // Encode as 2-bit color dominance
            $dominance = 0;
            if ($meanR > $meanG && $meanR > $meanB) {
                $dominance = 1; // Red dominant
            } elseif ($meanG > $meanR && $meanG > $meanB) {
                $dominance = 2; // Green dominant
            } elseif ($meanB > $meanR && $meanB > $meanG) {
                $dominance = 3; // Blue dominant
            }

            $layout |= ($dominance << $bitOffset);
            $bitOffset += 2;
        }

        return $layout;
    }

    /**
     * Compute brightness pattern (8 bits).
     */
    private function computeBrightnessPattern(VipsImage $image): int
    {
        $gray = $this->convertToGrayscale($image);
        $stats = $gray->stats();

        $mean = $stats->getpoint(5, 1)[0];      // Mean brightness
        $min = $stats->getpoint(1, 1)[0];       // Min brightness
        $max = $stats->getpoint(2, 1)[0];       // Max brightness

        // Use range instead of deviation for consistency
        $range = $max - $min;

        // Encode mean (4 bits) and range (4 bits)
        $meanBits = (int) min(15, $mean / 17);      // 0-255 -> 0-15
        $rangeBits = (int) min(15, $range / 17);    // 0-255 -> 0-15

        return ($meanBits << 4) | $rangeBits;
    }

    /**
     * Compute texture features (8 bits).
     */
    private function computeTextureFeatures(VipsImage $image): int
    {
        $gray = $this->convertToGrayscale($image);

        // Compute directional gradients
        $dx = $gray->sobel()->extract_band(0);
        $dy = $gray->sobel()->rot90()->extract_band(0);

        // Calculate texture metrics
        $dxStats = $dx->stats();
        $dyStats = $dy->stats();

        $horizontalTexture = $dxStats->getpoint(5, 1)[0]; // Mean horizontal gradient
        $verticalTexture = $dyStats->getpoint(5, 1)[0];   // Mean vertical gradient

        // Encode as 4 bits each
        $hBits = (int) min(15, $horizontalTexture / 3);
        $vBits = (int) min(15, $verticalTexture / 3);

        return ($hBits << 4) | $vBits;
    }

    /**
     * Count dominant colors (0-15).
     */
    private function computeDominantColorCount(VipsImage $image): int
    {
        // Use color ranges as proxy for color count (avoiding deviation)
        $stats = $image->stats();

        $rMin = $stats->getpoint(1, 1)[0];
        $gMin = $stats->getpoint(1, 2)[0];
        $bMin = $stats->getpoint(1, 3)[0];

        $rMax = $stats->getpoint(2, 1)[0];
        $gMax = $stats->getpoint(2, 2)[0];
        $bMax = $stats->getpoint(2, 3)[0];

        $rRange = $rMax - $rMin;
        $gRange = $gMax - $gMin;
        $bRange = $bMax - $bMin;

        $totalRange = $rRange + $gRange + $bRange;

        // Map to dominant color count estimate based on total range
        return match (true) {
            $totalRange < 90 => 1,    // Very uniform
            $totalRange < 180 => 2,   // 2-3 dominant colors
            $totalRange < 270 => 4,   // 4-5 colors
            $totalRange < 360 => 6,   // 6-8 colors
            $totalRange < 450 => 8,   // Many colors
            default => 12             // Very colorful
        };
    }

    /**
     * Compute special indicators (4 bits).
     */
    private function computeSpecialIndicators(VipsImage $image): int
    {
        $indicators = 0;

        // Bit 0: High frequency content (possible text)
        $gray = $this->convertToGrayscale($image);

        // Create Laplacian mask for edge detection
        $mask = VipsImage::newFromArray([
            [-1, -1, -1],
            [-1,  8, -1],
            [-1, -1, -1],
        ]);

        $laplacian = $gray->conv($mask);
        $highFreqStats = $laplacian->stats();
        $highFreq = $highFreqStats->getpoint(5, 1)[0];

        if ($highFreq > 20) {
            $indicators |= 1;
        }

        // Bit 1: Large uniform regions
        $stats = $image->stats();
        $rRange = $stats->getpoint(2, 1)[0] - $stats->getpoint(1, 1)[0];
        $gRange = $stats->getpoint(2, 2)[0] - $stats->getpoint(1, 2)[0];
        $bRange = $stats->getpoint(2, 3)[0] - $stats->getpoint(1, 3)[0];
        $avgRange = ($rRange + $gRange + $bRange) / 3;

        if ($avgRange < 45) {
            $indicators |= 2;
        }

        // Bits 2-3: Reserved for future use

        return $indicators;
    }

    /**
     * Pack all components into a 64-bit hash, applying Gray coding to
     * ordinal fields.
     */
    private function packComponents(array $components): int
    {
        $hash = 0;

        $hash |= (self::grayEncode($components['colorfulness']) & self::COLORFULNESS_MASK) << self::COLORFULNESS_BITS;
        $hash |= (self::grayEncode($components['edgeDensity']) & self::EDGE_DENSITY_MASK) << self::EDGE_DENSITY_BITS;
        $hash |= (self::grayEncode($components['entropy']) & self::ENTROPY_MASK) << self::ENTROPY_BITS;
        $hash |= (self::grayEncode($components['aspectRatio']) & self::ASPECT_RATIO_MASK) << self::ASPECT_RATIO_BITS;

        if ($components['hasBorder']) {
            $hash |= 1 << self::BORDER_FLAG_BIT;
        }

        $hash |= (self::grayEncodeColorDistribution($components['colorDistribution']) & self::COLOR_DIST_MASK) << self::COLOR_DIST_BITS;
        $hash |= ($components['spatialLayout'] & self::SPATIAL_LAYOUT_MASK) << self::SPATIAL_LAYOUT_BITS;
        $hash |= (self::grayEncodePaired4($components['brightness']) & self::BRIGHTNESS_MASK) << self::BRIGHTNESS_BITS;
        $hash |= (self::grayEncodePaired4($components['texture']) & self::TEXTURE_MASK) << self::TEXTURE_BITS;
        $hash |= (self::grayEncode($components['dominantColors']) & self::DOMINANT_COLOR_MASK) << self::DOMINANT_COLOR_BITS;
        $hash |= ($components['special'] & self::SPECIAL_MASK) << self::SPECIAL_BITS;

        return $hash;
    }

    /**
     * Decode a 64-bit MashedHash value into its semantic components.
     *
     * @return array{
     *     colorfulness: int,
     *     edgeDensity: int,
     *     entropy: int,
     *     aspectRatio: int,
     *     hasBorder: bool,
     *     colorDistribution: array{red: int, green: int, blue: int, hasDominantChannel: bool},
     *     spatialLayout: int,
     *     brightness: array{mean: int, range: int},
     *     texture: array{horizontal: int, vertical: int},
     *     dominantColors: int,
     *     special: int,
     * }
     */
    public function decode(HashValue $hash): array
    {
        if ($hash->getAlgorithm() !== self::ALGORITHM_NAME || $hash->getBits() !== 64) {
            throw new \InvalidArgumentException(
                sprintf('Expected a 64-bit %s hash', self::ALGORITHM_NAME)
            );
        }

        $value = $hash->getValue();

        $colorDist = ($value >> self::COLOR_DIST_BITS) & self::COLOR_DIST_MASK;
        $brightness = ($value >> self::BRIGHTNESS_BITS) & self::BRIGHTNESS_MASK;
        $texture = ($value >> self::TEXTURE_BITS) & self::TEXTURE_MASK;

        return [
            'colorfulness' => self::grayDecode(($value >> self::COLORFULNESS_BITS) & self::COLORFULNESS_MASK),
            'edgeDensity' => self::grayDecode(($value >> self::EDGE_DENSITY_BITS) & self::EDGE_DENSITY_MASK),
            'entropy' => self::grayDecode(($value >> self::ENTROPY_BITS) & self::ENTROPY_MASK),
            'aspectRatio' => self::grayDecode(($value >> self::ASPECT_RATIO_BITS) & self::ASPECT_RATIO_MASK),
            'hasBorder' => (bool) (($value >> self::BORDER_FLAG_BIT) & 1),
            'colorDistribution' => [
                'red' => self::grayDecode($colorDist & 0x1F),
                'green' => self::grayDecode(($colorDist >> 5) & 0x1F),
                'blue' => self::grayDecode(($colorDist >> 10) & 0x1F),
                'hasDominantChannel' => (bool) (($colorDist >> 15) & 1),
            ],
            'spatialLayout' => ($value >> self::SPATIAL_LAYOUT_BITS) & self::SPATIAL_LAYOUT_MASK,
            'brightness' => [
                'mean' => self::grayDecode(($brightness >> 4) & 0xF),
                'range' => self::grayDecode($brightness & 0xF),
            ],
            'texture' => [
                'horizontal' => self::grayDecode(($texture >> 4) & 0xF),
                'vertical' => self::grayDecode($texture & 0xF),
            ],
            'dominantColors' => self::grayDecode(($value >> self::DOMINANT_COLOR_BITS) & self::DOMINANT_COLOR_MASK),
            'special' => ($value >> self::SPECIAL_BITS) & self::SPECIAL_MASK,
        ];
    }

    /**
     * Reflected binary Gray code: binary → gray.
     * Adjacent binary levels (N, N+1) produce Gray codes that differ by exactly one bit.
     */
    private static function grayEncode(int $binary): int
    {
        return $binary ^ ($binary >> 1);
    }

    private static function grayDecode(int $gray): int
    {
        $binary = $gray;
        while ($gray >>= 1) {
            $binary ^= $gray;
        }

        return $binary;
    }

    /**
     * Re-encode the packed 16-bit colorDistribution value: the three 5-bit
     * RGB channels are ordinal (Gray-coded); the top dominance flag is not.
     */
    private static function grayEncodeColorDistribution(int $cd): int
    {
        $r = $cd & 0x1F;
        $g = ($cd >> 5) & 0x1F;
        $b = ($cd >> 10) & 0x1F;
        $dominance = ($cd >> 15) & 1;

        return self::grayEncode($r)
            | (self::grayEncode($g) << 5)
            | (self::grayEncode($b) << 10)
            | ($dominance << 15);
    }

    /**
     * Re-encode an 8-bit packed value that holds two 4-bit ordinal fields
     * (high nibble + low nibble), Gray-coding each nibble independently.
     */
    private static function grayEncodePaired4(int $packed): int
    {
        $high = ($packed >> 4) & 0xF;
        $low = $packed & 0xF;

        return (self::grayEncode($high) << 4) | self::grayEncode($low);
    }
}
