<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/**
 * HashValue - An immutable value object representing a hash of arbitrary bit-size.
 *
 * Internally the hash is stored as a big-endian byte string. For bit sizes of
 * 8, 16, 32 or 64 the class exposes an integer-based API (getValue(), the int
 * constructor) that matches the previous public surface. For larger hashes
 * (128+ bit composite/wide hashes used for LSH) use the byte-oriented
 * accessors (toHex(), toBase64(), getBytes()) and the fromBytes()/fromHex()
 * named constructors.
 *
 * @example
 * ```php
 * // 64-bit int style (unchanged)
 * $hash = new HashValue(0x1234567890ABCDEF, 64, 'perceptual');
 * echo $hash->toHex(); // "1234567890abcdef"
 *
 * // Wide hash from bytes
 * $wide = HashValue::fromBytes(random_bytes(32), 'composite:perceptual+dhash');
 * echo $wide->getBits(); // 256
 * ```
 */
final class HashValue implements JsonSerializable
{
    /** Bit sizes that accept the integer form of the public constructor. */
    private const INT_CONSTRUCTOR_SIZES = [8, 16, 32, 64];

    /** Upper sanity bound on hash size. */
    private const MAX_BITS = 4096;

    /** Big-endian raw bytes. Always length = bits/8. */
    private readonly string $bytes;

    private readonly int $bits;

    private readonly string $algorithm;

    private readonly array $metadata;

    private ?string $hexCache = null;

    private ?string $binaryCache = null;

    /** @var array<int,int>|null Lazy popcount lookup table. */
    private static ?array $popcountTable = null;

    /**
     * Construct a HashValue.
     *
     * Two input modes:
     *  - int value + bits in {8, 16, 32, 64}: legacy int form.
     *  - raw big-endian byte string + bits matching strlen($value)*8: wide form.
     *
     * @param  int|string  $value  Integer value (≤64-bit) or raw byte string (any multiple-of-8 bit size)
     */
    public function __construct(
        int|string $value,
        int $bits,
        string $algorithm,
        array $metadata = []
    ) {
        if (empty($algorithm)) {
            throw new InvalidArgumentException('Algorithm name cannot be empty');
        }

        if (is_int($value)) {
            if (! in_array($bits, self::INT_CONSTRUCTOR_SIZES, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Unsupported bit size: %d. The integer constructor supports: %s. '.
                        'For larger hashes, use HashValue::fromBytes() or HashValue::fromHex().',
                        $bits,
                        implode(', ', self::INT_CONSTRUCTOR_SIZES)
                    )
                );
            }

            if ($bits < 64) {
                $maxValue = (1 << $bits) - 1;
                if ($value < 0 || $value > $maxValue) {
                    throw new InvalidArgumentException(
                        sprintf('Hash value %d exceeds %d-bit range (0-%d)', $value, $bits, $maxValue)
                    );
                }
            } elseif ($value > PHP_INT_MAX || $value < PHP_INT_MIN) {
                // Unreachable in practice on 64-bit PHP but mirrors prior API.
                throw new InvalidArgumentException('Hash value exceeds 64-bit integer range');
            }

            $this->bytes = self::intToBytes($value, $bits);
        } else {
            if ($bits <= 0 || $bits % 8 !== 0 || $bits > self::MAX_BITS) {
                throw new InvalidArgumentException(
                    sprintf('Invalid bit size %d (must be a positive multiple of 8 up to %d)', $bits, self::MAX_BITS)
                );
            }

            if (strlen($value) * 8 !== $bits) {
                throw new InvalidArgumentException(
                    sprintf('Byte string length %d does not match %d bits', strlen($value), $bits)
                );
            }

            $this->bytes = $value;
        }

        $this->bits = $bits;
        $this->algorithm = $algorithm;
        $this->metadata = $metadata;
    }

    /**
     * Create a HashValue directly from raw bytes. Bit size is derived from length.
     */
    public static function fromBytes(string $bytes, string $algorithm, array $metadata = []): self
    {
        return new self($bytes, strlen($bytes) * 8, $algorithm, $metadata);
    }

    /**
     * Create a HashValue from a hexadecimal string. Prefix "0x"/"0X" is accepted.
     */
    public static function fromHex(string $hex, int $bits, string $algorithm, array $metadata = []): self
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }

        if (! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Invalid hexadecimal string: must contain only hex digits');
        }

        $expectedLength = intdiv($bits, 4);
        if ($bits % 4 !== 0 || strlen($hex) !== $expectedLength) {
            throw new InvalidArgumentException(
                sprintf('Hex string length mismatch: expected %d characters for %d-bit hash, got %d',
                    $expectedLength, $bits, strlen($hex))
            );
        }

        $bytes = hex2bin($hex);

        return new self($bytes, $bits, $algorithm, $metadata);
    }

    /**
     * Create a HashValue from a binary (0/1) string. Length must be a multiple of 8.
     */
    public static function fromBinary(string $binary, string $algorithm, array $metadata = []): self
    {
        if (! preg_match('/^[01]+$/', $binary)) {
            throw new InvalidArgumentException('Invalid binary string: must contain only 0 and 1');
        }

        $bits = strlen($binary);
        if ($bits <= 0 || $bits % 8 !== 0 || $bits > self::MAX_BITS) {
            throw new InvalidArgumentException(
                sprintf('Binary string length %d does not match a supported bit size (must be a positive multiple of 8)', $bits)
            );
        }

        $bytes = '';
        for ($i = 0; $i < $bits; $i += 8) {
            $bytes .= chr((int) bindec(substr($binary, $i, 8)));
        }

        return new self($bytes, $bits, $algorithm, $metadata);
    }

    /**
     * Create a HashValue from a base64 (standard or url-safe) encoded byte string.
     */
    public static function fromBase64(string $base64, int $bits, string $algorithm, array $metadata = []): self
    {
        $normalized = strtr($base64, '-_', '+/');
        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64 string');
        }

        $expectedBytes = intdiv($bits, 8);
        if ($bits % 8 !== 0 || strlen($decoded) !== $expectedBytes) {
            throw new InvalidArgumentException(
                sprintf('Base64 decoded length mismatch: expected %d bytes for %d-bit hash, got %d',
                    $expectedBytes, $bits, strlen($decoded))
            );
        }

        return new self($decoded, $bits, $algorithm, $metadata);
    }

    /**
     * Returns the integer representation of the hash for bit sizes ≤64.
     *
     * @throws LogicException for hashes wider than 64 bits
     */
    public function getValue(): int
    {
        if (! in_array($this->bits, self::INT_CONSTRUCTOR_SIZES, true)) {
            throw new LogicException(
                sprintf(
                    'getValue() only supports bit sizes [%s]; got %d-bit. Use getBytes() or toHex().',
                    implode(', ', self::INT_CONSTRUCTOR_SIZES),
                    $this->bits
                )
            );
        }

        return self::bytesToInt($this->bytes, $this->bits);
    }

    /**
     * Raw big-endian bytes. Length is always bits/8.
     */
    public function getBytes(): string
    {
        return $this->bytes;
    }

    public function getBits(): int
    {
        return $this->bits;
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function toHex(): string
    {
        return $this->hexCache ??= bin2hex($this->bytes);
    }

    public function toBinary(): string
    {
        if ($this->binaryCache !== null) {
            return $this->binaryCache;
        }

        $binary = '';
        for ($i = 0, $len = strlen($this->bytes); $i < $len; $i++) {
            $binary .= sprintf('%08b', ord($this->bytes[$i]));
        }

        return $this->binaryCache = $binary;
    }

    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    public function toUrlSafeBase64(): string
    {
        return strtr($this->toBase64(), '+/', '-_');
    }

    public function equals(HashValue $other): bool
    {
        return $this->bytes === $other->bytes
            && $this->bits === $other->bits
            && $this->algorithm === $other->algorithm;
    }

    public function isCompatibleWith(HashValue $other): bool
    {
        return $this->algorithm === $other->algorithm
            && $this->bits === $other->bits;
    }

    /**
     * Read a single bit. Position 0 is the least-significant bit (last byte, LSB).
     */
    public function getBit(int $position): bool
    {
        if ($position < 0 || $position >= $this->bits) {
            throw new InvalidArgumentException(
                sprintf('Bit position %d out of range for %d-bit hash', $position, $this->bits)
            );
        }

        $byteIndex = strlen($this->bytes) - 1 - intdiv($position, 8);
        $bitIndex = $position % 8;

        return (bool) ((ord($this->bytes[$byteIndex]) >> $bitIndex) & 1);
    }

    public function countSetBits(): int
    {
        return self::popcountBytes($this->bytes);
    }

    /**
     * Hamming distance to another compatible hash (same algorithm + bits).
     *
     * @throws InvalidArgumentException when hashes are incompatible
     */
    public function hammingDistance(HashValue $other): int
    {
        if (! $this->isCompatibleWith($other)) {
            throw new InvalidArgumentException(
                sprintf('Cannot calculate Hamming distance: incompatible hashes (%s/%d-bit vs %s/%d-bit)',
                    $this->algorithm, $this->bits, $other->algorithm, $other->bits)
            );
        }

        return self::popcountBytes($this->bytes ^ $other->bytes);
    }

    /**
     * Normalized 0..1 value. Exact for ≤64-bit hashes; approximates via top 8 bytes for wider hashes.
     */
    public function normalized(): float
    {
        if (in_array($this->bits, self::INT_CONSTRUCTOR_SIZES, true)) {
            if ($this->bits === 64) {
                $value = $this->getValue();
                $unsigned = $value < 0 ? $value + pow(2, 64) : (float) $value;

                return $unsigned / (pow(2, 64) - 1);
            }

            $maxValue = (1 << $this->bits) - 1;

            return $this->getValue() / $maxValue;
        }

        $topBytes = str_pad(substr($this->bytes, 0, 8), 8, "\x00");
        $unpacked = unpack('J', $topBytes)[1];
        $unsigned = $unpacked < 0 ? $unpacked + pow(2, 64) : (float) $unpacked;

        return $unsigned / (pow(2, 64) - 1);
    }

    /**
     * @return mixed The metadata array or a specific value if a key is given
     */
    public function getMetadata(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? null;
    }

    public function withMetadata(array $metadata): self
    {
        return new self($this->bytes, $this->bits, $this->algorithm, $metadata);
    }

    /**
     * @return array{value: ?int, bits: int, algorithm: string, hex: string, metadata: array}
     */
    public function toArray(): array
    {
        return [
            'value' => in_array($this->bits, self::INT_CONSTRUCTOR_SIZES, true) ? $this->getValue() : null,
            'bits' => $this->bits,
            'algorithm' => $this->algorithm,
            'hex' => $this->toHex(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array{value?: ?int, bits?: int, algorithm?: string, hex?: string, metadata?: array}  $data
     */
    public static function fromArray(array $data): self
    {
        if (isset($data['value']) && isset($data['bits']) && isset($data['algorithm'])) {
            return new self(
                $data['value'],
                $data['bits'],
                $data['algorithm'],
                $data['metadata'] ?? []
            );
        }

        if (isset($data['hex']) && isset($data['bits']) && isset($data['algorithm'])) {
            return self::fromHex(
                $data['hex'],
                $data['bits'],
                $data['algorithm'],
                $data['metadata'] ?? []
            );
        }

        throw new InvalidArgumentException(
            'Array must contain either (value, bits, algorithm) or (hex, bits, algorithm)'
        );
    }

    /**
     * @return array{value: ?int, bits: int, algorithm: string, hex: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'value' => in_array($this->bits, self::INT_CONSTRUCTOR_SIZES, true) ? $this->getValue() : null,
            'bits' => $this->bits,
            'algorithm' => $this->algorithm,
            'hex' => $this->toHex(),
        ];
    }

    public function __debugInfo(): array
    {
        return [
            'value' => in_array($this->bits, self::INT_CONSTRUCTOR_SIZES, true) ? $this->getValue() : null,
            'bits' => $this->bits,
            'algorithm' => $this->algorithm,
            'hex' => $this->toHex(),
            'binary' => substr($this->toBinary(), 0, 32).'...',
            'setBits' => $this->countSetBits(),
            'metadata' => $this->metadata,
        ];
    }

    public function __toString(): string
    {
        return $this->toHex();
    }

    private static function intToBytes(int $value, int $bits): string
    {
        return match ($bits) {
            8 => chr($value & 0xFF),
            16 => pack('n', $value),
            32 => pack('N', $value),
            64 => pack('J', $value),
        };
    }

    private static function bytesToInt(string $bytes, int $bits): int
    {
        return match ($bits) {
            8 => ord($bytes),
            16 => unpack('n', $bytes)[1],
            32 => unpack('N', $bytes)[1],
            64 => unpack('J', $bytes)[1],
        };
    }

    private static function popcountBytes(string $bytes): int
    {
        if (self::$popcountTable === null) {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $table[$i] = substr_count(decbin($i), '1');
            }
            self::$popcountTable = $table;
        }

        $total = 0;
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $total += self::$popcountTable[ord($bytes[$i])];
        }

        return $total;
    }
}
