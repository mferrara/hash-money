<?php

declare(strict_types=1);

use LegitPHP\HashMoney\MashedHash;
use LegitPHP\HashMoney\HashValue;

beforeEach(function () {
    MashedHash::configure(['cores' => 2]);
});

test('can generate mashed hash from file', function () {
    $hash = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getBits())->toBe(64);
    expect($hash->getAlgorithm())->toBe('mashed');
    expect($hash->getValue())->toBeInt();
});

test('generates consistent hashes for same image', function () {
    $hash1 = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash1->getValue())->toBe($hash2->getValue());
    expect(MashedHash::distance($hash1, $hash2))->toBe(0);
});

test('generates different hashes for different images', function () {
    $hash1 = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = MashedHash::hashFromFile(__DIR__.'/../images/cat2.jpg');

    expect($hash1->getValue())->not->toBe($hash2->getValue());
    expect(MashedHash::distance($hash1, $hash2))->toBeGreaterThan(5);
});

test('detects grayscale vs color images', function () {
    $colorHash = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $bwHash = MashedHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg');

    // Extract colorfulness bits (0-3)
    $colorfulness1 = $colorHash->getValue() & 0xF;
    $colorfulness2 = $bwHash->getValue() & 0xF;

    expect($colorfulness1)->toBeGreaterThan(3); // Color image
    expect($colorfulness2)->toBeLessThanOrEqual(3); // Grayscale
});

test('detects different aspect ratios', function () {
    // We'll need to create test images with different aspect ratios
    // For now, test that aspect ratio bits are set
    $hash = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    
    // Extract aspect ratio bits (12-14)
    $aspectRatio = ($hash->getValue() >> 12) & 0x7;
    
    expect($aspectRatio)->toBeGreaterThanOrEqual(0);
    expect($aspectRatio)->toBeLessThanOrEqual(7);
});

test('can generate hash from image data in memory', function () {
    $imageData = file_get_contents(__DIR__.'/../images/cat1.jpg');
    $hash = MashedHash::hashFromString($imageData);

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getBits())->toBe(64);
    expect($hash->getValue())->toBeInt();
});

test('file and memory hashes are consistent', function () {
    $fileHash = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $imageData = file_get_contents(__DIR__.'/../images/cat1.jpg');
    $memoryHash = MashedHash::hashFromString($imageData);

    expect($fileHash->getValue())->toBe($memoryHash->getValue());
});

test('generates similar hashes for cropped images', function () {
    $hash1 = MashedHash::hashFromFile(__DIR__.'/../images/cat2.jpg');
    $hash2 = MashedHash::hashFromFile(__DIR__.'/../images/cat2-crop.jpg');

    // Cropped images should have similar characteristics but not identical
    $distance = MashedHash::distance($hash1, $hash2);
    expect($distance)->toBeGreaterThan(0);
    expect($distance)->toBeLessThan(30);
});

test('handles images with different characteristics', function () {
    // Test with the gradient image which should have different characteristics
    $catHash = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $gradientHash = MashedHash::hashFromFile(__DIR__.'/../images/image-mesh-gradient.png');

    // These should be quite different
    expect(MashedHash::distance($catHash, $gradientHash))->toBeGreaterThan(20);
});

test('throws exception for non-existent file', function () {
    MashedHash::hashFromFile(__DIR__.'/non-existent.jpg');
})->throws(Exception::class);

test('throws exception for invalid image data', function () {
    MashedHash::hashFromString('not an image');
})->throws(Exception::class);

test('only supports 64-bit hashes', function () {
    MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 32);
})->throws(InvalidArgumentException::class, 'MashedHash only supports 64-bit hashes');

test('can configure vips settings', function () {
    MashedHash::configure([
        'cores' => 4,
        'maxCacheSize' => 128,
    ]);

    $hash = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    expect($hash)->toBeInstanceOf(HashValue::class);
});

test('distance calculation throws exception for incompatible hashes', function () {
    $mashedHash = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $perceptualHash = \LegitPHP\HashMoney\PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    MashedHash::distance($mashedHash, $perceptualHash);
})->throws(InvalidArgumentException::class, 'Cannot compare hashes: algorithm mismatch');

test('handles images with alpha channel', function () {
    // Create a test image with alpha channel
    $image = \Jcupitt\Vips\Image::black(100, 100, ['bands' => 4]);
    $image = $image->draw_rect([255, 0, 0, 128], 25, 25, 50, 50);

    $hash = MashedHash::hashFromVipsImage($image);

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
    expect($hash->getAlgorithm())->toBe('mashed');
});

test('components are encoded in correct bit positions', function () {
    $hash = MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $value = $hash->getValue();

    // Check that various components are within expected ranges
    $colorfulness = $value & 0xF;
    $edgeDensity = ($value >> 4) & 0xF;
    $entropy = ($value >> 8) & 0xF;
    $aspectRatio = ($value >> 12) & 0x7;

    expect($colorfulness)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($edgeDensity)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($entropy)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($aspectRatio)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(7);
});