<?php

declare(strict_types=1);

use LegitPHP\HashMoney\ColorHistogramHash;

beforeEach(function () {
    ColorHistogramHash::configure(['cores' => 2]);
});

test('hash values use full 64-bit space', function () {
    // Generate hashes for different test images
    $hashes = [
        ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg'),
        ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg'),
        ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat2.jpg'),
        ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat2-crop.jpg'),
        ColorHistogramHash::hashFromFile(__DIR__.'/../images/image-mesh-gradient.png'),
    ];

    $hashValues = array_map(fn ($h) => $h->getValue(), $hashes);

    // Check that we're using a good portion of the 64-bit space
    $maxValue = max(array_map('abs', $hashValues));
    $bitsUsed = strlen(decbin($maxValue));

    // Should use at least 40 bits of the 64-bit space
    expect($bitsUsed)->toBeGreaterThan(40);
});

test('bits are well distributed across all 64 positions', function () {
    // Generate hashes for all test images
    $images = glob(__DIR__.'/../images/*.{jpg,jpeg,png}', GLOB_BRACE);
    $hashValues = [];

    foreach ($images as $image) {
        $hash = ColorHistogramHash::hashFromFile($image);
        $hashValues[] = $hash->getValue();
    }

    // Count how many times each bit position is set
    $bitUsage = array_fill(0, 64, 0);
    foreach ($hashValues as $value) {
        for ($i = 0; $i < 64; $i++) {
            if (($value >> $i) & 1) {
                $bitUsage[$i]++;
            }
        }
    }

    // Check that bits are used across the entire space
    $usedPositions = 0;
    $unusedRanges = [];
    $currentUnusedStart = null;

    for ($i = 0; $i < 64; $i++) {
        if ($bitUsage[$i] > 0) {
            $usedPositions++;
            if ($currentUnusedStart !== null) {
                $unusedRanges[] = [$currentUnusedStart, $i - 1];
                $currentUnusedStart = null;
            }
        } else {
            if ($currentUnusedStart === null) {
                $currentUnusedStart = $i;
            }
        }
    }

    if ($currentUnusedStart !== null) {
        $unusedRanges[] = [$currentUnusedStart, 63];
    }

    // Should use at least 50% of bit positions
    expect($usedPositions)->toBeGreaterThan(32);

    // Should not have large consecutive unused ranges (max 8 bits)
    foreach ($unusedRanges as $range) {
        $rangeSize = $range[1] - $range[0] + 1;
        expect($rangeSize)->toBeLessThanOrEqual(8);
    }
});

test('no predictable patterns like powers of 2 minus 1', function () {
    $images = glob(__DIR__.'/../images/*.{jpg,jpeg,png}', GLOB_BRACE);
    $hashValues = [];

    foreach ($images as $image) {
        $hash = ColorHistogramHash::hashFromFile($image);
        $hashValues[] = $hash->getValue();
    }

    // Check for powers of 2 minus 1 (1, 3, 7, 15, 31, 63, 127, 255, etc.)
    $powersOf2Minus1 = [];
    for ($i = 0; $i <= 32; $i++) {
        $powersOf2Minus1[] = (1 << $i) - 1;
    }

    $problematicValues = 0;
    foreach ($hashValues as $value) {
        if (in_array(abs($value), $powersOf2Minus1)) {
            $problematicValues++;
        }
    }

    // Should have very few or no values that are powers of 2 minus 1
    expect($problematicValues)->toBeLessThanOrEqual(1);
});

test('hash values have good entropy', function () {
    $images = glob(__DIR__.'/../images/*.{jpg,jpeg,png}', GLOB_BRACE);
    $hashValues = [];

    foreach ($images as $image) {
        $hash = ColorHistogramHash::hashFromFile($image);
        $hashValues[] = $hash->getValue();
    }

    // Calculate average number of bits set per hash
    $totalBitsSet = 0;
    foreach ($hashValues as $value) {
        $binary = decbin(abs($value));
        $totalBitsSet += substr_count($binary, '1');
    }

    $avgBitsSet = $totalBitsSet / count($hashValues);

    // Good entropy means roughly half the bits should be set (between 20 and 44 for 64 bits)
    expect($avgBitsSet)->toBeGreaterThan(20);
    expect($avgBitsSet)->toBeLessThan(44);
});

test('different images produce well-separated hashes', function () {
    $images = glob(__DIR__.'/../images/*.{jpg,jpeg,png}', GLOB_BRACE);
    $hashes = [];

    foreach ($images as $image) {
        $hashes[basename($image)] = ColorHistogramHash::hashFromFile($image);
    }

    // Calculate pairwise distances
    $distances = [];
    $imageNames = array_keys($hashes);

    for ($i = 0; $i < count($imageNames); $i++) {
        for ($j = $i + 1; $j < count($imageNames); $j++) {
            $distance = ColorHistogramHash::distance(
                $hashes[$imageNames[$i]],
                $hashes[$imageNames[$j]]
            );
            $distances[] = $distance;
        }
    }

    // Average distance should be significant (around 32 for random 64-bit values)
    $avgDistance = array_sum($distances) / count($distances);
    expect($avgDistance)->toBeGreaterThan(15);

    // Should have good variation in distances
    $minDistance = min($distances);
    $maxDistance = max($distances);
    expect($maxDistance - $minDistance)->toBeGreaterThan(10);
});

test('grayscale images produce different hash patterns than color images', function () {
    $grayscaleHash = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg');
    $colorHash = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    // Grayscale and color versions should have significantly different hashes
    $distance = ColorHistogramHash::distance($grayscaleHash, $colorHash);
    expect($distance)->toBeGreaterThan(10);

    // The hashes should be different
    expect($grayscaleHash->getValue())->not->toBe($colorHash->getValue());
});
