<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Strategies;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\HashValue;

/**
 * PDQ Hash Strategy.
 *
 * 256-bit perceptual hash designed by Meta for industrial-scale near-duplicate
 * image detection. Pipeline:
 *
 *   1. Convert to luminance with Rec. 601 coefficients (0.299 R + 0.587 G + 0.114 B).
 *   2. Two-pass Jarosz 1-D box filter (X, Y, X, Y) — a tent approximation whose
 *      window size is ceil(dim / 128) per dimension.
 *   3. Decimate to 64×64 by center-pixel sampling.
 *   4. Compute a quality metric in [0, 100] from gradient magnitudes on the 64×64
 *      buffer (Meta recommends discarding hashes with quality ≤ 49).
 *   5. 16×16 DCT-II of the 64×64 buffer (B = D · A · Dᵀ).
 *   6. Threshold each of the 256 DCT coefficients against the median to produce
 *      a 256-bit binary hash.
 *
 * Hashes match (are "near-duplicate") when their Hamming distance is ≤ 31 of 256
 * bits per Meta's published threshold.
 *
 * Quality is exposed via {@see HashValue::getMetadata()} under key 'pdq_quality'.
 *
 * The eight dihedral hashes (rotations and flips) can be computed in one pass via
 * {@see hashesFromVipsImage()} / {@see hashesFromFile()} / {@see hashesFromString()}.
 *
 * Algorithm reference: Meta Platforms ThreatExchange PDQ
 * (https://github.com/facebook/ThreatExchange/tree/main/pdq, BSD-3-Clause).
 * This is an independent port; pixel-level output may differ slightly from the
 * upstream pure-PHP / C / WASM implementations because we rely on libvips for
 * decoding and pre-resize, but the algorithm class and threshold semantics are
 * preserved.
 */
class PdqHashStrategy extends AbstractHashStrategy
{
    public const ALGORITHM_NAME = 'pdq';

    public const HASH_BITS = 256;

    public const RECOMMENDED_DISTANCE_THRESHOLD = 31;

    public const RECOMMENDED_QUALITY_THRESHOLD = 50;

    public const DIH_ORIGINAL = 'orig';

    public const DIH_ROTATE_90 = 'r090';

    public const DIH_ROTATE_180 = 'r180';

    public const DIH_ROTATE_270 = 'r270';

    public const DIH_FLIP_X = 'flpx';

    public const DIH_FLIP_Y = 'flpy';

    public const DIH_FLIP_PLUS_1 = 'flpp';

    public const DIH_FLIP_MINUS_1 = 'flpm';

    private const LUMA_R = 0.299;

    private const LUMA_G = 0.587;

    private const LUMA_B = 0.114;

    private const JAROSZ_WINDOW_DIVISOR = 128;

    private const JAROSZ_PASSES = 2;

    /**
     * Default working resolution for the libvips pre-resize. Large enough for
     * the Jarosz window divisor to produce a non-trivial window (512 / 128 = 4)
     * while keeping the pure-PHP Jarosz/DCT loops tractable.
     */
    private const DEFAULT_WORKING_SIZE = 512;

    /** Pre-computed 16×64 DCT matrix; lazily built on first use. */
    private static ?array $dct16x64 = null;

    private int $workingSize = self::DEFAULT_WORKING_SIZE;

    public function configure(array $config): void
    {
        if (array_key_exists('workingSize', $config)) {
            $size = (int) $config['workingSize'];
            if ($size < 64) {
                throw new \InvalidArgumentException(
                    "PDQ workingSize must be >= 64 (got {$size}); the Jarosz pipeline needs room to filter."
                );
            }
            $this->workingSize = $size;
            unset($config['workingSize']);
        }

        parent::configure($config);
    }

    public function getAlgorithmName(): string
    {
        return self::ALGORITHM_NAME;
    }

    protected function getImageSizeForBits(int $bits): array
    {
        // PDQ is fixed-size at 256 bits; this is only used by the unused
        // base-class hashFromFile path. We still return a reasonable working
        // size in case a caller invokes it directly.
        return ['width' => $this->workingSize, 'height' => $this->workingSize];
    }

    public function hashFromFile(string $filePath, int $bits = self::HASH_BITS): HashValue
    {
        $this->assertBits($bits);
        $this->initVips();

        try {
            $image = VipsImage::thumbnail($filePath, $this->workingSize, [
                'height' => $this->workingSize,
                'size' => 'down',
                'import_profile' => 'srgb',
                'export_profile' => 'srgb',
            ]);

            return $this->hashFromVipsImage($image, $bits);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate PDQ hash: '.$e->getMessage(), 0, $e);
        }
    }

    public function hashFromString(string $imageData, int $bits = self::HASH_BITS, array $options = []): HashValue
    {
        $this->assertBits($bits);
        $this->initVips();

        try {
            $image = VipsImage::thumbnail_buffer($imageData, $this->workingSize, array_merge([
                'height' => $this->workingSize,
                'size' => 'down',
                'import_profile' => 'srgb',
                'export_profile' => 'srgb',
            ], $options));

            return $this->hashFromVipsImage($image, $bits);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate PDQ hash from buffer: '.$e->getMessage(), 0, $e);
        }
    }

    public function hashFromVipsImage(VipsImage $image, int $bits = self::HASH_BITS): HashValue
    {
        $this->assertBits($bits);

        [$buffer16x16, $quality] = $this->computeDctAndQuality($image);
        $bytes = $this->buildHashBytes($buffer16x16);

        return HashValue::fromBytes($bytes, self::ALGORITHM_NAME, ['pdq_quality' => $quality]);
    }

    /**
     * Compute all eight dihedral hashes (original + 3 rotations + 4 flips).
     *
     * @return array{hashes: array<string, HashValue>, quality: int}
     */
    public function hashesFromFile(string $filePath): array
    {
        $this->initVips();

        $image = VipsImage::thumbnail($filePath, $this->workingSize, [
            'height' => $this->workingSize,
            'size' => 'down',
            'import_profile' => 'srgb',
            'export_profile' => 'srgb',
        ]);

        return $this->hashesFromVipsImage($image);
    }

    /**
     * @return array{hashes: array<string, HashValue>, quality: int}
     */
    public function hashesFromString(string $imageData, array $options = []): array
    {
        $this->initVips();

        $image = VipsImage::thumbnail_buffer($imageData, $this->workingSize, array_merge([
            'height' => $this->workingSize,
            'size' => 'down',
            'import_profile' => 'srgb',
            'export_profile' => 'srgb',
        ], $options));

        return $this->hashesFromVipsImage($image);
    }

    /**
     * @return array{hashes: array<string, HashValue>, quality: int}
     */
    public function hashesFromVipsImage(VipsImage $image): array
    {
        [$buffer16x16, $quality] = $this->computeDctAndQuality($image);

        $hashes = [
            self::DIH_ORIGINAL => $this->hashValueFromDct($buffer16x16, $quality),
            self::DIH_ROTATE_90 => $this->hashValueFromDct($this->dctRotate90($buffer16x16), $quality),
            self::DIH_ROTATE_180 => $this->hashValueFromDct($this->dctRotate180($buffer16x16), $quality),
            self::DIH_ROTATE_270 => $this->hashValueFromDct($this->dctRotate270($buffer16x16), $quality),
            self::DIH_FLIP_X => $this->hashValueFromDct($this->dctFlipX($buffer16x16), $quality),
            self::DIH_FLIP_Y => $this->hashValueFromDct($this->dctFlipY($buffer16x16), $quality),
            self::DIH_FLIP_PLUS_1 => $this->hashValueFromDct($this->dctFlipPlus1($buffer16x16), $quality),
            self::DIH_FLIP_MINUS_1 => $this->hashValueFromDct($this->dctFlipMinus1($buffer16x16), $quality),
        ];

        return ['hashes' => $hashes, 'quality' => $quality];
    }

    /**
     * Convenience: extract the PDQ quality score from a HashValue produced by this strategy.
     */
    public static function quality(HashValue $hash): int
    {
        $q = $hash->getMetadata('pdq_quality');

        return is_int($q) ? $q : 0;
    }

    private function assertBits(int $bits): void
    {
        if ($bits !== self::HASH_BITS) {
            throw new \InvalidArgumentException(
                sprintf('PDQ produces %d-bit hashes only; got %d.', self::HASH_BITS, $bits)
            );
        }
    }

    private function hashValueFromDct(array $buffer16x16, int $quality): HashValue
    {
        return HashValue::fromBytes(
            $this->buildHashBytes($buffer16x16),
            self::ALGORITHM_NAME,
            ['pdq_quality' => $quality]
        );
    }

    /**
     * Run the full PDQ image-domain pipeline.
     *
     * @return array{0: array<int, array<int, float>>, 1: int} 16×16 DCT block and quality score.
     */
    private function computeDctAndQuality(VipsImage $image): array
    {
        $lumaMatrix = $this->buildLumaMatrix($image);
        $numRows = count($lumaMatrix);
        $numCols = count($lumaMatrix[0]);

        $windowAlongRows = self::computeJaroszWindowSize($numCols);
        $windowAlongCols = self::computeJaroszWindowSize($numRows);

        $this->jaroszFilter($lumaMatrix, $numRows, $numCols, $windowAlongRows, $windowAlongCols);

        $buffer64 = $this->decimateTo64x64($lumaMatrix, $numRows, $numCols);
        $quality = $this->computeImageDomainQualityMetric($buffer64);
        $buffer16 = $this->computeDct64To16($buffer64);

        return [$buffer16, $quality];
    }

    /**
     * Convert a libvips image to a 2D PHP float matrix of Rec. 601 luminance.
     *
     * @return array<int, array<int, float>>
     */
    private function buildLumaMatrix(VipsImage $image): array
    {
        if ($image->hasAlpha()) {
            $image = $image->flatten(['background' => [255, 255, 255]]);
        }

        if ($image->bands === 1 || $image->interpretation === 'b-w') {
            // Already luminance; just cast to float.
            $luma = $image->cast('float');
        } else {
            if ($image->interpretation !== 'srgb' && $image->interpretation !== 'rgb') {
                $image = $image->colourspace('srgb');
            }
            // recomb expects float input for fractional coefficients.
            $luma = $image
                ->cast('float')
                ->recomb([[self::LUMA_R, self::LUMA_G, self::LUMA_B]]);
        }

        $width = $luma->width;
        $height = $luma->height;

        $flat = $luma->writeToArray();
        $expected = $width * $height;
        if (count($flat) !== $expected) {
            throw new \RuntimeException(sprintf(
                'Luma extraction size mismatch: expected %d values, got %d.',
                $expected,
                count($flat)
            ));
        }

        $matrix = [];
        $idx = 0;
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $row[$x] = (float) $flat[$idx++];
            }
            $matrix[$y] = $row;
        }

        return $matrix;
    }

    private static function computeJaroszWindowSize(int $dimension): int
    {
        $w = (int) (($dimension + self::JAROSZ_WINDOW_DIVISOR - 1) / self::JAROSZ_WINDOW_DIVISOR);

        return max(1, $w);
    }

    /**
     * Two XY passes of 1-D box filtering — produces a 2D tent approximation.
     *
     * @param  array<int, array<int, float>>  $lumaMatrix  Modified in place.
     */
    private function jaroszFilter(array &$lumaMatrix, int $numRows, int $numCols, int $windowAlongRows, int $windowAlongCols): void
    {
        $other = array_fill(0, $numRows, array_fill(0, $numCols, 0.0));

        for ($k = 0; $k < self::JAROSZ_PASSES; $k++) {
            $this->boxAlongRows($lumaMatrix, $other, $numRows, $numCols, $windowAlongRows);
            $this->boxAlongCols($other, $lumaMatrix, $numRows, $numCols, $windowAlongCols);
        }
    }

    /**
     * @param  array<int, array<int, float>>  $in
     * @param  array<int, array<int, float>>  $out
     */
    private function boxAlongRows(array &$in, array &$out, int $numRows, int $numCols, int $windowSize): void
    {
        $halfWindow = (int) (($windowSize + 2) / 2);
        $phase1 = $halfWindow - 1;
        $phase2 = $windowSize - $halfWindow + 1;
        $phase3 = $numCols - $windowSize;
        $phase4 = $halfWindow - 1;

        for ($i = 0; $i < $numRows; $i++) {
            $row = &$in[$i];
            $outRow = &$out[$i];

            $li = 0;
            $ri = 0;
            $oi = 0;
            $sum = 0.0;
            $cur = 0;

            for ($k = 0; $k < $phase1; $k++) {
                $sum += $row[$ri];
                $cur++;
                $ri++;
            }
            for ($k = 0; $k < $phase2; $k++) {
                $sum += $row[$ri];
                $cur++;
                $outRow[$oi] = $sum / $cur;
                $ri++;
                $oi++;
            }
            for ($k = 0; $k < $phase3; $k++) {
                $sum += $row[$ri];
                $sum -= $row[$li];
                $outRow[$oi] = $sum / $cur;
                $li++;
                $ri++;
                $oi++;
            }
            for ($k = 0; $k < $phase4; $k++) {
                $sum -= $row[$li];
                $cur--;
                $outRow[$oi] = $sum / $cur;
                $li++;
                $oi++;
            }
            unset($row, $outRow);
        }
    }

    /**
     * @param  array<int, array<int, float>>  $in
     * @param  array<int, array<int, float>>  $out
     */
    private function boxAlongCols(array &$in, array &$out, int $numRows, int $numCols, int $windowSize): void
    {
        $halfWindow = (int) (($windowSize + 2) / 2);
        $phase1 = $halfWindow - 1;
        $phase2 = $windowSize - $halfWindow + 1;
        $phase3 = $numRows - $windowSize;
        $phase4 = $halfWindow - 1;

        for ($j = 0; $j < $numCols; $j++) {
            $li = 0;
            $ri = 0;
            $oi = 0;
            $sum = 0.0;
            $cur = 0;

            for ($k = 0; $k < $phase1; $k++) {
                $sum += $in[$ri][$j];
                $cur++;
                $ri++;
            }
            for ($k = 0; $k < $phase2; $k++) {
                $sum += $in[$ri][$j];
                $cur++;
                $out[$oi][$j] = $sum / $cur;
                $ri++;
                $oi++;
            }
            for ($k = 0; $k < $phase3; $k++) {
                $sum += $in[$ri][$j];
                $sum -= $in[$li][$j];
                $out[$oi][$j] = $sum / $cur;
                $li++;
                $ri++;
                $oi++;
            }
            for ($k = 0; $k < $phase4; $k++) {
                $sum -= $in[$li][$j];
                $cur--;
                $out[$oi][$j] = $sum / $cur;
                $li++;
                $oi++;
            }
        }
    }

    /**
     * @param  array<int, array<int, float>>  $lumaMatrix
     * @return array<int, array<int, float>>
     */
    private function decimateTo64x64(array $lumaMatrix, int $numRows, int $numCols): array
    {
        $buf = array_fill(0, 64, array_fill(0, 64, 0.0));

        for ($i = 0; $i < 64; $i++) {
            $ini = (int) ((($i + 0.5) * $numRows) / 64);
            if ($ini >= $numRows) {
                $ini = $numRows - 1;
            }
            for ($j = 0; $j < 64; $j++) {
                $inj = (int) ((($j + 0.5) * $numCols) / 64);
                if ($inj >= $numCols) {
                    $inj = $numCols - 1;
                }
                $buf[$i][$j] = $lumaMatrix[$ini][$inj];
            }
        }

        return $buf;
    }

    /**
     * @param  array<int, array<int, float>>  $buffer64
     */
    private function computeImageDomainQualityMetric(array $buffer64): int
    {
        $sum = 0;

        for ($i = 0; $i < 63; $i++) {
            for ($j = 0; $j < 64; $j++) {
                $u = $buffer64[$i][$j];
                $v = $buffer64[$i + 1][$j];
                $d = (int) ((($u - $v) * 100) / 255);
                $sum += abs($d);
            }
        }
        for ($i = 0; $i < 64; $i++) {
            for ($j = 0; $j < 63; $j++) {
                $u = $buffer64[$i][$j];
                $v = $buffer64[$i][$j + 1];
                $d = (int) ((($u - $v) * 100) / 255);
                $sum += abs($d);
            }
        }

        $q = (int) ($sum / 90);

        return $q > 100 ? 100 : $q;
    }

    /**
     * 64×64 → 16×16 DCT-II via B = D · A · Dᵀ where D is 16×64.
     *
     * @param  array<int, array<int, float>>  $buffer64
     * @return array<int, array<int, float>>
     */
    private function computeDct64To16(array $buffer64): array
    {
        $D = self::dctMatrix();

        // T = D · A   (T is 16×64)
        $T = array_fill(0, 16, array_fill(0, 64, 0.0));
        for ($i = 0; $i < 16; $i++) {
            $Drow = $D[$i];
            for ($j = 0; $j < 64; $j++) {
                $sum = 0.0;
                for ($k = 0; $k < 64; $k++) {
                    $sum += $Drow[$k] * $buffer64[$k][$j];
                }
                $T[$i][$j] = $sum;
            }
        }

        // B = T · Dᵀ   (B is 16×16, Dᵀ is 64×16, so B[i][j] = Σ_k T[i][k] * D[j][k])
        $B = array_fill(0, 16, array_fill(0, 16, 0.0));
        for ($i = 0; $i < 16; $i++) {
            $Trow = $T[$i];
            for ($j = 0; $j < 16; $j++) {
                $Drow = $D[$j];
                $sum = 0.0;
                for ($k = 0; $k < 64; $k++) {
                    $sum += $Trow[$k] * $Drow[$k];
                }
                $B[$i][$j] = $sum;
            }
        }

        return $B;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private static function dctMatrix(): array
    {
        if (self::$dct16x64 !== null) {
            return self::$dct16x64;
        }

        $scale = sqrt(2.0 / 64.0);
        $matrix = [];
        for ($i = 0; $i < 16; $i++) {
            $row = [];
            for ($j = 0; $j < 64; $j++) {
                $row[$j] = $scale * cos((M_PI / 128.0) * ($i + 1) * (2 * $j + 1));
            }
            $matrix[$i] = $row;
        }

        return self::$dct16x64 = $matrix;
    }

    /**
     * Threshold the 16×16 DCT block against its median to produce the 256-bit hash bytes.
     *
     * Bit ordering matches Meta's PDQ reference: bit_index k = (i*16 + j) lands in
     * slot k>>4 at bit k&15 (slot bit 0 is LSB). Slots are concatenated from slot 15
     * down to slot 0 in big-endian order so that bin2hex() produces a 64-char
     * string identical to the reference's PDQHash::toHexString().
     *
     * @param  array<int, array<int, float>>  $buffer16x16
     */
    private function buildHashBytes(array $buffer16x16): string
    {
        $flat = [];
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                $flat[] = $buffer16x16[$i][$j];
            }
        }
        sort($flat);
        $median = $flat[127];

        $slots = array_fill(0, 16, 0);
        $k = 0;
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                if ($buffer16x16[$i][$j] > $median) {
                    $slot = $k >> 4;
                    $bit = $k & 0xF;
                    $slots[$slot] |= (1 << $bit);
                }
                $k++;
            }
        }

        $bytes = '';
        for ($s = 15; $s >= 0; $s--) {
            $bytes .= pack('n', $slots[$s] & 0xFFFF);
        }

        return $bytes;
    }

    /**
     * Sign-flip patterns for the eight dihedral transforms of a 16×16 DCT block.
     * Matches the patterns documented in Meta's PDQ reference.
     *
     * @param  array<int, array<int, float>>  $A
     * @return array<int, array<int, float>>
     */
    private function dctRotate90(array $A): array
    {
        $B = array_fill(0, 16, array_fill(0, 16, 0.0));
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                $B[$j][$i] = ($j & 1) ? $A[$i][$j] : -$A[$i][$j];
            }
        }

        return $B;
    }

    /**
     * @param  array<int, array<int, float>>  $A
     * @return array<int, array<int, float>>
     */
    private function dctRotate180(array $A): array
    {
        $B = array_fill(0, 16, array_fill(0, 16, 0.0));
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                $B[$i][$j] = (($i + $j) & 1) ? -$A[$i][$j] : $A[$i][$j];
            }
        }

        return $B;
    }

    /**
     * @param  array<int, array<int, float>>  $A
     * @return array<int, array<int, float>>
     */
    private function dctRotate270(array $A): array
    {
        $B = array_fill(0, 16, array_fill(0, 16, 0.0));
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                $B[$j][$i] = ($i & 1) ? $A[$i][$j] : -$A[$i][$j];
            }
        }

        return $B;
    }

    /**
     * @param  array<int, array<int, float>>  $A
     * @return array<int, array<int, float>>
     */
    private function dctFlipX(array $A): array
    {
        $B = array_fill(0, 16, array_fill(0, 16, 0.0));
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                $B[$i][$j] = ($i & 1) ? $A[$i][$j] : -$A[$i][$j];
            }
        }

        return $B;
    }

    /**
     * @param  array<int, array<int, float>>  $A
     * @return array<int, array<int, float>>
     */
    private function dctFlipY(array $A): array
    {
        $B = array_fill(0, 16, array_fill(0, 16, 0.0));
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                $B[$i][$j] = ($j & 1) ? $A[$i][$j] : -$A[$i][$j];
            }
        }

        return $B;
    }

    /**
     * @param  array<int, array<int, float>>  $A
     * @return array<int, array<int, float>>
     */
    private function dctFlipPlus1(array $A): array
    {
        $B = array_fill(0, 16, array_fill(0, 16, 0.0));
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                $B[$j][$i] = $A[$i][$j];
            }
        }

        return $B;
    }

    /**
     * @param  array<int, array<int, float>>  $A
     * @return array<int, array<int, float>>
     */
    private function dctFlipMinus1(array $A): array
    {
        $B = array_fill(0, 16, array_fill(0, 16, 0.0));
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 16; $j++) {
                $B[$j][$i] = (($i + $j) & 1) ? -$A[$i][$j] : $A[$i][$j];
            }
        }

        return $B;
    }
}
