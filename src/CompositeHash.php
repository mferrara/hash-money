<?php

declare(strict_types=1);

namespace LegitPHP\HashMoney;

use LegitPHP\HashMoney\Contracts\HashStrategy;
use LegitPHP\HashMoney\Strategies\BlockMeanHashStrategy;
use LegitPHP\HashMoney\Strategies\ColorHistogramHashStrategy;
use LegitPHP\HashMoney\Strategies\CompositeHashStrategy;
use LegitPHP\HashMoney\Strategies\DHashStrategy;
use LegitPHP\HashMoney\Strategies\PerceptualHashStrategy;

/**
 * Composite Hash facade.
 *
 * Assembles a multi-view fingerprint from several independent hash
 * algorithms. The default recommendation for image similarity with LSH
 * banding is the 256-bit "quartet":
 *
 *     pHash64 + dHash64 + ColorHistogram64 + BlockMean64
 *
 * Each chunk captures a statistically independent kind of signal
 * (frequency-domain structure, gradient-domain structure, global color,
 * spatial-domain structure), which is exactly what LSH banding needs to
 * avoid hot buckets and what Hamming distance needs to reflect meaningful
 * dissimilarity.
 *
 * @example
 * ```php
 * $composite = CompositeHash::default(); // 256-bit quartet
 * $hash = $composite->hashFromFile('image.jpg');
 *
 * // Custom composition
 * $composite = CompositeHash::of(
 *     new PerceptualHashStrategy(),
 *     new DHashStrategy(),
 * );
 * ```
 */
class CompositeHash
{
    /**
     * Default 256-bit quartet: pHash + dHash + ColorHistogram + BlockMean.
     */
    public static function default(): CompositeHashStrategy
    {
        return new CompositeHashStrategy([
            new PerceptualHashStrategy,
            new DHashStrategy,
            new ColorHistogramHashStrategy,
            new BlockMeanHashStrategy,
        ]);
    }

    /**
     * Build a composite strategy from a variadic list of sub-strategies.
     * Each sub-strategy defaults to 64-bit output.
     */
    public static function of(HashStrategy ...$strategies): CompositeHashStrategy
    {
        return new CompositeHashStrategy($strategies);
    }
}
