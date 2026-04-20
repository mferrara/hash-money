<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Strategies;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\HashValue;

/**
 * Block Mean Hash.
 *
 * Resizes the image to a √bits × √bits grayscale grid, computes the mean
 * luminance of the whole grid, then sets one bit per cell based on whether
 * that cell is brighter than the overall mean. The result is a compact
 * spatial-domain fingerprint that complements pHash (frequency-domain) and
 * dHash (gradient-domain): it retains "where the bright/dark regions live"
 * even through luminance changes and JPEG re-encoding.
 */
class BlockMeanHashStrategy extends AbstractHashStrategy
{
    private const ALGORITHM_NAME = 'block-mean';

    /** Grid dimensions for each supported bit size. width*height must equal bits. */
    private const DIMENSIONS = [
        8 => ['width' => 4, 'height' => 2],
        16 => ['width' => 4, 'height' => 4],
        32 => ['width' => 8, 'height' => 4],
        64 => ['width' => 8, 'height' => 8],
        128 => ['width' => 16, 'height' => 8],
        256 => ['width' => 16, 'height' => 16],
    ];

    public function getAlgorithmName(): string
    {
        return self::ALGORITHM_NAME;
    }

    protected function getImageSizeForBits(int $bits): array
    {
        if (! isset(self::DIMENSIONS[$bits])) {
            throw new \InvalidArgumentException("Unsupported bit size: $bits");
        }

        return self::DIMENSIONS[$bits];
    }

    public function hashFromVipsImage(VipsImage $image, int $bits = 64): HashValue
    {
        if (! isset(self::DIMENSIONS[$bits])) {
            throw new \InvalidArgumentException("Unsupported bit size: $bits");
        }

        $image = $this->convertToGrayscale($image);

        $dims = self::DIMENSIONS[$bits];
        $width = $dims['width'];
        $height = $dims['height'];

        $pixels = $this->extractPixelsAs2DArray($image, $width, $height);

        $sum = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $sum += $pixels[$y][$x];
            }
        }
        $mean = $sum / ($width * $height);

        $bitArray = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $bitArray[] = $pixels[$y][$x] > $mean ? 1 : 0;
            }
        }

        $bytes = '';
        for ($i = 0; $i < $bits; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                if (! empty($bitArray[$i + $j])) {
                    $byte |= (1 << (7 - $j));
                }
            }
            $bytes .= chr($byte);
        }

        return HashValue::fromBytes($bytes, $this->getAlgorithmName());
    }
}
