<?php

use LegitPHP\HashMoney\DHash;
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\PerceptualHash;

test('can generate dhash from file', function () {
    $hash = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
    expect($hash->getBits())->toBe(64);
    expect($hash->getAlgorithm())->toBe('dhash');
});

test('can generate dhash from string', function () {
    $imageData = file_get_contents(__DIR__.'/../images/cat1.jpg');
    $hash = DHash::hashFromString($imageData);

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
    expect($hash->getBits())->toBe(64);
    expect($hash->getAlgorithm())->toBe('dhash');
});

test('same image produces same dhash', function () {
    $hash1 = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash1->getValue())->toBe($hash2->getValue());
    expect($hash1->equals($hash2))->toBeTrue();
});

test('similar images have small hamming distance with dhash', function () {
    $hash1 = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = DHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg');

    $distance = DHash::distance($hash1, $hash2);

    expect($distance)->toBeInt();
    expect($distance)->toBeLessThan(10);
});

test('different images have larger hamming distance with dhash', function () {
    $hash1 = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = DHash::hashFromFile(__DIR__.'/../images/cat2.jpg');

    $distance = DHash::distance($hash1, $hash2);

    expect($distance)->toBeInt();
    expect($distance)->toBeGreaterThan(20);
});

test('can configure vips settings for dhash', function () {
    DHash::configure([
        'cores' => 4,
        'maxCacheSize' => 128,
        'maxMemory' => 512,
    ]);

    $hash = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getValue())->toBeInt();
});

test('dhash throws exception for non-existent file', function () {
    expect(fn () => DHash::hashFromFile('non-existent-file.jpg'))
        ->toThrow(Exception::class);
});

test('dhash throws exception for invalid image data', function () {
    expect(fn () => DHash::hashFromString('invalid image data'))
        ->toThrow(Exception::class);
});

test('can generate different bit size dhashes', function () {
    $hash64 = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 64);
    $hash32 = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 32);
    $hash16 = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 16);
    $hash8 = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 8);

    expect($hash64->getBits())->toBe(64);
    expect($hash32->getBits())->toBe(32);
    expect($hash16->getBits())->toBe(16);
    expect($hash8->getBits())->toBe(8);
});

test('cannot compare dhash with perceptual hash', function () {
    $dhash = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $phash = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect(fn () => DHash::distance($dhash, $phash))
        ->toThrow(InvalidArgumentException::class);
});

test('dhash produces different values than perceptual hash', function () {
    $dhash = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $phash = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($dhash->getValue())->not->toBe($phash->getValue());
});

test('dhash hex and binary representations work correctly', function () {
    $hash = DHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 16);

    $hex = $hash->toHex();
    $binary = $hash->toBinary();

    expect($hex)->toBeString();
    expect(strlen($hex))->toBe(4); // 16 bits = 4 hex chars
    expect($binary)->toBeString();
    expect(strlen($binary))->toBe(16); // 16 bits
});
