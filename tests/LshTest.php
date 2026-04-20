<?php

declare(strict_types=1);

use LegitPHP\HashMoney\CompositeHash;
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\Lsh;

test('flat banding returns bandCount keys', function () {
    $hash = new HashValue(0x1234567890ABCDEF, 64, 'test');
    $bands = Lsh::bands($hash, 8);

    expect($bands)->toBeArray();
    expect($bands)->toHaveCount(8);
});

test('flat banding produces expected keys for 64-bit', function () {
    // 0x12 34 56 78 90 AB CD EF split into 8 × 8-bit bands
    $hash = new HashValue(0x1234567890ABCDEF, 64, 'test');
    $bands = Lsh::bands($hash, 8);

    expect($bands)->toBe([0x12, 0x34, 0x56, 0x78, 0x90, 0xAB, 0xCD, 0xEF]);
});

test('flat banding on 256-bit hash', function () {
    $bytes = str_repeat("\xAA", 32);
    $hash = HashValue::fromBytes($bytes, 'test');

    $bands16 = Lsh::bands($hash, 16);
    expect($bands16)->toHaveCount(16);
    // Each band is 16 bits = 2 bytes of 0xAA = 0xAAAA
    foreach ($bands16 as $key) {
        expect($key)->toBe(0xAAAA);
    }
});

test('identical hashes produce identical band keys', function () {
    $hash = new HashValue(0x7AFEBABEDEADBEEF, 64, 'test');
    $a = Lsh::bands($hash, 8);
    $b = Lsh::bands($hash, 8);

    expect($a)->toBe($b);
});

test('different hashes produce different band keys (usually)', function () {
    $a = Lsh::bands(new HashValue(0x1111111111111111, 64, 'test'), 8);
    $b = Lsh::bands(new HashValue(0x2222222222222222, 64, 'test'), 8);

    expect($a)->not->toBe($b);
});

test('throws on band count that does not divide hash evenly', function () {
    $hash = new HashValue(0, 64, 'test');
    expect(fn () => Lsh::bands($hash, 7))
        ->toThrow(InvalidArgumentException::class);
});

test('throws on band size not divisible by 8', function () {
    // 64 bits / 32 bands = 2 bits per band = invalid
    $hash = new HashValue(0, 64, 'test');
    expect(fn () => Lsh::bands($hash, 32))
        ->toThrow(InvalidArgumentException::class);
});

test('bandsByChunk on composite hash', function () {
    $composite = CompositeHash::default();
    $hash = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');

    $perChunk = Lsh::bandsByChunk($hash, 4);

    expect($perChunk)->toHaveKeys(['perceptual', 'dhash', 'color-histogram', 'block-mean']);
    foreach ($perChunk as $keys) {
        expect($keys)->toHaveCount(4);
        foreach ($keys as $k) {
            expect($k)->toBeInt();
        }
    }
});

test('bandsByChunk gives same keys for identical hashes', function () {
    $composite = CompositeHash::default();
    $a = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');
    $b = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect(Lsh::bandsByChunk($a))->toBe(Lsh::bandsByChunk($b));
});

test('bandsByChunk throws on non-composite hash', function () {
    $hash = new HashValue(0, 64, 'test');
    expect(fn () => Lsh::bandsByChunk($hash))
        ->toThrow(InvalidArgumentException::class);
});
