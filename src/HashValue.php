<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use InvalidArgumentException;

final class HashValue
{
    private const SUPPORTED_BITS = [8, 16, 32, 64];

    public function __construct(
        private readonly int $value,
        private readonly int $bits,
        private readonly string $algorithm
    ) {
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
        if ($this->bits === 64) {
            // Handle 64-bit values (including negative)
            return sprintf('%016x', $this->value);
        }

        $hexDigits = $this->bits / 4;

        return sprintf('%0'.$hexDigits.'x', $this->value);
    }

    public function toBinary(): string
    {
        if ($this->bits === 64 && $this->value < 0) {
            // For negative 64-bit values, we need special handling
            $binary = decbin($this->value);

            // PHP's decbin returns full binary for negative numbers, we want just 64 bits
            return substr($binary, -64);
        }

        return sprintf('%0'.$this->bits.'b', $this->value);
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
}
