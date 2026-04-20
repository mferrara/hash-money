<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use InvalidArgumentException;

/**
 * LSH (Locality-Sensitive Hashing) band helpers.
 *
 * Turns a HashValue into a set of 64-bit bucket keys that can be used to
 * build a banded LSH index. Two hashes are "candidates" (likely to be within
 * some Hamming distance) when they share at least one bucket key.
 *
 * In a database, persist each key as an indexed BIGINT column (one column
 * per band, or a bridge table keyed by band index + key). A similarity
 * query then becomes an `OR` across the bands followed by a full Hamming
 * verification on the small candidate set.
 *
 * Tuning:
 *   B bands × R bits per band = hash bits. Increasing B raises recall (more
 *   hits on near-matches) but also candidate set size. A common starting
 *   point for a 256-bit hash is B=16, R=16 (65,536 buckets per band).
 */
final class Lsh
{
    /**
     * Split a hash into `bandCount` equal bands across the whole hash and
     * return the bucket key for each band.
     *
     * Each key is the band bytes interpreted as a big-endian unsigned
     * integer (padded to 8 bytes). For bands wider than 8 bytes the key is
     * xxh64 of the band bytes. Two hashes with identical band bytes always
     * produce identical keys.
     *
     * @return array<int, int> Bucket keys in band-order
     */
    public static function bands(HashValue $hash, int $bandCount): array
    {
        if ($bandCount <= 0) {
            throw new InvalidArgumentException('bandCount must be positive');
        }

        $bits = $hash->getBits();
        if ($bits % $bandCount !== 0) {
            throw new InvalidArgumentException(
                sprintf('bandCount %d does not evenly divide %d-bit hash', $bandCount, $bits)
            );
        }

        $bandBits = intdiv($bits, $bandCount);
        if ($bandBits % 8 !== 0) {
            throw new InvalidArgumentException(
                sprintf('Band size %d bits must be a positive multiple of 8', $bandBits)
            );
        }

        $bandBytesLen = intdiv($bandBits, 8);
        $bytes = $hash->getBytes();

        $keys = [];
        for ($i = 0; $i < $bandCount; $i++) {
            $keys[] = self::bucketKey(substr($bytes, $i * $bandBytesLen, $bandBytesLen));
        }

        return $keys;
    }

    /**
     * Per-chunk banding for composite hashes.
     *
     * Splits each component chunk (as recorded in the HashValue's 'chunks'
     * metadata) into `bandsPerChunk` bands and returns a map of
     * `algorithm => array<int, int>`. This preserves the semantic identity
     * of each chunk — "matched on pHash band 2" is a more meaningful
     * candidate signal than "matched on bits 96..111".
     *
     * @return array<string, array<int, int>>
     */
    public static function bandsByChunk(HashValue $hash, int $bandsPerChunk = 4): array
    {
        if ($bandsPerChunk <= 0) {
            throw new InvalidArgumentException('bandsPerChunk must be positive');
        }

        $chunks = $hash->getMetadata('chunks');
        if (! is_array($chunks) || $chunks === []) {
            throw new InvalidArgumentException(
                'Hash has no chunk metadata; use bands() for flat banding or use a composite hash.'
            );
        }

        $bytes = $hash->getBytes();
        $result = [];
        foreach ($chunks as $chunk) {
            $chunkBits = $chunk['bits'];
            if ($chunkBits % $bandsPerChunk !== 0) {
                throw new InvalidArgumentException(
                    sprintf('bandsPerChunk %d does not evenly divide %d-bit chunk "%s"',
                        $bandsPerChunk, $chunkBits, $chunk['algorithm'])
                );
            }

            $bandBits = intdiv($chunkBits, $bandsPerChunk);
            if ($bandBits % 8 !== 0) {
                throw new InvalidArgumentException(
                    sprintf('Chunk "%s" band size %d bits must be a multiple of 8',
                        $chunk['algorithm'], $bandBits)
                );
            }

            $bandBytesLen = intdiv($bandBits, 8);
            $chunkBytes = substr($bytes, $chunk['offset_bytes'], intdiv($chunkBits, 8));

            $keys = [];
            for ($i = 0; $i < $bandsPerChunk; $i++) {
                $keys[] = self::bucketKey(substr($chunkBytes, $i * $bandBytesLen, $bandBytesLen));
            }
            $result[$chunk['algorithm']] = $keys;
        }

        return $result;
    }

    /**
     * Interpret band bytes as a 64-bit bucket key. Bands of ≤8 bytes are
     * zero-padded and unpacked directly. Wider bands are hashed with xxh64.
     */
    private static function bucketKey(string $bandBytes): int
    {
        $len = strlen($bandBytes);
        if ($len === 0) {
            throw new InvalidArgumentException('Empty band bytes');
        }

        if ($len <= 8) {
            $padded = str_pad($bandBytes, 8, "\x00", STR_PAD_LEFT);

            return unpack('J', $padded)[1];
        }

        return unpack('J', hash('xxh64', $bandBytes, true))[1];
    }
}
