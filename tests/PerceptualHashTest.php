<?php

use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\PerceptualHash;

test('can generate hash from file', function () {
    $hash = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
    expect($hash->getBits())->toBe(64);
    expect($hash->getAlgorithm())->toBe('perceptual');
});

test('can generate hash from string', function () {
    $imageData = file_get_contents(__DIR__.'/../images/cat1.jpg');
    $hash = PerceptualHash::hashFromString($imageData);

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
    expect($hash->getBits())->toBe(64);
    expect($hash->getAlgorithm())->toBe('perceptual');
});

test('same image produces same hash', function () {
    $hash1 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash1->getValue())->toBe($hash2->getValue());
    expect($hash1->equals($hash2))->toBeTrue();
});

test('similar images have small hamming distance', function () {
    $hash1 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg');

    $distance = PerceptualHash::distance($hash1, $hash2);

    expect($distance)->toBeInt();
    expect($distance)->toBeLessThan(4);
});

test('different images have larger hamming distance', function () {
    $hash1 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat2.jpg');

    $distance = PerceptualHash::distance($hash1, $hash2);

    expect($distance)->toBeInt();
    expect($distance)->toBeGreaterThan(15);
});

test('can configure vips settings', function () {
    PerceptualHash::configure([
        'cores' => 4,
        'maxCacheSize' => 128,
        'maxMemory' => 512,
    ]);

    $hash = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
});

test('throws exception for non-existent file', function () {
    expect(fn () => PerceptualHash::hashFromFile('non-existent-file.jpg'))
        ->toThrow(Exception::class);
});

test('throws exception for invalid image data', function () {
    expect(fn () => PerceptualHash::hashFromString('invalid image data'))
        ->toThrow(Exception::class);
});

test('can generate different bit size hashes', function () {
    $hash64 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 64);
    $hash32 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 32);
    $hash16 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 16);

    expect($hash64->getBits())->toBe(64);
    expect($hash32->getBits())->toBe(32);
    expect($hash16->getBits())->toBe(16);
});

test('cannot compare hashes from different algorithms', function () {
    $hash1 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    // Create a mock hash with different algorithm
    $hash2 = new HashValue($hash1->getValue(), 64, 'different-algorithm');

    expect(fn () => PerceptualHash::distance($hash1, $hash2))
        ->toThrow(InvalidArgumentException::class);
});

test('cannot compare hashes with different bit sizes', function () {
    $hash1 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 64);
    $hash2 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 32);

    expect(fn () => PerceptualHash::distance($hash1, $hash2))
        ->toThrow(InvalidArgumentException::class);
});
