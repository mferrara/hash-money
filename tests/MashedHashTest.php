<?php

declare(strict_types=1);

use Jcupitt\Vips\Image;
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\MashedHash;
use LegitPHP\HashMoney\PerceptualHash;
use LegitPHP\HashMoney\Strategies\MashedHashStrategy;

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
    $color = MashedHash::decode(MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg'));
    $bw = MashedHash::decode(MashedHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg'));

    expect($color['colorfulness'])->toBeGreaterThan(3); // Color image
    expect($bw['colorfulness'])->toBeLessThanOrEqual(3); // Grayscale
});

test('detects different aspect ratios', function () {
    $decoded = MashedHash::decode(MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg'));

    expect($decoded['aspectRatio'])->toBeGreaterThanOrEqual(0);
    expect($decoded['aspectRatio'])->toBeLessThanOrEqual(7);
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
    $perceptualHash = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    MashedHash::distance($mashedHash, $perceptualHash);
})->throws(InvalidArgumentException::class, 'Cannot calculate Hamming distance: incompatible hashes');

test('handles images with alpha channel', function () {
    // Create a test image with alpha channel
    $image = Image::black(100, 100, ['bands' => 4]);
    $image = $image->draw_rect([255, 0, 0, 128], 25, 25, 50, 50);

    $hash = MashedHash::hashFromVipsImage($image);

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
    expect($hash->getAlgorithm())->toBe('mashed');
});

test('components decode into expected ranges', function () {
    $decoded = MashedHash::decode(MashedHash::hashFromFile(__DIR__.'/../images/cat1.jpg'));

    expect($decoded['colorfulness'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($decoded['edgeDensity'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($decoded['entropy'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($decoded['aspectRatio'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(7);
    expect($decoded['dominantColors'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($decoded['brightness']['mean'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($decoded['brightness']['range'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(15);
    expect($decoded['colorDistribution']['red'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(31);
});

test('gray coding: adjacent quantization levels differ by one bit in the field', function () {
    // Construct two component sets differing by exactly one ordinal level
    // and verify the Hamming distance in that field is exactly 1 bit.
    $strategy = new MashedHashStrategy;
    $reflection = new ReflectionMethod($strategy, 'packComponents');

    $base = [
        'colorfulness' => 7,
        'edgeDensity' => 0,
        'entropy' => 0,
        'aspectRatio' => 0,
        'hasBorder' => false,
        'colorDistribution' => 0,
        'spatialLayout' => 0,
        'brightness' => 0,
        'texture' => 0,
        'dominantColors' => 0,
        'special' => 0,
    ];

    $a = $reflection->invoke($strategy, $base);
    $b = $reflection->invoke($strategy, array_merge($base, ['colorfulness' => 8]));

    // Without Gray coding this would be 4 bits (binary 0111 vs 1000).
    $xor = $a ^ $b;
    $popcount = substr_count(decbin($xor < 0 ? $xor + PHP_INT_MAX + 1 + PHP_INT_MAX : $xor), '1');
    expect($popcount)->toBe(1);
});
