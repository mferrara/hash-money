<?php

declare(strict_types=1);

use LegitPHP\HashMoney\HashValue;

describe('HashValue Enhanced Features', function () {
    describe('Factory Methods', function () {
        test('can create from hex string', function () {
            $hash = HashValue::fromHex('1234567890abcdef', 64, 'test');
            expect($hash->getValue())->toBe(0x1234567890ABCDEF);
            expect($hash->getBits())->toBe(64);
            expect($hash->getAlgorithm())->toBe('test');
        });

        test('can create from hex with lowercase 0x prefix', function () {
            $hash = HashValue::fromHex('0x1234', 16, 'test');
            expect($hash->getValue())->toBe(0x1234);
        });

        test('can create from hex with uppercase 0X prefix', function () {
            $hash = HashValue::fromHex('0X1234', 16, 'test');
            expect($hash->getValue())->toBe(0x1234);
        });

        test('handles negative 64-bit values from hex', function () {
            $hash = HashValue::fromHex('ffffffffffffffff', 64, 'test');
            expect($hash->getValue())->toBe(-1);
        });

        test('throws on invalid hex string', function () {
            expect(fn () => HashValue::fromHex('xyz', 16, 'test'))
                ->toThrow(InvalidArgumentException::class, 'Invalid hexadecimal string');
        });

        test('throws on hex length mismatch', function () {
            expect(fn () => HashValue::fromHex('1234', 64, 'test'))
                ->toThrow(InvalidArgumentException::class, 'Hex string length mismatch');
        });

        test('can create from binary string', function () {
            $hash = HashValue::fromBinary('10101010', 'test');
            expect($hash->getValue())->toBe(0b10101010);
            expect($hash->getBits())->toBe(8);
        });

        test('can create 64-bit from binary', function () {
            $binary = str_repeat('0', 63).'1';
            $hash = HashValue::fromBinary($binary, 'test');
            expect($hash->getValue())->toBe(1);
        });

        test('handles negative 64-bit from binary', function () {
            $binary = '1'.str_repeat('0', 63);
            $hash = HashValue::fromBinary($binary, 'test');
            expect($hash->getValue())->toBe(PHP_INT_MIN);
        });

        test('throws on invalid binary string', function () {
            expect(fn () => HashValue::fromBinary('10102', 'test'))
                ->toThrow(InvalidArgumentException::class, 'Invalid binary string');
        });

        test('throws on unsupported binary length', function () {
            expect(fn () => HashValue::fromBinary('101', 'test'))
                ->toThrow(InvalidArgumentException::class, 'does not match a supported bit size');
        });

        test('can create from base64', function () {
            $hash = new HashValue(0x1234567890ABCDEF, 64, 'test');
            $base64 = $hash->toBase64();

            $restored = HashValue::fromBase64($base64, 64, 'test');
            expect($restored->getValue())->toBe($hash->getValue());
        });

        test('throws on invalid base64', function () {
            expect(fn () => HashValue::fromBase64('!@#$', 64, 'test'))
                ->toThrow(InvalidArgumentException::class, 'Invalid base64 string');
        });

        test('factory methods support metadata', function () {
            $meta = ['source' => 'test', 'timestamp' => 12345];

            $fromHex = HashValue::fromHex('1234', 16, 'test', $meta);
            expect($fromHex->getMetadata())->toBe($meta);

            $fromBinary = HashValue::fromBinary('10101010', 'test', $meta);
            expect($fromBinary->getMetadata())->toBe($meta);

            $fromBase64 = HashValue::fromBase64('EjQ=', 16, 'test', $meta);
            expect($fromBase64->getMetadata())->toBe($meta);
        });
    });

    describe('String Representations', function () {
        test('toBase64 works correctly', function () {
            $hash = new HashValue(0x1234, 16, 'test');
            expect($hash->toBase64())->toBe('EjQ=');

            // Test with a known value and verify
            $hash64 = new HashValue(0x1234567890ABCDEF, 64, 'test');
            $base64 = $hash64->toBase64();

            // Verify it can be decoded back
            $restored = HashValue::fromBase64($base64, 64, 'test');
            expect($restored->getValue())->toBe($hash64->getValue());
        });

        test('toBase64 handles negative 64-bit values', function () {
            $hash = new HashValue(-1, 64, 'test');
            expect($hash->toBase64())->toBe('//////////8=');
        });

        test('toUrlSafeBase64 works correctly', function () {
            $hash = new HashValue(0xFFFFFF, 32, 'test');
            $base64 = $hash->toBase64();
            $urlSafe = $hash->toUrlSafeBase64();

            expect($urlSafe)->toBe(strtr($base64, '+/', '-_'));
        });

        test('__toString returns hex', function () {
            $hash = new HashValue(0x1234, 16, 'test');
            expect((string) $hash)->toBe('1234');
        });

        test('hex and binary representations are cached', function () {
            $hash = new HashValue(0x1234, 16, 'test');

            // First call
            $hex1 = $hash->toHex();
            $binary1 = $hash->toBinary();

            // Second call should use cache
            $hex2 = $hash->toHex();
            $binary2 = $hash->toBinary();

            expect($hex1)->toBe($hex2);
            expect($binary1)->toBe($binary2);
        });
    });

    describe('Bitwise Operations', function () {
        test('getBit works correctly', function () {
            $hash = new HashValue(0b10101010, 8, 'test');

            expect($hash->getBit(0))->toBeFalse(); // 0
            expect($hash->getBit(1))->toBeTrue();  // 1
            expect($hash->getBit(2))->toBeFalse(); // 0
            expect($hash->getBit(3))->toBeTrue();  // 1
            expect($hash->getBit(7))->toBeTrue();  // 1
        });

        test('getBit throws on out of range', function () {
            $hash = new HashValue(0xFF, 8, 'test');

            expect(fn () => $hash->getBit(-1))
                ->toThrow(InvalidArgumentException::class, 'out of range');

            expect(fn () => $hash->getBit(8))
                ->toThrow(InvalidArgumentException::class, 'out of range');
        });

        test('countSetBits works correctly', function () {
            expect((new HashValue(0b00000000, 8, 'test'))->countSetBits())->toBe(0);
            expect((new HashValue(0b11111111, 8, 'test'))->countSetBits())->toBe(8);
            expect((new HashValue(0b10101010, 8, 'test'))->countSetBits())->toBe(4);
            expect((new HashValue(0b11001100, 8, 'test'))->countSetBits())->toBe(4);
        });

        test('countSetBits handles 64-bit values', function () {
            $hash = new HashValue(-1, 64, 'test'); // All bits set
            expect($hash->countSetBits())->toBe(64);

            $hash2 = new HashValue(0, 64, 'test'); // No bits set
            expect($hash2->countSetBits())->toBe(0);
        });
    });

    describe('Comparison Methods', function () {
        test('hammingDistance works correctly', function () {
            $hash1 = new HashValue(0b11111111, 8, 'test');
            $hash2 = new HashValue(0b00000000, 8, 'test');
            expect($hash1->hammingDistance($hash2))->toBe(8);

            $hash3 = new HashValue(0b10101010, 8, 'test');
            $hash4 = new HashValue(0b01010101, 8, 'test');
            expect($hash3->hammingDistance($hash4))->toBe(8);

            $hash5 = new HashValue(0b11110000, 8, 'test');
            $hash6 = new HashValue(0b11001100, 8, 'test');
            expect($hash5->hammingDistance($hash6))->toBe(4);
        });

        test('hammingDistance throws on incompatible hashes', function () {
            $hash1 = new HashValue(0xFF, 8, 'test1');
            $hash2 = new HashValue(0xFF, 8, 'test2');

            expect(fn () => $hash1->hammingDistance($hash2))
                ->toThrow(InvalidArgumentException::class, 'incompatible hashes');
        });

        test('normalized returns value between 0 and 1', function () {
            $hash0 = new HashValue(0, 8, 'test');
            expect($hash0->normalized())->toBe(0.0);

            $hash255 = new HashValue(255, 8, 'test');
            expect(abs($hash255->normalized() - 1.0))->toBeLessThan(0.00001);

            $hash127 = new HashValue(127, 8, 'test');
            expect(abs($hash127->normalized() - (127 / 255)))->toBeLessThan(0.00001);
        });

        test('normalized handles 64-bit values', function () {
            $hash = new HashValue(-1, 64, 'test');
            expect(abs($hash->normalized() - 1.0))->toBeLessThan(0.00001);
        });
    });

    describe('Metadata Support', function () {
        test('can store and retrieve metadata', function () {
            $meta = ['source' => 'test', 'timestamp' => 12345];
            $hash = new HashValue(0x1234, 16, 'test', $meta);

            expect($hash->getMetadata())->toBe($meta);
            expect($hash->getMetadata('source'))->toBe('test');
            expect($hash->getMetadata('timestamp'))->toBe(12345);
            expect($hash->getMetadata('nonexistent'))->toBeNull();
        });

        test('withMetadata creates new instance', function () {
            $hash1 = new HashValue(0x1234, 16, 'test', ['a' => 1]);
            $hash2 = $hash1->withMetadata(['b' => 2]);

            expect($hash1)->not->toBe($hash2);
            expect($hash1->getMetadata())->toBe(['a' => 1]);
            expect($hash2->getMetadata())->toBe(['b' => 2]);
            expect($hash2->getValue())->toBe($hash1->getValue());
        });
    });

    describe('Array Serialization', function () {
        test('toArray includes all data', function () {
            $meta = ['source' => 'test'];
            $hash = new HashValue(0x1234, 16, 'test', $meta);
            $array = $hash->toArray();

            expect($array)->toBe([
                'value' => 0x1234,
                'bits' => 16,
                'algorithm' => 'test',
                'hex' => '1234',
                'metadata' => $meta,
            ]);
        });

        test('fromArray with value', function () {
            $data = [
                'value' => 0x1234,
                'bits' => 16,
                'algorithm' => 'test',
                'metadata' => ['source' => 'test'],
            ];

            $hash = HashValue::fromArray($data);
            expect($hash->getValue())->toBe(0x1234);
            expect($hash->getBits())->toBe(16);
            expect($hash->getAlgorithm())->toBe('test');
            expect($hash->getMetadata())->toBe(['source' => 'test']);
        });

        test('fromArray with hex', function () {
            $data = [
                'hex' => '1234',
                'bits' => 16,
                'algorithm' => 'test',
            ];

            $hash = HashValue::fromArray($data);
            expect($hash->getValue())->toBe(0x1234);
        });

        test('fromArray throws on missing data', function () {
            expect(fn () => HashValue::fromArray(['value' => 123]))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('JSON Serialization', function () {
        test('implements JsonSerializable', function () {
            $hash = new HashValue(0x1234, 16, 'test');
            $json = json_encode($hash);

            $expected = json_encode([
                'value' => 0x1234,
                'bits' => 16,
                'algorithm' => 'test',
                'hex' => '1234',
            ]);

            expect($json)->toBe($expected);
        });

        test('can round-trip through JSON', function () {
            $hash = new HashValue(0x1234567890ABCDEF, 64, 'perceptual');
            $json = json_encode($hash);
            $data = json_decode($json, true);

            $restored = HashValue::fromArray($data);
            expect($restored->equals($hash))->toBeTrue();
        });
    });

    describe('Debug Support', function () {
        test('__debugInfo provides useful information', function () {
            $hash = new HashValue(0xFF, 8, 'test', ['key' => 'value']);
            $debug = $hash->__debugInfo();

            expect($debug)->toHaveKeys(['value', 'bits', 'algorithm', 'hex', 'binary', 'setBits', 'metadata']);
            expect($debug['value'])->toBe(255);
            expect($debug['setBits'])->toBe(8);
            expect($debug['metadata'])->toBe(['key' => 'value']);
        });
    });

    describe('Edge Cases', function () {
        test('handles PHP_INT_MAX', function () {
            $hash = new HashValue(PHP_INT_MAX, 64, 'test');
            expect($hash->getValue())->toBe(PHP_INT_MAX);

            $hex = $hash->toHex();
            $restored = HashValue::fromHex($hex, 64, 'test');
            expect($restored->getValue())->toBe(PHP_INT_MAX);
        });

        test('handles PHP_INT_MIN', function () {
            $hash = new HashValue(PHP_INT_MIN, 64, 'test');
            expect($hash->getValue())->toBe(PHP_INT_MIN);

            $hex = $hash->toHex();
            $restored = HashValue::fromHex($hex, 64, 'test');
            expect($restored->getValue())->toBe(PHP_INT_MIN);
        });

        test('handles zero values', function () {
            foreach ([8, 16, 32, 64] as $bits) {
                $hash = new HashValue(0, $bits, 'test');
                expect($hash->countSetBits())->toBe(0);
                expect($hash->normalized())->toBe(0.0);
            }
        });
    });

    describe('fromHex leading zero preservation', function () {
        test('fromHex preserves leading zeros without prefix', function () {
            $hash = HashValue::fromHex('00ff', 16, 'test');
            expect($hash->getValue())->toBe(0x00FF);
            expect($hash->toHex())->toBe('00ff');
        });

        test('fromHex with lowercase hex prefix preserves leading zeros in value', function () {
            $hash = HashValue::fromHex('0x00ff', 16, 'test');
            expect($hash->getValue())->toBe(0x00FF);
            expect($hash->toHex())->toBe('00ff');
        });

        test('fromHex with uppercase hex prefix preserves leading zeros in value', function () {
            $hash = HashValue::fromHex('0X00ff', 16, 'test');
            expect($hash->getValue())->toBe(0x00FF);
            expect($hash->toHex())->toBe('00ff');
        });

        test('fromHex without prefix works for values starting with x-like chars', function () {
            // Hex value that starts with a valid hex digit but would be corrupted by ltrim character set
            $hash = HashValue::fromHex('0000000000000001', 64, 'test');
            expect($hash->getValue())->toBe(1);
        });
    });

    describe('normalized 64-bit correctness', function () {
        test('normalized returns correct value for positive 64-bit', function () {
            $hash = new HashValue(0, 64, 'test');
            expect($hash->normalized())->toBe(0.0);
        });

        test('normalized returns 1.0 for max unsigned 64-bit', function () {
            // -1 in signed = all bits set = max unsigned
            $hash = new HashValue(-1, 64, 'test');
            expect(abs($hash->normalized() - 1.0))->toBeLessThan(0.00001);
        });

        test('normalized returns approximately 0.5 for PHP_INT_MIN', function () {
            // PHP_INT_MIN = 0x8000000000000000 = 2^63 unsigned
            // normalized = 2^63 / (2^64 - 1) ≈ 0.5
            $hash = new HashValue(PHP_INT_MIN, 64, 'test');
            expect(abs($hash->normalized() - 0.5))->toBeLessThan(0.01);
        });

        test('normalized returns value in [0,1] range for arbitrary 64-bit', function () {
            $hash = new HashValue(12345678, 64, 'test');
            $normalized = $hash->normalized();
            expect($normalized)->toBeGreaterThanOrEqual(0.0);
            expect($normalized)->toBeLessThanOrEqual(1.0);
        });
    });
});
