<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney\Strategies;

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\Contracts\HashStrategy;
use LegitPHP\HashMoney\HashValue;

/**
 * Composite Hash Strategy.
 *
 * Concatenates the byte output of several sub-hash strategies into a single
 * wider HashValue. Its main purpose is to assemble an LSH-friendly multi-view
 * fingerprint — e.g. pHash + dHash + ColorHistogram + BlockMean → 256 bits
 * where each 64-bit chunk carries a different kind of signal.
 *
 * The composite's algorithm name is deterministic ("composite:<a>+<b>+...")
 * so two composites with the same components are compatible for
 * hammingDistance comparisons. Chunk boundaries are recorded in the
 * HashValue metadata so LSH helpers can band each chunk separately.
 */
class CompositeHashStrategy implements HashStrategy
{
    private const ALGORITHM_PREFIX = 'composite:';

    /** @var array<int, array{strategy: HashStrategy, bits: int}> */
    private array $components;

    private readonly string $algorithmName;

    private readonly int $totalBits;

    /**
     * Each component is either a HashStrategy (defaults to 64-bit output)
     * or an associative array of `['strategy' => HashStrategy, 'bits' => int]`
     * with an explicit bit size.
     *
     * @param  array<HashStrategy|array{strategy: HashStrategy, bits?: int}>  $components
     */
    public function __construct(array $components)
    {
        if (count($components) === 0) {
            throw new \InvalidArgumentException('Composite hash requires at least one component');
        }

        $normalized = [];
        foreach ($components as $i => $component) {
            if ($component instanceof HashStrategy) {
                $normalized[] = ['strategy' => $component, 'bits' => 64];
            } elseif (is_array($component) && isset($component['strategy']) && $component['strategy'] instanceof HashStrategy) {
                $normalized[] = [
                    'strategy' => $component['strategy'],
                    'bits' => $component['bits'] ?? 64,
                ];
            } else {
                throw new \InvalidArgumentException(
                    "Invalid component at index {$i}: expected HashStrategy or ['strategy' => HashStrategy, 'bits' => int]"
                );
            }
        }

        $this->components = $normalized;
        $this->totalBits = array_sum(array_column($normalized, 'bits'));
        $this->algorithmName = self::ALGORITHM_PREFIX.implode('+', array_map(
            fn (array $c) => $c['strategy']->getAlgorithmName(),
            $normalized
        ));
    }

    public function getAlgorithmName(): string
    {
        return $this->algorithmName;
    }

    public function getTotalBits(): int
    {
        return $this->totalBits;
    }

    /**
     * @return array<int, array{algorithm: string, bits: int, offset_bytes: int}>
     */
    public function getChunkLayout(): array
    {
        $layout = [];
        $offset = 0;
        foreach ($this->components as $c) {
            $layout[] = [
                'algorithm' => $c['strategy']->getAlgorithmName(),
                'bits' => $c['bits'],
                'offset_bytes' => $offset,
            ];
            $offset += intdiv($c['bits'], 8);
        }

        return $layout;
    }

    public function configure(array $config): void
    {
        foreach ($this->components as $c) {
            $c['strategy']->configure($config);
        }
    }

    public function hashFromFile(string $filePath, int $bits = 0): HashValue
    {
        $this->assertBitsMatch($bits);

        $bytes = '';
        foreach ($this->components as $c) {
            $sub = $c['strategy']->hashFromFile($filePath, $c['bits']);
            $bytes .= $sub->getBytes();
        }

        return HashValue::fromBytes($bytes, $this->algorithmName, [
            'chunks' => $this->getChunkLayout(),
        ]);
    }

    public function hashFromString(string $imageData, int $bits = 0, array $options = []): HashValue
    {
        $this->assertBitsMatch($bits);

        $bytes = '';
        foreach ($this->components as $c) {
            $sub = $c['strategy']->hashFromString($imageData, $c['bits'], $options);
            $bytes .= $sub->getBytes();
        }

        return HashValue::fromBytes($bytes, $this->algorithmName, [
            'chunks' => $this->getChunkLayout(),
        ]);
    }

    public function hashFromVipsImage(VipsImage $image, int $bits = 0): HashValue
    {
        $this->assertBitsMatch($bits);

        // Sub-strategies expect their image pre-thumbnailed to their own
        // target size, so round-trip through a lossless buffer to let each
        // component handle its own resize pipeline.
        $buffer = $image->writeToBuffer('.png[compression=0]');

        return $this->hashFromString($buffer);
    }

    public function distance(HashValue $hash1, HashValue $hash2): int
    {
        return $hash1->hammingDistance($hash2);
    }

    private function assertBitsMatch(int $bits): void
    {
        if ($bits !== 0 && $bits !== $this->totalBits) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Composite hash produces %d bits; requested %d. Pass 0 (default) or %d.',
                    $this->totalBits,
                    $bits,
                    $this->totalBits
                )
            );
        }
    }
}
