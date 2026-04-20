# Changelog

All notable changes to `hash-money` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.0] - 2026-04-20

### Added

- **BlockMeanHash** — classic block-mean hash (resize to √bits × √bits
  grayscale → threshold each cell against the overall mean). Supports
  8/16/32/64/128/256-bit output. Spatial-domain fingerprint that
  complements pHash (frequency-domain) and dHash (gradient-domain); ideal
  as a 4th chunk in a composite hash.
- **CompositeHashStrategy + CompositeHash facade** — concatenates the
  byte output of several sub-strategies into one wider `HashValue`. The
  default `CompositeHash::default()` is the 256-bit quartet
  `pHash64 + dHash64 + ColorHistogram64 + BlockMean64`, a multi-view
  fingerprint whose chunks are statistically independent (which is what
  LSH banding needs to avoid hot buckets). Chunk layout is preserved in
  the `HashValue`'s `chunks` metadata.
- **`Lsh` banding helpers** — `Lsh::bands($hash, $count)` splits a hash
  into N equal bands and returns 64-bit bucket keys for building a
  banded LSH index. `Lsh::bandsByChunk($hash, $bandsPerChunk)` does
  per-chunk sub-banding for composite hashes, preserving the semantic
  identity of each chunk (pHash band 2, color band 1, etc.) for more
  meaningful candidate filtering.
- **`MultiIndexHash::chunks($hash, $count)`** — splits a 64-bit hash into
  equal chunks for Multi-Index Hashing. For Hamming threshold k ≤ chunks-1
  on a 64-bit hash, pigeonhole guarantees at least one chunk matches
  exactly between any two hashes within k bits. Massively faster than
  naïve `BIT_COUNT` scans at small k on large tables.
- **`HashValue` byte-backed internal representation** — `HashValue` now
  stores the hash as a big-endian byte string internally, supporting any
  multiple-of-8 bit size from 8 up to 4096 bits. The integer constructor
  (`new HashValue(int, int, string)`) continues to work for the standard
  {8, 16, 32, 64} sizes, so existing callers are unaffected. Wider hashes
  use `HashValue::fromBytes()`, `fromHex()`, `fromBase64()`, etc. The new
  `getBytes()` accessor returns the raw big-endian bytes; `getValue()`
  throws `LogicException` for hashes wider than 64 bits.
- **`MashedHash::decode($hash)`** — decoder that returns a structured
  array of the hash's semantic fields (colorfulness, edgeDensity, …).
- **`docs/LARAVEL_BRIDGE.md`** — proposed design for the future
  `legitphp/hash-money-laravel` package (Eloquent cast, migration
  helpers, query scopes, pluggable index drivers, jobs, config).

### Changed

- **MashedHash ordinal fields are now Gray-coded.** Adjacent quantization
  levels (e.g. colorfulness 7 ↔ 8) previously caused Hamming-distance
  spikes because their binary encodings flipped up to 4 bits; now they
  differ by exactly one bit, which makes Hamming distance a meaningful
  similarity measure for MashedHash instead of a fingerprint-category
  signal. Only ordinal fields are coded — flag bits (border flag,
  colorDistribution dominance, special indicators) and categorical
  fields (spatialLayout) are left alone. **Reading raw bit fields of
  a MashedHash now sees the Gray-coded value; use `MashedHash::decode()`
  to read semantic levels.** Hash values differ from previous versions;
  rehash existing MashedHash data to use the new encoding.

## [1.1.0] - 2026-04-17

First tagged release since `v1.0.0` (2025-06-15). A substantial amount of
work accumulated on `main` in between — three new hash algorithms, a
full-featured `HashValue` value object, and several correctness and
configuration fixes. This release bundles all of it under a single
minor-version bump.

### Added

- **DHash** — gradient-based 8/16/32/64-bit difference hash. Faster than
  pHash, good for cropped variants, more sensitive to rotation.
- **ColorHistogramHash** — 64-bit HSV color-distribution hash with
  configurable quantization (default 8×4×4 bins). Complements spatial
  hashes by capturing color information.
- **MashedHash** — 64-bit composite fingerprint encoding 11 image
  characteristics (colorfulness, edge density, entropy, aspect ratio,
  borders, spatial layout, brightness, texture, dominant colors, etc.).
- **`HashValue` value object** — immutable, type-safe representation of
  a hash result (previously hashes were raw integers). Includes:
  - factory methods: `fromHex`, `fromBinary`, `fromBase64`, `fromArray`
  - serialization: `toHex`, `toBinary`, `toBase64`, `toUrlSafeBase64`,
    `jsonSerialize`
  - metadata attachment: `withMetadata`, `getMetadata`
  - bitwise analysis: `getBit`, `countSetBits`, `normalized`
  - comparison: `equals`, `isCompatibleWith`, `hammingDistance`
- **Per-strategy VIPS configuration** via `configure()` on each facade
  (`cores`, `maxCacheSize`, `maxMemory`, `sequentialAccess`,
  `disableCache`). Each strategy maintains an independent configuration,
  so configuring one algorithm no longer overrides another.
- **Automatic EXIF orientation handling** via libvips thumbnail loading.
- **Linear color-space processing** for more accurate perceptual
  comparison.
- **Alpha channel flattening** against a white background.
- **Strategy pattern** internally so new algorithms can be added without
  touching the facade layer.
- **PRE_BATCH_REVIEW.md** — operator guide for calibrating a large-scale
  rollout before generating hashes for ~100K+ images. Covers version
  prereqs, timing calibration, distribution and collision checks,
  known-pair validation, failure-mode probes, database and query-plan
  review, and a go/no-go checklist.
- **Prebuilt CI test image** with libvips preinstalled for faster CI
  runs.
- Comprehensive test coverage for each algorithm, for `HashValue`, and
  for `ColorHistogramHash` bit distribution.

### Fixed

These fixes landed during `dev-main` development between `v1.0.0` and
`1.1.0`. Anyone running unpinned `dev-main` in that window should
re-read this list to decide whether any stored hashes need regeneration.

- **ColorHistogramHash**: fixed a bit-distribution issue that caused
  ~96% collisions (only ~36 of 64 bits were effectively populated, and
  hash values clustered near `2^n - 1`). The algorithm now uses all
  64 bits with MurmurHash-inspired mixing. ColorHistogramHash is a new
  algorithm in this release, but any `dev-main` consumer that generated
  values before the fix needs to regenerate.
- **ColorHistogramHash**: fixed stale `$isGrayscale` state in
  `ColorHistogramHashStrategy`. Only affected direct
  `hashFromVipsImage()` callers who reused a single strategy instance
  across interleaved grayscale and color inputs; `hashFromString()`
  and `hashFromFile()` callers were unaffected.
- **VIPS cache memory unit**: `Config::cacheSetMaxMem()` takes bytes,
  not megabytes. The previous code passed the `maxMemory` value
  directly (`256` → 256 *bytes* of cache), effectively disabling the
  memory cache. Now multiplied by `1024 * 1024` before being passed
  to libvips.
- **Per-strategy config isolation**: `AbstractHashStrategy` stored
  config in a single `static` array shared across all subclasses, so
  `PerceptualHash::configure(['cores' => 8])` would overwrite
  `DHash::configure(['cores' => 2])`. Each strategy now has its own
  config slot keyed by `static::class`.
- **Strategy exception chains**: strategies now pass the underlying
  `\Exception` as the `previous` argument of their
  `\RuntimeException` wrappers, so the full VIPS error chain is
  available via `$e->getPrevious()`.
- **`HashValue::fromHex`**: prefix stripping used `ltrim('0x')`, which
  would also strip any leading `0` or `x` hex digits. Now uses
  `str_starts_with` to strip only a leading `0x` / `0X`.
- **`HashValue::normalized`**: for 64-bit hashes, the subtraction
  `$value - (1 << 64)` overflowed PHP's signed int. Now uses float
  math.
- **README config-key examples**: previously documented in snake_case
  (`concurrency`, `cache_max`, `disable_cache`), now match the actual
  camelCase keys (`cores`, `maxCacheSize`, `disableCache`).

### Notes

- **64-bit PHP required.** `int` on 32-bit hosts cannot represent the
  full 64-bit hash range.
- **ColorHistogramHash** and **MashedHash** have dataset-dependent
  collision characteristics — running `PRE_BATCH_REVIEW.md` Phase 3
  on a representative sample is strongly recommended before committing
  them to a duplicate-detection pipeline.

## [1.0.0] - 2025-06-15

Initial release. PerceptualHash (DCT-based, 8/16/32/64-bit) with VIPS
backend, linear color-space processing, automatic EXIF orientation
handling, and alpha channel flattening.
