<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use InvalidArgumentException;

/**
 * Multi-Index Hashing (MIH) helpers for 64-bit hashes.
 *
 * For a Hamming search with threshold k bits on a 64-bit hash:
 * split the hash into m = k+1 (or more) equal chunks. By pigeonhole, any two
 * hashes within k bits Hamming must have at least one chunk matching
 * exactly. Index each chunk as its own B-tree column and query with m
 * equality lookups, union, then verify the full Hamming distance on the
 * small candidate set.
 *
 * MIH is much faster than naïve `BIT_COUNT(a ^ b) <= k` scans for small k
 * (typically k ≤ 8) on large tables. For larger k or for wider hashes
 * consider banding via {@see Lsh} with a composite multi-view hash instead.
 *
 * @example
 * ```php
 * // 9 chunks → safe for Hamming threshold up to 8
 * $chunks = MultiIndexHash::chunks($hash, 8); // 8 chunks, k ≤ 7
 * // INSERT chunks as BIGINT columns mih0..mih7, all indexed
 * ```
 */
final class MultiIndexHash
{
    /**
     * Split a 64-bit hash into `chunkCount` equal-width chunks. Returns the
     * chunks in most-significant-first order as unsigned PHP ints.
     *
     * @return array<int, int>
     */
    public static function chunks(HashValue $hash, int $chunkCount): array
    {
        if ($hash->getBits() !== 64) {
            throw new InvalidArgumentException(
                'MultiIndexHash only supports 64-bit hashes; got '.$hash->getBits().'-bit. '.
                'For wider hashes use Lsh banding.'
            );
        }

        if ($chunkCount <= 0 || 64 % $chunkCount !== 0) {
            throw new InvalidArgumentException(
                sprintf('chunkCount %d must evenly divide 64 bits', $chunkCount)
            );
        }

        $chunkBits = intdiv(64, $chunkCount);
        $bytes = $hash->getBytes();

        if ($chunkBits % 8 === 0) {
            $chunkBytesLen = intdiv($chunkBits, 8);
            $result = [];
            for ($i = 0; $i < $chunkCount; $i++) {
                $chunkBytes = substr($bytes, $i * $chunkBytesLen, $chunkBytesLen);
                $padded = str_pad($chunkBytes, 8, "\x00", STR_PAD_LEFT);
                $result[] = unpack('J', $padded)[1];
            }

            return $result;
        }

        $value = $hash->getValue();
        $mask = $chunkBits >= 64 ? -1 : ((1 << $chunkBits) - 1);
        $result = [];
        for ($i = $chunkCount - 1; $i >= 0; $i--) {
            $result[] = ($value >> ($i * $chunkBits)) & $mask;
        }

        return $result;
    }
}
