<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use LegitPHP\Hash\PerceptualHash;

// Check PHP integer size
if (PHP_INT_SIZE < 8) {
    exit('Error: This package requires 64-bit PHP. Current PHP_INT_SIZE: '.PHP_INT_SIZE."\n");
}

echo "PHP Integer Info:\n";
echo 'PHP_INT_SIZE: '.PHP_INT_SIZE.' bytes ('.(PHP_INT_SIZE * 8)." bits)\n";
echo 'PHP_INT_MAX: '.PHP_INT_MAX."\n\n";

// Store hashes for comparison
$hashes = [];

// Get all image files from the images directory
$imagesDir = __DIR__.'/images';
$imageFiles = glob($imagesDir.'/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE);
sort($imageFiles);

echo "Image Hash Benchmark\n";
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
        $hash = PerceptualHash::hashFromFile($imagePath);
        $endTime = microtime(true);
        $timeTaken = ($endTime - $startTime) * 1000; // Convert to milliseconds

        // Format hash as 16-digit hex, handling negative values properly
        $hashHex = sprintf('%016x', $hash < 0 ? $hash + 0x10000000000000000 : $hash);
        echo "Hash: {$hashHex} (int: {$hash})\n";
        echo 'Time taken: '.sprintf('%.2f ms', $timeTaken)."\n";

        // Store hash for comparison
        $hashes[$filename] = $hash;

        // Check for related images (suffixed versions)
        $baseFilename = getBaseFilename($filename);
        if ($baseFilename !== $filename) {
            // This is a suffixed file, check if we have the base version
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $baseFilenameWithExt = $baseFilename.'.'.$extension;

            if (isset($hashes[$baseFilenameWithExt])) {
                $distance = PerceptualHash::distance($hashes[$baseFilenameWithExt], $hash);
                echo "Hamming distance from {$baseFilenameWithExt}: {$distance}\n";
            }
        } else {
            // This is a base file, check for any suffixed versions we've already processed
            foreach ($hashes as $processedFile => $processedHash) {
                if ($processedFile !== $filename && getBaseFilename($processedFile) === getBaseFilename($filename)) {
                    $distance = PerceptualHash::distance($hash, $processedHash);
                    echo "Hamming distance from {$processedFile}: {$distance}\n";
                }
            }
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
echo 'Total images processed: '.count($hashes)."\n";

// Find most similar pairs
if (count($hashes) > 1) {
    echo "\nSimilarity Analysis (Hamming Distance):\n";
    echo str_repeat('-', 40)."\n";

    $similarities = [];
    $files = array_keys($hashes);

    for ($i = 0; $i < count($files); $i++) {
        for ($j = $i + 1; $j < count($files); $j++) {
            $file1 = $files[$i];
            $file2 = $files[$j];
            $distance = PerceptualHash::distance($hashes[$file1], $hashes[$file2]);
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
    echo "Top 5 most similar image pairs:\n";
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
