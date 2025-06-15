<?php

use LegitPHP\Hash\PerceptualHash;

test('can generate hash from file', function () {
    $hash = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInt();
    expect($hash)->toBeGreaterThan(0);
});

test('can generate hash from string', function () {
    $imageData = file_get_contents(__DIR__.'/../images/cat1.jpg');
    $hash = PerceptualHash::hashFromString($imageData);

    expect($hash)->toBeInt();
    expect($hash)->toBeGreaterThan(0);
});

test('same image produces same hash', function () {
    $hash1 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash1)->toBe($hash2);
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

    expect($hash)->toBeInt();
    expect($hash)->toBeGreaterThan(0);
});

test('throws exception for non-existent file', function () {
    expect(fn () => PerceptualHash::hashFromFile('non-existent-file.jpg'))
        ->toThrow(Exception::class);
});

test('throws exception for invalid image data', function () {
    expect(fn () => PerceptualHash::hashFromString('invalid image data'))
        ->toThrow(Exception::class);
});
