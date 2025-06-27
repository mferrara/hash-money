<?php

declare(strict_types=1);

/**
 * Development Example Script
 *
 * This script demonstrates the performance and capabilities of PerceptualHash,
 * DHash, ColorHistogramHash, and MashedHash algorithms with configurable bit sizes.
 * It's intended for development and benchmarking purposes, not for production use.
 *
 * To run: php example.php
 *
 * Note: Test images must be present in the images/ directory
 */

require __DIR__.'/vendor/autoload.php';

use LegitPHP\HashMoney\ColorHistogramHash;
use LegitPHP\HashMoney\DHash;
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\MashedHash;
use LegitPHP\HashMoney\PerceptualHash;

// Check PHP integer size
if (PHP_INT_SIZE < 8) {
    exit('Error: This package requires 64-bit PHP. Current PHP_INT_SIZE: '.PHP_INT_SIZE."\n");
}

echo "PHP Integer Info:\n";
echo 'PHP_INT_SIZE: '.PHP_INT_SIZE.' bytes ('.(PHP_INT_SIZE * 8)." bits)\n";
echo 'PHP_INT_MAX: '.PHP_INT_MAX."\n\n";

// Parse command line arguments
$algorithm = $argv[1] ?? 'all'; // 'perceptual', 'dhash', 'color', 'mashed', or 'all'
$bits = (int) ($argv[2] ?? 64); // 8, 16, 32, or 64

if (! in_array($algorithm, ['perceptual', 'dhash', 'color', 'mashed', 'all'])) {
    echo "Usage: php example.php [algorithm] [bits]\n";
    echo "  algorithm: perceptual, dhash, color, mashed, or all (default: all)\n";
    echo "  bits: 8, 16, 32, or 64 (default: 64)\n";
    exit(1);
}

if (! in_array($bits, [8, 16, 32, 64])) {
    echo "Error: Invalid bit size. Supported sizes are: 8, 16, 32, 64\n";
    exit(1);
}

// Store hashes for comparison
$perceptualHashes = [];
$dHashes = [];
$colorHashes = [];
$mashedHashes = [];

// Get all image files from the images directory
$imagesDir = __DIR__.'/images';
$imageFiles = glob($imagesDir.'/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE);
sort($imageFiles);

echo "Image Hash Benchmark\n";
echo "Algorithm: {$algorithm}\n";
echo "Hash size: {$bits} bits\n";
echo str_repeat('=', 80)."\n\n";

foreach ($imageFiles as $imagePath) {
    $filename = basename($imagePath);

    echo "Processing: {$filename}\n";
    echo str_repeat('-', 40)."\n";

    // Get file info
    $fileSize = filesize($imagePath);
    $fileSizeFormatted = formatBytes($fileSize);
    $fileType = mime_content_type($imagePath);

    echo "File size: {$fileSizeFormatted}\n";
    echo "File type: {$fileType}\n";

    // Benchmark hash generation
    $startTime = microtime(true);

    try {
        if ($algorithm === 'perceptual' || $algorithm === 'all') {
            $startTime = microtime(true);
            $pHash = PerceptualHash::hashFromFile($imagePath, $bits);
            $endTime = microtime(true);
            $timeTaken = ($endTime - $startTime) * 1000;

            echo "Perceptual Hash: {$pHash->toHex()} (int: {$pHash->getValue()})\n";
            echo 'Perceptual Time: '.sprintf('%.2f ms', $timeTaken)."\n";

            $perceptualHashes[$filename] = $pHash;

            // Check for related images
            checkRelatedImages($filename, $pHash, $perceptualHashes, 'Perceptual');
        }

        if ($algorithm === 'dhash' || $algorithm === 'all') {
            $startTime = microtime(true);
            $dHash = DHash::hashFromFile($imagePath, $bits);
            $endTime = microtime(true);
            $timeTaken = ($endTime - $startTime) * 1000;

            echo "DHash: {$dHash->toHex()} (int: {$dHash->getValue()})\n";
            echo 'DHash Time: '.sprintf('%.2f ms', $timeTaken)."\n";

            $dHashes[$filename] = $dHash;

            // Check for related images
            checkRelatedImages($filename, $dHash, $dHashes, 'DHash');
        }

        if ($algorithm === 'color' || $algorithm === 'all') {
            $startTime = microtime(true);
            $colorHash = ColorHistogramHash::hashFromFile($imagePath, $bits);
            $endTime = microtime(true);
            $timeTaken = ($endTime - $startTime) * 1000;

            echo "Color Histogram Hash: {$colorHash->toHex()} (int: {$colorHash->getValue()})\n";
            echo 'Color Histogram Time: '.sprintf('%.2f ms', $timeTaken)."\n";

            $colorHashes[$filename] = $colorHash;

            // Check for related images
            checkRelatedImages($filename, $colorHash, $colorHashes, 'ColorHistogram');
        }

        if ($algorithm === 'mashed' || $algorithm === 'all') {
            $startTime = microtime(true);
            $mHash = MashedHash::hashFromFile($imagePath, $bits);
            $endTime = microtime(true);
            $timeTaken = ($endTime - $startTime) * 1000;

            echo "MashedHash: {$mHash->toHex()} (int: {$mHash->getValue()})\n";
            echo 'MashedHash Time: '.sprintf('%.2f ms', $timeTaken)."\n";

            $mashedHashes[$filename] = $mHash;

            // Check for related images
            checkRelatedImages($filename, $mHash, $mashedHashes, 'MashedHash');
        }

    } catch (Exception $e) {
        echo 'ERROR: '.$e->getMessage()."\n";
    }

    echo "\n";
}

// Summary statistics
echo str_repeat('=', 80)."\n";
echo "Summary\n";
echo str_repeat('=', 80)."\n";

if ($algorithm === 'perceptual' || $algorithm === 'all') {
    echo 'Total images processed (Perceptual): '.count($perceptualHashes)."\n";
    if (count($perceptualHashes) > 1) {
        showSimilarityAnalysis($perceptualHashes, 'Perceptual');
    }
}

if ($algorithm === 'dhash' || $algorithm === 'all') {
    if ($algorithm === 'all') {
        echo "\n";
    }
    echo 'Total images processed (DHash): '.count($dHashes)."\n";
    if (count($dHashes) > 1) {
        showSimilarityAnalysis($dHashes, 'DHash');
    }
}

if ($algorithm === 'color' || $algorithm === 'all') {
    if ($algorithm === 'all') {
        echo "\n";
    }
    echo 'Total images processed (Color Histogram): '.count($colorHashes)."\n";
    if (count($colorHashes) > 1) {
        showSimilarityAnalysis($colorHashes, 'ColorHistogram');
    }
}

if ($algorithm === 'mashed' || $algorithm === 'all') {
    if ($algorithm === 'all') {
        echo "\n";
    }
    echo 'Total images processed (MashedHash): '.count($mashedHashes)."\n";
    if (count($mashedHashes) > 1) {
        showSimilarityAnalysis($mashedHashes, 'MashedHash');
    }
}

// Compare algorithms if all were run
if ($algorithm === 'all' && count($perceptualHashes) > 0) {
    echo "\n";
    echo str_repeat('=', 80)."\n";
    echo "Algorithm Comparison\n";
    echo str_repeat('=', 80)."\n";

    foreach ($perceptualHashes as $filename => $pHash) {
        if (isset($dHashes[$filename])) {
            $dHash = $dHashes[$filename];
            echo "{$filename}:\n";
            echo "  Perceptual:      {$pHash->toHex()}\n";
            echo "  DHash:           {$dHash->toHex()}\n";
            if (isset($colorHashes[$filename])) {
                $colorHash = $colorHashes[$filename];
                echo "  Color Histogram: {$colorHash->toHex()}\n";
            }
            if (isset($mashedHashes[$filename])) {
                $mHash = $mashedHashes[$filename];
                echo "  MashedHash:      {$mHash->toHex()}\n";
            }
        }
    }
}

/**
 * Format bytes to human readable format
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision).' '.$units[$pow];
}

/**
 * Get base filename without suffixes like -bw, -crop, etc.
 */
function getBaseFilename(string $filename): string
{
    $pathInfo = pathinfo($filename);
    $name = $pathInfo['filename'];

    // Remove common suffixes
    $suffixes = ['-bw', '-crop', '-thumb', '-small', '-large', '-medium'];
    foreach ($suffixes as $suffix) {
        if (str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
            break;
        }
    }

    return $name;
}

/**
 * Check for related images and calculate distances
 */
function checkRelatedImages(string $filename, HashValue $hash, array $hashes, string $algorithmName): void
{
    $baseFilename = getBaseFilename($filename);
    if ($baseFilename !== $filename) {
        // This is a suffixed file, check if we have the base version
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $baseFilenameWithExt = $baseFilename.'.'.$extension;

        if (isset($hashes[$baseFilenameWithExt])) {
            $distance = match ($algorithmName) {
                'Perceptual' => PerceptualHash::distance($hashes[$baseFilenameWithExt], $hash),
                'DHash' => DHash::distance($hashes[$baseFilenameWithExt], $hash),
                'ColorHistogram' => ColorHistogramHash::distance($hashes[$baseFilenameWithExt], $hash),
                'MashedHash' => MashedHash::distance($hashes[$baseFilenameWithExt], $hash),
            };
            echo "{$algorithmName} distance from {$baseFilenameWithExt}: {$distance}\n";
        }
    } else {
        // This is a base file, check for any suffixed versions we've already processed
        foreach ($hashes as $processedFile => $processedHash) {
            if ($processedFile !== $filename && getBaseFilename($processedFile) === getBaseFilename($filename)) {
                $distance = match ($algorithmName) {
                    'Perceptual' => PerceptualHash::distance($hash, $processedHash),
                    'DHash' => DHash::distance($hash, $processedHash),
                    'ColorHistogram' => ColorHistogramHash::distance($hash, $processedHash),
                    'MashedHash' => MashedHash::distance($hash, $processedHash),
                };
                echo "{$algorithmName} distance from {$processedFile}: {$distance}\n";
            }
        }
    }
}

/**
 * Show similarity analysis for a set of hashes
 */
function showSimilarityAnalysis(array $hashes, string $algorithmName): void
{
    echo "\n{$algorithmName} Similarity Analysis (Hamming Distance):\n";
    echo str_repeat('-', 40)."\n";

    $similarities = [];
    $files = array_keys($hashes);

    for ($i = 0; $i < count($files); $i++) {
        for ($j = $i + 1; $j < count($files); $j++) {
            $file1 = $files[$i];
            $file2 = $files[$j];
            $distance = match ($algorithmName) {
                'Perceptual' => PerceptualHash::distance($hashes[$file1], $hashes[$file2]),
                'DHash' => DHash::distance($hashes[$file1], $hashes[$file2]),
                'ColorHistogram' => ColorHistogramHash::distance($hashes[$file1], $hashes[$file2]),
                'MashedHash' => MashedHash::distance($hashes[$file1], $hashes[$file2]),
            };
            $similarities[] = [
                'file1' => $file1,
                'file2' => $file2,
                'distance' => $distance,
            ];
        }
    }

    // Sort by distance
    usort($similarities, function ($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });

    // Show top 5 most similar pairs
    echo "Top 5 most similar image pairs ({$algorithmName}):\n";
    for ($i = 0; $i < min(5, count($similarities)); $i++) {
        $sim = $similarities[$i];
        echo sprintf(
            "%2d. %-30s <-> %-30s Distance: %2d\n",
            $i + 1,
            $sim['file1'],
            $sim['file2'],
            $sim['distance']
        );
    }
}
