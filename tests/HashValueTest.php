<?php

use LegitPHP\HashMoney\HashValue;

test('can create hash value with valid parameters', function () {
    $hash = new HashValue(12345, 64, 'perceptual');

    expect($hash->getValue())->toBe(12345);
    expect($hash->getBits())->toBe(64);
    expect($hash->getAlgorithm())->toBe('perceptual');
});

test('throws exception for unsupported bit size', function () {
    expect(fn () => new HashValue(12345, 128, 'perceptual'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported bit size');
});

test('throws exception for empty algorithm name', function () {
    expect(fn () => new HashValue(12345, 64, ''))
        ->toThrow(InvalidArgumentException::class, 'Algorithm name cannot be empty');
});

test('throws exception when value exceeds bit range for small sizes', function () {
    // 8-bit max is 255
    expect(fn () => new HashValue(256, 8, 'test'))
        ->toThrow(InvalidArgumentException::class, 'exceeds 8-bit range');

    // 16-bit max is 65535
    expect(fn () => new HashValue(65536, 16, 'test'))
        ->toThrow(InvalidArgumentException::class, 'exceeds 16-bit range');

    // 32-bit max is 4294967295
    expect(fn () => new HashValue(4294967296, 32, 'test'))
        ->toThrow(InvalidArgumentException::class, 'exceeds 32-bit range');
});

test('allows negative values for 64-bit hashes', function () {
    $hash = new HashValue(-12345, 64, 'perceptual');

    expect($hash->getValue())->toBe(-12345);
});

test('hex representation works correctly', function () {
    $hash8 = new HashValue(255, 8, 'test');
    expect($hash8->toHex())->toBe('ff');

    $hash16 = new HashValue(65535, 16, 'test');
    expect($hash16->toHex())->toBe('ffff');

    $hash32 = new HashValue(4294967295, 32, 'test');
    expect($hash32->toHex())->toBe('ffffffff');

    $hash64 = new HashValue(1, 64, 'test');
    expect($hash64->toHex())->toBe('0000000000000001');
});

test('binary representation works correctly', function () {
    $hash8 = new HashValue(170, 8, 'test'); // 10101010
    expect($hash8->toBinary())->toBe('10101010');

    $hash16 = new HashValue(43690, 16, 'test'); // 1010101010101010
    expect($hash16->toBinary())->toBe('1010101010101010');

    $hash64Positive = new HashValue(1, 64, 'test');
    expect(strlen($hash64Positive->toBinary()))->toBe(64);
    expect(substr($hash64Positive->toBinary(), -1))->toBe('1');
});

test('equals method works correctly', function () {
    $hash1 = new HashValue(12345, 64, 'perceptual');
    $hash2 = new HashValue(12345, 64, 'perceptual');
    $hash3 = new HashValue(12346, 64, 'perceptual');
    $hash4 = new HashValue(12345, 32, 'perceptual');
    $hash5 = new HashValue(12345, 64, 'dhash');

    expect($hash1->equals($hash2))->toBeTrue();
    expect($hash1->equals($hash3))->toBeFalse();
    expect($hash1->equals($hash4))->toBeFalse();
    expect($hash1->equals($hash5))->toBeFalse();
});

test('isCompatibleWith method works correctly', function () {
    $hash1 = new HashValue(12345, 64, 'perceptual');
    $hash2 = new HashValue(67890, 64, 'perceptual');
    $hash3 = new HashValue(12345, 32, 'perceptual');
    $hash4 = new HashValue(12345, 64, 'dhash');

    expect($hash1->isCompatibleWith($hash2))->toBeTrue();
    expect($hash1->isCompatibleWith($hash3))->toBeFalse();
    expect($hash1->isCompatibleWith($hash4))->toBeFalse();
});

test('handles 64-bit negative values correctly', function () {
    // PHP's maximum negative 64-bit integer
    $hash = new HashValue(PHP_INT_MIN, 64, 'test');

    expect($hash->getValue())->toBe(PHP_INT_MIN);
    expect($hash->toHex())->toBeString();
    expect(strlen($hash->toHex()))->toBe(16);
    expect($hash->toBinary())->toBeString();
    expect(strlen($hash->toBinary()))->toBe(64);
});
