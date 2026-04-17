# Pre-Batch Review Guide

This document describes a structured review to run **in the consumer project** (the one that depends on `legitphp/hash-money`) before generating hashes for a large dataset — e.g., tens of millions of images.

It exists because a production batch at that scale is expensive to re-run: a bad config, a pathological hash distribution, or an unseen failure mode can silently poison the dataset. Cheaper to spend an afternoon on calibration than to discover a problem at row 40M.

The guide is intentionally agent-agnostic: it describes *what* to check and *what to report*, not *who* does it. Human or AI operator, the output should look the same.

---

## When to run this

Run this review before:

- First-time rollout of any hash algorithm to production.
- A backfill run covering more than ~100K images.
- Bumping `legitphp/hash-money` across a release that changes hash computation (consult the changelog; in particular, `ColorHistogramHash` changed substantially in `cd8727b`).

---

## Prerequisites

1. **Upgrade `legitphp/hash-money` to current `dev-main` (or latest tag).**
   The commit pinned as `89e86ae` carries a VIPS memory-config bug: the `maxMemory` default of `256` is passed straight to `Config::cacheSetMaxMem()`, which takes *bytes*, not MB. That means the VIPS cache is effectively 256 bytes — no caching — which cripples batch throughput. PR #15 (merged into `main` on 2026-04-17) fixes this. After `composer update`, verify by grepping `vendor/legitphp/hash-money/src/Strategies/AbstractHashStrategy.php` for `1024 * 1024` inside `initVips()`. If it's not there, you're on a stale version and this review is premature.

2. **Know your consumer's invocation pattern.**
   The pre-PR-#13 grayscale-state bug only affects direct `hashFromVipsImage()` callers. If the consumer exclusively uses `hashFromString()` / `hashFromFile()` (e.g., via a `generateFromString` wrapper on a model), stored pre-upgrade ColorHistogramHash values are bit-identical to post-upgrade values and **do not need regeneration**. Confirm this by grepping the consumer for `hashFromVipsImage`.

3. **Pick a representative sample.**
   Target ~10K images that reflect production: file sizes, formats (JPEG/PNG/WebP/GIF), orientations, color *and* grayscale, any weird-but-real inputs your system actually receives. If you can't assemble 10K, 1K is acceptable for a first pass — but extrapolation error grows.

4. **Know your downstream query shape.**
   Exact lookups? Hamming-distance range queries? Clustering? The answer determines what you're looking for in Phase 3 (distribution) and Phase 6 (query plan).

---

## Phase 1 — Smoke test

**Goal:** Confirm every hash type the consumer generates runs end-to-end on a small slice without crashing.

**Steps:**
- For each of the hash types the consumer actually writes (`perceptualHash`, `improvedPerceptualHash`, `dHash`, `mashedHash`, `colorHistogramHash`, or whichever subset applies), run the generation path against ~50 images.
- Record any uncaught exceptions along with the full chain (`$e->getPrevious()` now carries the underlying VIPS error after PR #14).

**Exit criterion:** No unhandled exceptions on well-formed JPEG / PNG / WebP.

---

## Phase 2 — Timing calibration

**Goal:** Estimate wall time and resource cost for the full batch so the operational plan survives contact with reality.

**Measurements per hash type, over the 10K sample:**
- Mean and p95 per-image hash time (measure individual calls, not batch totals — worker scaling then predicts cleanly).
- Memory high-water mark per worker process.
- I/O pattern: if images come from remote storage, include fetch time separately from hash time.

**Report format — one row per hash type:**

| hash_type | mean_ms | p95_ms | max_rss_mb | est_hours_80M_at_N_workers |
|-----------|---------|--------|------------|----------------------------|

**Decision point:** If the extrapolation exceeds your operational window, reconsider parallelism, drop the most expensive hash type, or shard the work. Don't start the batch until the extrapolation fits.

---

## Phase 3 — Distribution & collision check

**Goal:** Catch pathological hash clustering before it contaminates the dataset.

**Checks per hash type over the 10K sample:**

- **Set-bit histogram.** For a healthy 64-bit hash over natural images, bits-set-per-hash should cluster near 32 with a roughly bell-shaped distribution. Large tails below ~8 or above ~56, or bimodal clustering, indicate either a degenerate input class or a bug in the algorithm for this dataset.
- **Unique value count.** In a random sample of 10K natural images with no known duplicates, the unique hash count should be very close to 10K. Material duplication is a red flag — especially for `ColorHistogramHash`, which had a 96% collision bug fixed in `cd8727b`. Confirm the installed version includes that commit if collisions look high.
- **Grayscale branch occupancy.** `ColorHistogramHash` sets bit 63 for grayscale inputs. Report the grayscale / color split in the sample and confirm grayscale inputs actually land in the grayscale branch (bit 63 = 1). A consumer unknowingly feeding CMYK or indexed-palette images may see surprises here.

---

## Phase 4 — Known-pair validation

**Goal:** Confirm that Hamming distance behaves as expected — duplicates cluster tight, unrelated images stay far apart, and the gap is wide enough to threshold.

**Construct a labeled set:**
- 20–50 **true-duplicate pairs** (same image resaved / re-encoded / slightly resized).
- 20–50 **near-duplicate pairs** (different crop of same scene, color-adjusted, watermarked).
- 20–50 **different-image pairs** (unrelated content).

**For each hash type, report:**

| hash_type | dup_min | dup_mean | dup_max | near_min | near_mean | near_max | diff_min | diff_mean |
|-----------|---------|----------|---------|----------|-----------|----------|----------|-----------|

**Interpretation:**
- The distance between `dup_max` (and ideally `near_max`) and `diff_min` is your threshold headroom.
- If `near_max` overlaps `diff_min`, that hash type cannot cleanly separate near-duplicates from unrelated images on *this* dataset. Either tighten the definition of "near-duplicate" in your use case, stack multiple hash types (see README's "Recommended Combinations"), or drop that hash type.

---

## Phase 5 — Failure-mode probe

**Goal:** Surface the long tail of inputs that will inevitably show up across 80M images.

**Hash each of these inputs with every active hash type. Record pass / fail and, on failure, the exception chain:**

- Truncated / corrupt JPEG.
- 1×1 pixel image.
- Very large image (e.g., 20000×20000; adjust to your actual upper bound).
- Animated GIF (confirm behavior: first frame? error?).
- CMYK JPEG.
- Indexed-palette PNG.
- 16-bit depth PNG.
- Image with an embedded ICC profile that's missing / broken.
- Pure grayscale (1-band) image — run against every hash type.
- An EXIF-rotated image — confirm orientation is handled consistently or is explicitly ignored.

**Blocker:** Any input that *silently* returns a wrong / null hash without raising an exception. Anything that raises but drops the underlying VIPS message in the chain is a quality-of-life bug worth filing upstream.

---

## Phase 6 — Database and query plan

**Goal:** Don't discover at row 50M that the schema doesn't query efficiently.

**Answer before the batch starts:**

- **Schema shape.** One `media_hashes` row per `(media_id, hash_type)` pair, or one column per hash type on the media table? Both work; the query path differs.
- **Exact-match index.** Is `hash_int` (or equivalent) indexed? Confirm.
- **Hamming-distance queries.** If downstream needs "find me everything within Hamming distance k of this hash":
  - PHP-side brute force is fine up to ~100K rows.
  - At 80M, you need either precomputed `popcount(hash XOR candidate)` via a DB extension (Postgres `bit_count`, MySQL `BIT_COUNT`), or a bit-vector index (PGVector, Redis Vector Sets, etc.).
  - Decide **now**, before schema freeze.
- **Retry / dead-letter handling.** If Phase 5 shows a failure rate of even 0.01%, that's 8,000 failed rows at 80M scale. Plan how they're recorded and reprocessed.

---

## Phase 7 — Consumer integration review

**Goal:** Sanity-check the consumer's wiring of hash-money before scale amplifies any mistake.

Quick read-through of the consumer's hash-generation code:

- **Strategy instance reuse.** Does the consumer instantiate a fresh strategy per image, or reuse one per worker? Either is fine with the post-PR-#13 code; with pre-PR-#13 code reusing a `ColorHistogramHashStrategy` across interleaved grayscale/color images via direct `hashFromVipsImage` calls would corrupt hashes. (This is the exact bug PR #13 fixed.) Once on the upgraded version, this concern is moot — but it's worth confirming which code path the consumer uses.
- **VIPS config application.** Is the consumer calling `PerceptualHash::configure([...])` and the equivalents for the other algorithms somewhere predictable (boot, worker init)? After PR #15, each strategy holds its own config — so calling `configure` on one no longer affects the others. If the consumer was relying on the old "configure once, affects all" behavior, that's now broken.
- **File content source.** The consumer's `generateHash` takes a `?string $fileContent`. Confirm that callers pass the raw bytes (not a path, not a resource). Mixing these up produces hashes of the wrong data without any error.

---

## Go / no-go checklist

Don't start the 80M batch until every item is checked:

- [ ] `legitphp/hash-money` upgraded to a version including PRs #13, #14, #15, #16 (merged to `main` on 2026-04-17).
- [ ] Phase 2 extrapolation fits the operational window at the planned worker count.
- [ ] Phase 3 set-bit distributions look natural for every active hash type.
- [ ] Phase 3 unique-value count is close to sample size (no severe collision cluster).
- [ ] Phase 4 shows usable Hamming-distance separation between duplicate and different-image classes for every active hash type.
- [ ] Phase 5 long-tail failure modes are either handled gracefully or explicitly accepted with a documented fallback.
- [ ] Phase 6 schema and query-path decisions are finalized; indexes exist.
- [ ] Phase 7 consumer wiring review surfaced no misuse.

If any box is unchecked, stop and resolve before scaling up.

---

## Deliverable format

The operator running this review should produce one report document containing:

1. **Versions pinned:** hash-money commit/version, libvips version, PHP version, consumer commit SHA.
2. **Sample characterization:** size, format breakdown, color/grayscale split, source.
3. **One section per phase** with the tables/outputs specified above.
4. **Go / no-go call** with the checklist filled in and any unchecked items annotated with a plan.

The report is the artifact that justifies the decision to run (or not run) the 80M batch. Keep it.
