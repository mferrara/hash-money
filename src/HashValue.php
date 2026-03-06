<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use InvalidArgumentException;
use JsonSerializable;

/**
 * HashValue - An immutable value object representing a hash.
 *
 * This class provides a comprehensive API for working with hash values,
 * supporting multiple bit sizes and offering various utility methods
 * for comparison, transformation, and serialization.
 *
 * @example
 * ```php
 * $hash = new HashValue(0x1234567890ABCDEF, 64, 'perceptual');
 * echo $hash->toHex(); // "1234567890abcdef"
 * echo $hash->toBase64(); // "EjRWeJCr3v8="
 * 
 * // Factory methods
 * $fromHex = HashValue::fromHex('1234567890abcdef', 64, 'perceptual');
 * $fromBinary = HashValue::fromBinary('1010101010101010', 'dhash');
 * ```
 */
final class HashValue implements JsonSerializable
{
    private const SUPPORTED_BITS = [8, 16, 32, 64];
    
    /** @var string|null Cached hex representation */
    private ?string $hexCache = null;
    
    /** @var string|null Cached binary representation */
    private ?string $binaryCache = null;
    
    /** @var array Optional metadata for storing additional information */
    private array $metadata;

    public function __construct(
        private readonly int $value,
        private readonly int $bits,
        private readonly string $algorithm,
        array $metadata = []
    ) {
        $this->metadata = $metadata;
        if (! in_array($bits, self::SUPPORTED_BITS, true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported bit size: %d. Supported sizes are: %s', $bits, implode(', ', self::SUPPORTED_BITS))
            );
        }

        $maxValue = (1 << $bits) - 1;
        if ($bits === 64) {
            // For 64-bit, we need to handle signed integers
            $maxPositive = PHP_INT_MAX;
            $minNegative = PHP_INT_MIN;
            if ($value > $maxPositive || $value < $minNegative) {
                throw new InvalidArgumentException('Hash value exceeds 64-bit integer range');
            }
        } else {
            // For smaller bit sizes, ensure value fits
            if ($value < 0 || $value > $maxValue) {
                throw new InvalidArgumentException(
                    sprintf('Hash value %d exceeds %d-bit range (0-%d)', $value, $bits, $maxValue)
                );
            }
        }

        if (empty($algorithm)) {
            throw new InvalidArgumentException('Algorithm name cannot be empty');
        }
    }

    public function getValue(): int
    {
        return $this->value;
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
        if ($this->hexCache === null) {
            if ($this->bits === 64) {
                // Handle 64-bit values (including negative)
                $this->hexCache = sprintf('%016x', $this->value);
            } else {
                $hexDigits = $this->bits / 4;
                $this->hexCache = sprintf('%0'.$hexDigits.'x', $this->value);
            }
        }
        
        return $this->hexCache;
    }

    public function toBinary(): string
    {
        if ($this->binaryCache === null) {
            if ($this->bits === 64 && $this->value < 0) {
                // For negative 64-bit values, we need special handling
                $binary = decbin($this->value);
                // PHP's decbin returns full binary for negative numbers, we want just 64 bits
                $this->binaryCache = substr($binary, -64);
            } else {
                $this->binaryCache = sprintf('%0'.$this->bits.'b', $this->value);
            }
        }
        
        return $this->binaryCache;
    }

    public function equals(HashValue $other): bool
    {
        return $this->value === $other->value
            && $this->bits === $other->bits
            && $this->algorithm === $other->algorithm;
    }

    public function isCompatibleWith(HashValue $other): bool
    {
        return $this->algorithm === $other->algorithm
            && $this->bits === $other->bits;
    }
    
    /**
     * Create a HashValue from a hexadecimal string.
     *
     * @param string $hex The hexadecimal string (with or without 0x prefix)
     * @param int $bits The bit size of the hash
     * @param string $algorithm The algorithm name
     * @param array $metadata Optional metadata
     * @return self
     * @throws InvalidArgumentException If the hex string is invalid
     */
    public static function fromHex(string $hex, int $bits, string $algorithm, array $metadata = []): self
    {
        $hex = ltrim($hex, '0x');
        $hex = ltrim($hex, '0X');
        
        if (!ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Invalid hexadecimal string: must contain only hex digits');
        }
        
        $expectedLength = $bits / 4;
        if (strlen($hex) !== $expectedLength) {
            throw new InvalidArgumentException(
                sprintf('Hex string length mismatch: expected %d characters for %d-bit hash, got %d',
                    $expectedLength, $bits, strlen($hex))
            );
        }
        
        // Handle 64-bit values specially due to PHP's signed integers
        if ($bits === 64) {
            $high = hexdec(substr($hex, 0, 8));
            $low = hexdec(substr($hex, 8, 8));
            $value = ($high << 32) | $low;
            
            // Handle negative values
            if ($high >= 0x80000000) {
                $value = $value - (1 << 64);
            }
        } else {
            $value = (int)hexdec($hex);
        }
        
        return new self($value, $bits, $algorithm, $metadata);
    }
    
    /**
     * Create a HashValue from a binary string.
     *
     * @param string $binary The binary string (only 0 and 1 characters)
     * @param string $algorithm The algorithm name
     * @param array $metadata Optional metadata
     * @return self
     * @throws InvalidArgumentException If the binary string is invalid
     */
    public static function fromBinary(string $binary, string $algorithm, array $metadata = []): self
    {
        if (!preg_match('/^[01]+$/', $binary)) {
            throw new InvalidArgumentException('Invalid binary string: must contain only 0 and 1');
        }
        
        $bits = strlen($binary);
        if (!in_array($bits, self::SUPPORTED_BITS, true)) {
            throw new InvalidArgumentException(
                sprintf('Binary string length %d does not match a supported bit size: %s',
                    $bits, implode(', ', self::SUPPORTED_BITS))
            );
        }
        
        // Handle 64-bit values specially
        if ($bits === 64) {
            $value = 0;
            for ($i = 0; $i < 64; $i++) {
                if ($binary[$i] === '1') {
                    $value |= (1 << (63 - $i));
                }
            }
            
            // Handle sign bit for 64-bit values
            if ($binary[0] === '1') {
                $value = $value - (1 << 64);
            }
        } else {
            $value = bindec($binary);
        }
        
        return new self($value, $bits, $algorithm, $metadata);
    }
    
    /**
     * Create a HashValue from a base64 string.
     *
     * @param string $base64 The base64 encoded string
     * @param int $bits The bit size of the hash
     * @param string $algorithm The algorithm name
     * @param array $metadata Optional metadata
     * @return self
     * @throws InvalidArgumentException If the base64 string is invalid
     */
    public static function fromBase64(string $base64, int $bits, string $algorithm, array $metadata = []): self
    {
        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64 string');
        }
        
        $expectedBytes = $bits / 8;
        if (strlen($decoded) !== $expectedBytes) {
            throw new InvalidArgumentException(
                sprintf('Base64 decoded length mismatch: expected %d bytes for %d-bit hash, got %d',
                    $expectedBytes, $bits, strlen($decoded))
            );
        }
        
        // Convert bytes to integer
        $value = 0;
        for ($i = 0; $i < strlen($decoded); $i++) {
            $value = ($value << 8) | ord($decoded[$i]);
        }
        
        // Handle sign for 64-bit values
        if ($bits === 64 && ord($decoded[0]) >= 0x80) {
            $value = $value - (1 << 64);
        }
        
        return new self($value, $bits, $algorithm, $metadata);
    }
    
    /**
     * Convert hash to base64 encoding.
     *
     * @return string Base64 encoded hash
     */
    public function toBase64(): string
    {
        $bytes = '';
        $value = $this->value;
        
        // Handle negative 64-bit values
        if ($this->bits === 64 && $value < 0) {
            $value = $value + (1 << 64);
        }
        
        // Convert to bytes
        for ($i = ($this->bits / 8) - 1; $i >= 0; $i--) {
            $bytes .= chr(($value >> ($i * 8)) & 0xFF);
        }
        
        return base64_encode($bytes);
    }
    
    /**
     * Convert hash to URL-safe base64 encoding.
     *
     * @return string URL-safe base64 encoded hash
     */
    public function toUrlSafeBase64(): string
    {
        return strtr($this->toBase64(), '+/', '-_');
    }
    
    /**
     * Get the value of a specific bit.
     *
     * @param int $position The bit position (0-based, from right)
     * @return bool True if bit is set, false otherwise
     * @throws InvalidArgumentException If position is out of range
     */
    public function getBit(int $position): bool
    {
        if ($position < 0 || $position >= $this->bits) {
            throw new InvalidArgumentException(
                sprintf('Bit position %d out of range for %d-bit hash', $position, $this->bits)
            );
        }
        
        return (bool)(($this->value >> $position) & 1);
    }
    
    /**
     * Count the number of set bits (1s) in the hash.
     *
     * @return int Number of set bits
     */
    public function countSetBits(): int
    {
        $count = 0;
        $value = $this->value;
        
        // Handle negative values for 64-bit
        if ($this->bits === 64 && $value < 0) {
            $value = $value + (1 << 64);
        }
        
        for ($i = 0; $i < $this->bits; $i++) {
            if (($value >> $i) & 1) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Calculate Hamming distance to another hash.
     *
     * @param HashValue $other The other hash to compare with
     * @return int The Hamming distance (number of differing bits)
     * @throws InvalidArgumentException If hashes are incompatible
     */
    public function hammingDistance(HashValue $other): int
    {
        if (!$this->isCompatibleWith($other)) {
            throw new InvalidArgumentException(
                sprintf('Cannot calculate Hamming distance: incompatible hashes (%s/%d-bit vs %s/%d-bit)',
                    $this->algorithm, $this->bits, $other->algorithm, $other->bits)
            );
        }
        
        $diff = $this->value ^ $other->value;
        $distance = 0;
        
        for ($i = 0; $i < $this->bits; $i++) {
            if (($diff >> $i) & 1) {
                $distance++;
            }
        }
        
        return $distance;
    }
    
    /**
     * Return a normalized value between 0 and 1.
     *
     * @return float Normalized value
     */
    public function normalized(): float
    {
        $value = $this->value;
        
        // Handle negative 64-bit values
        if ($this->bits === 64 && $value < 0) {
            $value = $value + (1 << 64);
        }
        
        $maxValue = (1 << $this->bits) - 1;
        return $value / $maxValue;
    }
    
    /**
     * Get metadata associated with this hash.
     *
     * @param string|null $key Optional key to get specific metadata
     * @return mixed The metadata array or specific value if key provided
     */
    public function getMetadata(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }
        
        return $this->metadata[$key] ?? null;
    }
    
    /**
     * Create a new HashValue with updated metadata.
     *
     * @param array $metadata The new metadata
     * @return self New HashValue instance with updated metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self($this->value, $this->bits, $this->algorithm, $metadata);
    }
    
    /**
     * Convert hash to array representation.
     *
     * @return array{value: int, bits: int, algorithm: string, hex: string, metadata: array}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'bits' => $this->bits,
            'algorithm' => $this->algorithm,
            'hex' => $this->toHex(),
            'metadata' => $this->metadata,
        ];
    }
    
    /**
     * Create HashValue from array representation.
     *
     * @param array{value?: int, bits?: int, algorithm?: string, hex?: string, metadata?: array} $data
     * @return self
     * @throws InvalidArgumentException If required data is missing
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
     * JsonSerializable implementation.
     *
     * @return array{value: int, bits: int, algorithm: string, hex: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'value' => $this->value,
            'bits' => $this->bits,
            'algorithm' => $this->algorithm,
            'hex' => $this->toHex(),
        ];
    }
    
    /**
     * Get debug information for var_dump.
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        return [
            'value' => $this->value,
            'bits' => $this->bits,
            'algorithm' => $this->algorithm,
            'hex' => $this->toHex(),
            'binary' => substr($this->toBinary(), 0, 32) . '...',
            'setBits' => $this->countSetBits(),
            'metadata' => $this->metadata,
        ];
    }
    
    /**
     * String representation of the hash (returns hex).
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toHex();
    }
}
