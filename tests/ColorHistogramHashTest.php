<?php

declare(strict_types=1);

use LegitPHP\HashMoney\ColorHistogramHash;
use LegitPHP\HashMoney\HashValue;

beforeEach(function () {
    ColorHistogramHash::configure(['cores' => 2]);
});

test('can generate color histogram hash from file', function () {
    $hash = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getBits())->toBe(64);
    expect($hash->getAlgorithm())->toBe('color-histogram');
    expect($hash->getValue())->toBeInt();
});

test('generates different hashes for images with different colors', function () {
    $hash1 = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg');

    expect($hash1->getValue())->not->toBe($hash2->getValue());
    expect(ColorHistogramHash::distance($hash1, $hash2))->toBeGreaterThan(10);
});

test('generates similar hashes for color-similar images', function () {
    $hash1 = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat2.jpg');
    $hash2 = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat2-crop.jpg');

    // Cropped version should have similar color distribution
    expect(ColorHistogramHash::distance($hash1, $hash2))->toBeLessThan(20);
});

test('can generate hash from image data in memory', function () {
    $imageData = file_get_contents(__DIR__.'/../images/cat1.jpg');
    $hash = ColorHistogramHash::hashFromString($imageData);

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getBits())->toBe(64);
    expect($hash->getValue())->toBeInt();
});

test('generates consistent hashes for same image', function () {
    $hash1 = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash1->getValue())->toBe($hash2->getValue());
    expect(ColorHistogramHash::distance($hash1, $hash2))->toBe(0);
});

test('file and memory hashes are consistent', function () {
    $fileHash = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $imageData = file_get_contents(__DIR__.'/../images/cat1.jpg');
    $memoryHash = ColorHistogramHash::hashFromString($imageData);

    expect($fileHash->getValue())->toBe($memoryHash->getValue());
});

test('throws exception for non-existent file', function () {
    ColorHistogramHash::hashFromFile(__DIR__.'/non-existent.jpg');
})->throws(Exception::class);

test('throws exception for invalid image data', function () {
    ColorHistogramHash::hashFromString('not an image');
})->throws(Exception::class);

test('only supports 64-bit hashes', function () {
    ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 32);
})->throws(InvalidArgumentException::class, 'Color histogram hash only supports 64-bit hashes');

test('can configure HSV quantization levels', function () {
    // Configure with different quantization
    ColorHistogramHash::configureQuantization(16, 8, 8);
    $hash1 = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    // Reset to defaults
    ColorHistogramHash::configureQuantization(8, 4, 4);
    $hash2 = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    // Different quantization should produce different hashes
    expect($hash1->getValue())->not->toBe($hash2->getValue());
});

test('handles images with alpha channel', function () {
    // Create a test image with alpha channel
    $image = \Jcupitt\Vips\Image::black(100, 100, ['bands' => 4]);
    $image = $image->draw_rect([255, 0, 0, 128], 25, 25, 50, 50);

    $hash = ColorHistogramHash::hashFromVipsImage($image);

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
});

test('is robust to JPEG compression', function () {
    $originalHash = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    // Load and re-save with different quality
    $image = \Jcupitt\Vips\Image::newFromFile(__DIR__.'/../images/cat1.jpg');
    $compressedData = $image->jpegsave_buffer(['Q' => 50]);
    $compressedHash = ColorHistogramHash::hashFromString($compressedData);

    // Should be similar despite compression
    expect(ColorHistogramHash::distance($originalHash, $compressedHash))->toBeLessThan(15);
});

test('distance calculation throws exception for incompatible hashes', function () {
    $colorHash = ColorHistogramHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $perceptualHash = \LegitPHP\HashMoney\PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    ColorHistogramHash::distance($colorHash, $perceptualHash);
})->throws(InvalidArgumentException::class, 'Cannot compare hashes: algorithm mismatch');
