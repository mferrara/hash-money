<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Strategies;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\HashValue;

class DHashStrategy extends AbstractHashStrategy
{
    private const ALGORITHM_NAME = 'dhash';

    // Image dimensions for different bit sizes
    // We need width+1 to compare adjacent pixels
    private const DIMENSIONS = [
        64 => ['width' => 9, 'height' => 8],
        32 => ['width' => 8, 'height' => 4],
        16 => ['width' => 4, 'height' => 4],
        8 => ['width' => 4, 'height' => 2],
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
        $image = $this->convertToGrayscale($image);

        $dimensions = self::DIMENSIONS[$bits];
        $width = $dimensions['width'];
        $height = $dimensions['height'];

        // Extract pixel values
        $pixels = $this->extractPixelsAs2DArray($image, $width, $height);

        // Generate hash by comparing adjacent pixels
        $hashValue = $this->generateDHash($pixels, $width, $height, $bits);

        return new HashValue($hashValue, $bits, $this->getAlgorithmName());
    }


    private function generateDHash(array $pixels, int $width, int $height, int $bits): int
    {
        $hash = 0;
        $bit = 0;

        // Compare adjacent pixels horizontally
        for ($y = 0; $y < $height && $bit < $bits; $y++) {
            for ($x = 0; $x < $width - 1 && $bit < $bits; $x++) {
                // Set bit to 1 if left pixel is brighter than right pixel
                if ($pixels[$y][$x] > $pixels[$y][$x + 1]) {
                    $hash |= (1 << ($bits - 1 - $bit));
                }
                $bit++;
            }
        }

        return $hash;
    }
}
