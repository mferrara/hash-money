<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use Exception;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Image as VipsImage;

/**
 * Perceptual Hash implementation using VIPS for high-performance image hashing.
 *
 * IMPORTANT: This class requires 64-bit PHP (PHP_INT_SIZE >= 8) as it generates
 * 64-bit hash values. On 64-bit systems, PHP integers can be negative when the
 * most significant bit is set, which is normal and expected behavior.
 */
class PerceptualHash
{
    private const SIZE = 32;

    private const SIZE_SQRT = 0.25;

    private const MATRIX_SIZE = 11;

    private const DCT_11_32 = [
        [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
        [0.99879546, 0.98917651, 0.97003125, 0.94154407, 0.90398929, 0.85772861, 0.80320753, 0.74095113, 0.67155895, 0.5956993, 0.51410274, 0.42755509, 0.33688985, 0.24298018, 0.14673047, 0.04906767, -0.04906767, -0.14673047, -0.24298018, -0.33688985, -0.42755509, -0.51410274, -0.5956993, -0.67155895, -0.74095113, -0.80320753, -0.85772861, -0.90398929, -0.94154407, -0.97003125, -0.98917651, -0.99879546],
        [0.99518473, 0.95694034, 0.88192126, 0.77301045, 0.63439328, 0.47139674, 0.29028468, 0.09801714, -0.09801714, -0.29028468, -0.47139674, -0.63439328, -0.77301045, -0.88192126, -0.95694034, -0.99518473, -0.99518473, -0.95694034, -0.88192126, -0.77301045, -0.63439328, -0.47139674, -0.29028468, -0.09801714, 0.09801714, 0.29028468, 0.47139674, 0.63439328, 0.77301045, 0.88192126, 0.95694034, 0.99518473],
        [0.98917651, 0.90398929, 0.74095113, 0.51410274, 0.24298018, -0.04906767, -0.33688985, -0.5956993, -0.80320753, -0.94154407, -0.99879546, -0.97003125, -0.85772861, -0.67155895, -0.42755509, -0.14673047, 0.14673047, 0.42755509, 0.67155895, 0.85772861, 0.97003125, 0.99879546, 0.94154407, 0.80320753, 0.5956993, 0.33688985, 0.04906767, -0.24298018, -0.51410274, -0.74095113, -0.90398929, -0.98917651],
        [0.98078528, 0.83146961, 0.55557023, 0.19509032, -0.19509032, -0.55557023, -0.83146961, -0.98078528, -0.98078528, -0.83146961, -0.55557023, -0.19509032, 0.19509032, 0.55557023, 0.83146961, 0.98078528, 0.98078528, 0.83146961, 0.55557023, 0.19509032, -0.19509032, -0.55557023, -0.83146961, -0.98078528, -0.98078528, -0.83146961, -0.55557023, -0.19509032, 0.19509032, 0.55557023, 0.83146961, 0.98078528],
        [0.97003125, 0.74095113, 0.33688985, -0.14673047, -0.5956993, -0.90398929, -0.99879546, -0.85772861, -0.51410274, -0.04906767, 0.42755509, 0.80320753, 0.98917651, 0.94154407, 0.67155895, 0.24298018, -0.24298018, -0.67155895, -0.94154407, -0.98917651, -0.80320753, -0.42755509, 0.04906767, 0.51410274, 0.85772861, 0.99879546, 0.90398929, 0.5956993, 0.14673047, -0.33688985, -0.74095113, -0.97003125],
        [0.95694034, 0.63439328, 0.09801714, -0.47139674, -0.88192126, -0.99518473, -0.77301045, -0.29028468, 0.29028468, 0.77301045, 0.99518473, 0.88192126, 0.47139674, -0.09801714, -0.63439328, -0.95694034, -0.95694034, -0.63439328, -0.09801714, 0.47139674, 0.88192126, 0.99518473, 0.77301045, 0.29028468, -0.29028468, -0.77301045, -0.99518473, -0.88192126, -0.47139674, 0.09801714, 0.63439328, 0.95694034],
        [0.94154407, 0.51410274, -0.14673047, -0.74095113, -0.99879546, -0.80320753, -0.24298018, 0.42755509, 0.90398929, 0.97003125, 0.5956993, -0.04906767, -0.67155895, -0.98917651, -0.85772861, -0.33688985, 0.33688985, 0.85772861, 0.98917651, 0.67155895, 0.04906767, -0.5956993, -0.97003125, -0.90398929, -0.42755509, 0.24298018, 0.80320753, 0.99879546, 0.74095113, 0.14673047, -0.51410274, -0.94154407],
        [0.92387953, 0.38268343, -0.38268343, -0.92387953, -0.92387953, -0.38268343, 0.38268343, 0.92387953, 0.92387953, 0.38268343, -0.38268343, -0.92387953, -0.92387953, -0.38268343, 0.38268343, 0.92387953, 0.92387953, 0.38268343, -0.38268343, -0.92387953, -0.92387953, -0.38268343, 0.38268343, 0.92387953, 0.92387953, 0.38268343, -0.38268343, -0.92387953, -0.92387953, -0.38268343, 0.38268343, 0.92387953],
        [0.90398929, 0.24298018, -0.5956993, -0.99879546, -0.67155895, 0.14673047, 0.85772861, 0.94154407, 0.33688985, -0.51410274, -0.98917651, -0.74095113, 0.04906767, 0.80320753, 0.97003125, 0.42755509, -0.42755509, -0.97003125, -0.80320753, -0.04906767, 0.74095113, 0.98917651, 0.51410274, -0.33688985, -0.94154407, -0.85772861, -0.14673047, 0.67155895, 0.99879546, 0.5956993, -0.24298018, -0.90398929],
        [0.88192126, 0.09801714, -0.77301045, -0.95694034, -0.29028468, 0.63439328, 0.99518473, 0.47139674, -0.47139674, -0.99518473, -0.63439328, 0.29028468, 0.95694034, 0.77301045, -0.09801714, -0.88192126, -0.88192126, -0.09801714, 0.77301045, 0.95694034, 0.29028468, -0.63439328, -0.99518473, -0.47139674, 0.47139674, 0.99518473, 0.63439328, -0.29028468, -0.95694034, -0.77301045, 0.09801714, 0.88192126],
    ];

    private static bool $vipsInitialized = false;

    private static array $config = [
        'cores' => null,
        'maxCacheSize' => 64,
        'maxMemory' => 256,
        'sequentialAccess' => true,
        'disableCache' => false,
    ];

    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);

        if (self::$vipsInitialized) {
            self::$vipsInitialized = false;
            self::initVips();
        }
    }

    private static function initVips(): void
    {
        if (! self::$vipsInitialized) {
            $cores = self::$config['cores'] ?? min(8, max(2, self::getNumCores()));
            Config::concurrencySet($cores);
            Config::cacheSetMax(self::$config['maxCacheSize']);
            Config::cacheSetMaxMem(self::$config['maxMemory']);

            if (self::$config['disableCache']) {
                Config::cacheSetMax(0);
            }

            self::$vipsInitialized = true;
        }
    }

    public static function hashFromFile(string $filePath): int
    {
        self::initVips();

        try {
            $image = VipsImage::thumbnail($filePath, self::SIZE, [
                'height' => self::SIZE,
                'size' => 'force',
                'no_rotate' => true,
                'linear' => false,
            ]);

            return self::hashFromVipsImage($image);
        } catch (Exception $e) {
            throw new Exception('Failed to generate hash: '.$e->getMessage());
        }
    }

    public static function hashFromString(string $imageData, array $options = []): int
    {
        self::initVips();

        try {
            $image = VipsImage::thumbnail_buffer($imageData, self::SIZE, array_merge([
                'height' => self::SIZE,
                'size' => 'force',
                'no_rotate' => true,
                'linear' => false,
            ], $options));

            return self::hashFromVipsImage($image);
        } catch (Exception $e) {
            throw new Exception('Failed to generate hash from buffer: '.$e->getMessage());
        }
    }

    public static function hashFromVipsImage(VipsImage $image): int
    {
        if ($image->bands > 1) {
            $image = $image->colourspace('b-w');
        }

        $image = $image->resize(self::SIZE / $image->width, [
            'vscale' => self::SIZE / $image->height,
            'kernel' => 'lanczos3',
        ]);

        $pixels = self::extractPixelMatrix($image);
        $dctMatrix = self::applyDCT($pixels);
        $diagonal = self::extractDiagonal($dctMatrix);

        return self::generateHash($diagonal);
    }

    private static function extractPixelMatrix(VipsImage $image): array
    {
        $data = $image->writeToMemory();
        $pixels = unpack('C*', $data);

        $matrix = [];
        $i = 1;

        for ($y = 0; $y < self::SIZE; $y++) {
            $row = [];
            for ($x = 0; $x < self::SIZE; $x++) {
                $row[] = $pixels[$i++];
            }
            $matrix[] = $row;
        }

        return $matrix;
    }

    private static function applyDCT(array $pixels): array
    {
        $rows = [];
        for ($y = 0; $y < self::SIZE; $y++) {
            $rows[$y] = self::calculateDCT($pixels[$y], self::MATRIX_SIZE);
        }

        $matrix = [];
        $rowMatrixSize = self::MATRIX_SIZE;

        for ($x = 0; $x < self::MATRIX_SIZE; $x++) {
            $col = [];
            for ($y = 0; $y < self::SIZE; $y++) {
                $col[$y] = $rows[$y][$x];
            }
            $matrix[$x] = self::calculateDCT($col, $rowMatrixSize);
            $rowMatrixSize--;
        }

        return $matrix;
    }

    private static function calculateDCT(array $vector, int $partialSize): array
    {
        $transformed = [];

        for ($i = 0; $i < $partialSize; $i++) {
            $sum = 0;
            for ($j = 0; $j < self::SIZE; $j++) {
                $sum += $vector[$j] * self::DCT_11_32[$i][$j];
            }
            $sum *= self::SIZE_SQRT;
            if ($i === 0) {
                $sum *= 0.70710678118655;
            }
            $transformed[$i] = $sum;
        }

        return $transformed;
    }

    private static function extractDiagonal(array $matrix): array
    {
        $result = [];
        $size = self::MATRIX_SIZE;
        $mode = 0;
        $lower = 0;
        $max = (int) (ceil((($size * $size) / 2) + ($size * 0.5)));

        for ($t = 0; $t < (2 * $size - 1); $t++) {
            $t1 = $t;
            if ($t1 >= $size) {
                $mode++;
                $t1 = $size - 1;
                $lower++;
            } else {
                $lower = 0;
            }

            for ($i = $t1; $i >= $lower; $i--) {
                if (count($result) >= $max) {
                    return $result;
                }
                if (($t1 + $mode) % 2 === 0) {
                    $result[] = $matrix[$i][$t1 + $lower - $i];
                } else {
                    $result[] = $matrix[$t1 + $lower - $i][$i];
                }
            }
        }

        return $result;
    }

    private static function generateHash(array $diagonal): int
    {
        $pixels = array_slice($diagonal, 1, 64);
        $average = array_sum($pixels) / count($pixels);

        $hash = 0;
        for ($i = 0; $i < 64; $i++) {
            if ($i < count($pixels) && $pixels[$i] > $average) {
                $hash |= (1 << (63 - $i));
            }
        }

        return $hash;
    }

    public static function distance(int $hash1, int $hash2): int
    {
        $diff = $hash1 ^ $hash2;
        $distance = 0;

        for ($i = 0; $i < 64; $i++) {
            if (($diff >> $i) & 1) {
                $distance++;
            }
        }

        return $distance;
    }

    private static function getNumCores(): int
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
}
