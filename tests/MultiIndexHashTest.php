<?php

declare(strict_types=1);

use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\MultiIndexHash;

test('splits 64-bit hash into 8 x 8-bit chunks', function () {
    $hash = new HashValue(0x1234567890ABCDEF, 64, 'test');
    $chunks = MultiIndexHash::chunks($hash, 8);

    expect($chunks)->toBe([0x12, 0x34, 0x56, 0x78, 0x90, 0xAB, 0xCD, 0xEF]);
});

test('splits into 4 x 16-bit chunks', function () {
    $hash = new HashValue(0x1234567890ABCDEF, 64, 'test');
    $chunks = MultiIndexHash::chunks($hash, 4);

    expect($chunks)->toBe([0x1234, 0x5678, 0x90AB, 0xCDEF]);
});

test('splits into 2 x 32-bit chunks', function () {
    $hash = new HashValue(0x1234567890ABCDEF, 64, 'test');
    $chunks = MultiIndexHash::chunks($hash, 2);

    expect($chunks)->toBe([0x12345678, 0x90ABCDEF]);
});

test('identity split returns single chunk', function () {
    $hash = new HashValue(-1, 64, 'test');
    $chunks = MultiIndexHash::chunks($hash, 1);

    expect($chunks)->toHaveCount(1);
    expect($chunks[0])->toBe(-1);
});

test('handles negative 64-bit values', function () {
    $hash = new HashValue(PHP_INT_MIN, 64, 'test');
    $chunks = MultiIndexHash::chunks($hash, 8);

    // PHP_INT_MIN = 0x8000000000000000
    expect($chunks)->toBe([0x80, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00]);
});

test('handles -1 (all ones)', function () {
    $hash = new HashValue(-1, 64, 'test');
    $chunks = MultiIndexHash::chunks($hash, 8);

    expect($chunks)->toBe([0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF]);
});

test('throws on non-64-bit hash', function () {
    $hash = HashValue::fromBytes(str_repeat("\x00", 32), 'test');
    expect(fn () => MultiIndexHash::chunks($hash, 8))
        ->toThrow(InvalidArgumentException::class);
});

test('throws on chunk count that does not divide 64 evenly', function () {
    $hash = new HashValue(0, 64, 'test');
    expect(fn () => MultiIndexHash::chunks($hash, 6))
        ->toThrow(InvalidArgumentException::class);
});

test('pigeonhole property: hashes within k bits share at least one chunk', function () {
    // 0xFF XOR 0x1F = 0xE0 (3 bits). Only the low byte differs, so the
    // other 7 byte-chunks match exactly.
    $a = HashValue::fromBytes("\x00\x00\x00\x00\x00\x00\x00\xFF", 'test');
    $b = HashValue::fromBytes("\x00\x00\x00\x00\x00\x00\x00\x1F", 'test');

    expect($a->hammingDistance($b))->toBe(3);

    // Split into 8 chunks → pigeonhole: at least one chunk matches.
    $aChunks = MultiIndexHash::chunks($a, 8);
    $bChunks = MultiIndexHash::chunks($b, 8);

    $matches = 0;
    for ($i = 0; $i < 8; $i++) {
        if ($aChunks[$i] === $bChunks[$i]) {
            $matches++;
        }
    }
    expect($matches)->toBeGreaterThanOrEqual(7); // 7 zero bytes + 1 differing byte
});
