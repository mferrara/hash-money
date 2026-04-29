# Hash Money 💰

[![Latest Version on Packagist](https://img.shields.io/packagist/v/legitphp/hash-money.svg?style=flat-square)](https://packagist.org/packages/legitphp/hash-money)
[![Tests](https://img.shields.io/github/actions/workflow/status/mferrara/hash/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mferrara/hash/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/legitphp/hash-money.svg?style=flat-square)](https://packagist.org/packages/legitphp/hash-money)
[![PHP Version](https://img.shields.io/packagist/php-v/legitphp/hash-money?style=flat-square)](https://packagist.org/packages/legitphp/hash-money)

**Cache rules everything around me.**

Onions? Eggs? What do you like with your hash?

# Hash Money

We're serving up a performance-oriented and opinionated collection of similarity hashing algorithms for PHP. Whether you're comparing images, finding duplicates, or measuring how alike things are - we got you covered. We're riding dirty with [php-vips](https://github.com/php-vips/php-vips) for maximum speed. Get your FFI poppin'.

## Features

- 🚀 **Multiple Algorithms**: Perceptual (pHash), Difference (dHash), Color Histogram, Mashed, Block Mean, and PDQ hashes
- 🧬 **Composite Hashes**: Concatenate several algorithms into a wider multi-view fingerprint (e.g. 256-bit `pHash + dHash + ColorHistogram + BlockMean`)
- 🔎 **LSH + MIH helpers**: Split hashes into band / chunk keys for indexed similarity search at scale
- 🔒 **Type Safety**: Value objects ensure you can't compare incompatible hashes
- 🎯 **Configurable Bit Sizes**: 8 / 16 / 32 / 64-bit integer hashes, plus wider 128 / 256-bit hashes via `HashValue::fromBytes()`
- ⚡ **High Performance**: Optimized VIPS operations for speed
- 🛠️ **Clean API**: Simple static methods with full IDE support
- 🧩 **Extensible**: Strategy pattern makes adding new algorithms easy

## Algorithms

### Perceptual Hash (pHash)
DCT-based algorithm that's robust to scaling, aspect ratio changes, and minor color variations. Best for finding near-duplicate images.

- Uses Discrete Cosine Transform (DCT)
- More computationally intensive but highly accurate
- Excellent for matching images with color/brightness variations
- Based on the work from [VincentChalnot/PerceptualHash](https://github.com/VincentChalnot/perceptual-hash)

### Difference Hash (dHash)
Gradient-based algorithm that's faster than pHash and good at detecting similar images. It works by comparing adjacent pixels to encode the image structure.

- Analyzes gradient changes between adjacent pixels
- Faster computation than pHash
- Good for detecting cropped or slightly modified images
- More sensitive to rotation than pHash

### Color Histogram Hash
Color distribution-based algorithm that captures global color patterns in images. Particularly effective for finding images with similar color palettes.

- Uses HSV color space for robustness to illumination changes
- Quantizes colors into bins (8×4×4 by default)
- Excellent for detecting color-shifted or filtered variants
- Complements spatial hashes by focusing on color information
- **Enhanced bit distribution**: Now uses all 64 bits effectively with proper mixing
- **Improved uniqueness**: Fixed algorithm provides much better hash diversity

### MashedHash 🥔
A comprehensive image fingerprint that "mashes" together multiple image characteristics into a single 64-bit hash. This algorithm analyzes 11 different aspects of an image to create a rich signature that captures both content and style.

**Bit Layout (64 bits total):**
- **Bits 0-3**: Colorfulness level (0-15) - Detects grayscale vs vibrant images
- **Bits 4-7**: Edge density (0-15) - Measures detail and texture complexity
- **Bits 8-11**: Entropy/complexity (0-15) - Identifies simple vs complex compositions
- **Bits 12-14**: Aspect ratio class (0-7) - Captures image orientation and format
- **Bit 15**: Border flag - Detects images with uniform borders (common in social media)
- **Bits 16-31**: Color distribution (16 bits) - Analyzes RGB channel characteristics
- **Bits 32-39**: Spatial color layout (8 bits) - Tracks dominant colors by quadrant
- **Bits 40-47**: Brightness pattern (8 bits) - Encodes luminance distribution
- **Bits 48-55**: Texture features (8 bits) - Captures directional patterns
- **Bits 56-59**: Dominant color count (0-15) - Estimates color palette size
- **Bits 60-63**: Special indicators (4 bits) - Flags for text, uniform regions, etc.

**Why use MashedHash?**
- **Rich metadata**: Unlike single-feature hashes, it captures multiple image properties
- **Versatile matching**: Can identify similar images even with different modifications
- **Social media ready**: Detects common edits like borders, filters, and crops
- **Fast comparison**: Despite encoding 11 features, it's still just a 64-bit integer
- **Complementary**: Works best when combined with pHash or dHash for robust matching

**Gray coding note:** Ordinal integer fields (colorfulness, edge density,
entropy, aspect ratio, RGB channel levels, brightness, texture, dominant
colors) are stored in reflected-binary Gray code so adjacent quantization
levels differ by exactly one bit. Reading raw bit fields directly sees
the Gray-coded representation; use `MashedHash::decode($hash)` to read
semantic values.

### Block Mean Hash
Spatial-domain fingerprint: resize the image to a √bits × √bits grayscale
grid, compute the mean luminance of the whole grid, then set one bit per
cell based on whether that cell is brighter than the overall mean.

- Supports 8 / 16 / 32 / 64 / 128 / 256-bit output
- Retains "where the bright/dark regions live" through luminance changes and JPEG re-encoding
- Statistically independent from pHash (frequency-domain) and dHash (gradient-domain) — ideal as a 4th chunk in a composite hash

### PDQ Hash
Industrial-scale 256-bit perceptual hash from
[Meta ThreatExchange](https://github.com/facebook/ThreatExchange/tree/main/pdq).
Closely related to pHash but designed for billions-scale matching:

- **Larger output** — 256 bits vs. pHash's 64. Far lower birthday-collision
  rate at scale; you can store millions of hashes without the false-positive
  drift you'd see at 64 bits.
- **Quality metric** — alongside the hash, PDQ reports a gradient-derived
  reliability score in [0, 100]. Meta recommends discarding hashes with
  quality below 50 (uniform/blurry images, where the median threshold isn't
  meaningful). Accessible via `PdqHash::quality($hash)` or
  `$hash->getMetadata('pdq_quality')`.
- **Eight dihedral hashes** — `PdqHash::hashesFromFile()` returns all
  rotation and flip variants in a single pass.
- **Recommended match threshold** — Hamming distance ≤ 31 of 256 bits.
- **Pipeline** — Rec. 601 luminance → two-pass Jarosz 1-D box filter (a
  tent approximation, Wojciech Jarosz, "Fast Image Convolutions",
  SIGGRAPH 2001) → 64×64 decimation → 16×16 DCT-II → median threshold.

This is an independent port of Meta's BSD-3-Clause reference. See
[LICENSE.md](LICENSE.md) for the third-party notice.

### Composite Hash
Concatenates several algorithms' 64-bit output into one wider fingerprint
with independent signal types in each chunk. The default composition is
the 256-bit "quartet" `pHash + dHash + ColorHistogram + BlockMean`:

```php
use LegitPHP\HashMoney\CompositeHash;

$composite = CompositeHash::default();
$hash = $composite->hashFromFile('/path/to/image.jpg');

echo $hash->getBits();     // 256
echo $hash->toHex();       // 64 hex chars
echo $hash->getAlgorithm();// "composite:perceptual+dhash+color-histogram+block-mean"
```

Chunk boundaries are preserved in the `HashValue`'s `chunks` metadata so
LSH helpers can band each chunk separately. See the
[LSH helpers](#scaling-to-large-datasets-lsh--mih) section below.

## Requirements

- PHP 8.3+ (64-bit required)
- [libvips](https://github.com/libvips/libvips) 8.7+
- [php-vips](https://github.com/libvips/php-vips) extension 2.5+

## Installation

You can install the package via composer:

```bash
composer require legitphp/hash-money
```

### Installing libvips

**Ubuntu/Debian:**
```bash
sudo apt install libvips-dev
```

**macOS:**
```bash
brew install vips
```

**Then install the PHP extension:**
```bash
pecl install vips
```

### Versioning

Hash Money follows [semantic versioning](https://semver.org/). Pin with
`"legitphp/hash-money": "^1.1"` to take patch releases and additive
minor releases automatically while opting in to majors deliberately.

If you're rolling out at scale (~100K+ images), also read
[`PRE_BATCH_REVIEW.md`](PRE_BATCH_REVIEW.md) before generating a
production dataset — the guide covers version prerequisites, timing
calibration, distribution/collision checks, and a go/no-go checklist.

## Usage

### Basic Usage

```php
use LegitPHP\HashMoney\PerceptualHash;
use LegitPHP\HashMoney\DHash;
use LegitPHP\HashMoney\ColorHistogramHash;
use LegitPHP\HashMoney\MashedHash;
use LegitPHP\HashMoney\PdqHash;

// Generate a perceptual hash
$pHash = PerceptualHash::hashFromFile('/path/to/image.jpg');
echo $pHash->toHex(); // e.g., "f0e1d2c3b4a59687"

// Generate a difference hash  
$dHash = DHash::hashFromFile('/path/to/image.jpg');
echo $dHash->toBinary(); // e.g., "1010101100110011..."

// Generate a color histogram hash
$colorHash = ColorHistogramHash::hashFromFile('/path/to/image.jpg');
echo $colorHash->toHex(); // e.g., "a1b2c3d4e5f6g7h8"

// Generate a MashedHash (comprehensive fingerprint)
$mHash = MashedHash::hashFromFile('/path/to/image.jpg');
echo $mHash->toHex(); // e.g., "1cf0e2a3b4596d87"

// Generate a PDQ hash (256-bit, with quality score)
$pdqHash = PdqHash::hashFromFile('/path/to/image.jpg');
echo $pdqHash->toHex(); // 64 hex chars
echo PdqHash::quality($pdqHash); // 0-100; Meta recommends discarding < 50

// Compare images
$hash1 = PerceptualHash::hashFromFile('/path/to/image1.jpg');
$hash2 = PerceptualHash::hashFromFile('/path/to/image2.jpg');
$distance = PerceptualHash::distance($hash1, $hash2);

if ($distance <= 10) {
    echo "Images are very similar!";
}
```

### Configurable Hash Sizes

```php
// Generate different sized hashes for different use cases
$hash64 = PerceptualHash::hashFromFile($path, 64); // Default, most accurate
$hash32 = PerceptualHash::hashFromFile($path, 32); // Balanced speed/accuracy
$hash16 = PerceptualHash::hashFromFile($path, 16); // Fast, basic matching
$hash8 = PerceptualHash::hashFromFile($path, 8);   // Extremely fast, rough matching

// Same options available for DHash
$dHash = DHash::hashFromFile($path, 32);
```

Smaller hash sizes are faster to compute and compare but may produce more false positives. Choose based on your needs:
- **64-bit**: Best for production use with large image databases
- **32-bit**: Good balance for most applications
- **16-bit**: Suitable for quick similarity checks
- **8-bit**: Only for rough categorization

### Type Safety

```php
// The API returns HashValue objects with type safety
$pHash = PerceptualHash::hashFromFile('image.jpg');
$dHash = DHash::hashFromFile('image.jpg');

// This will throw an exception - can't compare different algorithms!
try {
    PerceptualHash::distance($pHash, $dHash);
} catch (InvalidArgumentException $e) {
    echo "Cannot compare hashes from different algorithms";
}

// Get hash details
echo $pHash->getValue();     // Raw integer value
echo $pHash->getBits();      // 64
echo $pHash->getAlgorithm(); // "perceptual"
echo $pHash->toHex();        // Hexadecimal representation

// Create typed HashValue instances from various sources
$fromHex = HashValue::fromHex('a1b2c3d4', 32, 'perceptual');
$fromBinary = HashValue::fromBinary('10101010', 'dhash');
$fromBase64 = HashValue::fromBase64('EjRWeQ==', 32, 'color-histogram');

// Type safety is maintained across all operations
if ($pHash->isCompatibleWith($fromHex)) {
    $distance = $pHash->hammingDistance($fromHex);
}
```

### Configure VIPS

```php
// Configure VIPS settings for performance tuning
PerceptualHash::configure([
    'cores' => 4,
    'maxCacheSize' => 100,  // Max cached operations
    'maxMemory' => 256,     // 256MB cache memory
]);

// Each strategy maintains its own configuration
DHash::configure([
    'cores' => 8,
]);

// Configure Color Histogram Hash quantization
ColorHistogramHash::configureQuantization(16, 8, 8); // 16 hue bins, 8 saturation bins, 8 value bins

// MashedHash uses standard VIPS configuration
MashedHash::configure([
    'cores' => 4,
]);
```

### Distance Interpretation

The Hamming distance between two hashes indicates how similar the images are.

## Advanced Usage

### Working with Hash Values

The `HashValue` class provides a rich API for working with hash results:

```php
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\PerceptualHash;

$hash = PerceptualHash::hashFromFile('image.jpg');

// Basic information
$value = $hash->getValue();        // Raw integer value
$hex = $hash->toHex();            // Hex representation (e.g., "a1b2c3d4e5f6")
$binary = $hash->toBinary();      // Binary string (e.g., "101010110010...")
$bits = $hash->getBits();         // Size in bits (8, 16, 32, or 64)
$algorithm = $hash->getAlgorithm(); // Algorithm name

// Additional representations
$base64 = $hash->toBase64();           // Base64 encoding
$urlSafe = $hash->toUrlSafeBase64();  // URL-safe base64
$string = (string) $hash;             // Converts to hex

// Direct comparison
if ($hash1->equals($hash2)) {
    echo "Exact match!";
}

// Calculate distance directly
$distance = $hash1->hammingDistance($hash2);
echo "Images differ by $distance bits";
```

### Batch Processing

```php
// Process multiple images efficiently
$images = glob('/path/to/images/*.jpg');
$hashes = [];

foreach ($images as $image) {
    $hashes[$image] = DHash::hashFromFile($image, 32);
}

// Find similar images
foreach ($hashes as $path1 => $hash1) {
    foreach ($hashes as $path2 => $hash2) {
        if ($path1 !== $path2 && DHash::distance($hash1, $hash2) < 10) {
            echo "$path1 is similar to $path2\n";
        }
    }
}
```

### Performance Optimization

```php
// Configure for maximum performance
PerceptualHash::configure([
    'cores' => 8,              // Use 8 CPU cores
    'maxMemory' => 512,        // 512MB cache memory
    'disableCache' => false,   // Enable caching
]);

// Each strategy has independent configuration
DHash::configure([
    'cores' => 4,
    'maxMemory' => 256,        // 256MB cache memory
]);

// Process from memory to avoid disk I/O
$imageData = file_get_contents('large-image.jpg');
$hash = PerceptualHash::hashFromString($imageData);
```

### Enhanced HashValue Features

The `HashValue` class includes advanced features for flexible hash manipulation and storage:

#### Factory Methods and Serialization

Create HashValue objects from various formats:

```php
use LegitPHP\HashMoney\HashValue;

// Create from hexadecimal string
$fromHex = HashValue::fromHex('a1b2c3d4e5f6', 64, 'perceptual');
$fromHex = HashValue::fromHex('0xA1B2C3D4E5F6', 64, 'perceptual'); // With prefix

// Create from binary string
$fromBinary = HashValue::fromBinary('10101010', 'dhash'); // Auto-detects 8-bit

// Create from base64
$fromBase64 = HashValue::fromBase64('EjRWeJCrze8=', 64, 'perceptual');

// Serialize to different formats
$hash = PerceptualHash::hashFromFile('image.jpg');
$json = json_encode($hash); // Implements JsonSerializable
$array = $hash->toArray();  // Convert to array

// Restore from serialized data
$decoded = json_decode($json, true);
$restored = HashValue::fromArray($decoded);
```

#### Metadata Support

Attach metadata to hash values for richer data management:

```php
// Create hash with metadata
$hash = PerceptualHash::hashFromFile('photo.jpg');
$hashWithMeta = $hash->withMetadata([
    'filename' => 'photo.jpg',
    'timestamp' => time(),
    'source' => 'user_upload',
    'quality_score' => 0.95
]);

// Access metadata
$allMeta = $hashWithMeta->getMetadata();
$filename = $hashWithMeta->getMetadata('filename');

// Metadata persists through serialization
$json = json_encode($hashWithMeta->toArray());
$restored = HashValue::fromArray(json_decode($json, true));
echo $restored->getMetadata('filename'); // 'photo.jpg'
```

#### Bitwise Analysis

Examine hash properties at the bit level:

```php
$hash = DHash::hashFromFile('image.jpg', 64);

// Check individual bits
for ($i = 0; $i < 8; $i++) {
    if ($hash->getBit($i)) {
        echo "Bit $i is set\n";
    }
}

// Count set bits (useful for hash analysis)
$setBits = $hash->countSetBits();
$density = $setBits / $hash->getBits(); // Bit density ratio

// Get normalized value (0.0 to 1.0)
$normalized = $hash->normalized();
```

#### Advanced Comparisons

Built-in methods for sophisticated hash comparison:

```php
$hash1 = PerceptualHash::hashFromFile('original.jpg');
$hash2 = PerceptualHash::hashFromFile('modified.jpg');

// Direct Hamming distance calculation
$distance = $hash1->hammingDistance($hash2);

// Calculate similarity percentage
$maxDistance = $hash1->getBits();
$similarity = (1 - ($distance / $maxDistance)) * 100;
echo "Images are {$similarity}% similar";

// Use normalized values for threshold comparisons
if ($hash1->normalized() > 0.5 && $hash2->normalized() > 0.5) {
    echo "Both images have high bit density";
}
```

### Scaling to Large Datasets (LSH + MIH)

Once you have millions of hashes in a database, naïve
`BIT_COUNT(a ^ b) <= k` scans become prohibitively slow. Two helpers are
provided for building indexed similarity search:

#### Multi-Index Hashing (MIH) — best for 64-bit hashes, small thresholds

For Hamming threshold `k` bits on a 64-bit hash, split the hash into
`m > k` equal chunks. Pigeonhole: any two hashes within `k` bits must
have at least one chunk matching exactly. Index each chunk as its own
`BIGINT` column and query with `m` equality lookups, union the results,
then verify the full Hamming distance on the small candidate set:

```php
use LegitPHP\HashMoney\MultiIndexHash;

$hash = PerceptualHash::hashFromFile('image.jpg');
$chunks = MultiIndexHash::chunks($hash, 8); // 8 × 8-bit chunks, safe for k ≤ 7

// Persist chunks as BIGINT columns (mih_0..mih_7), each with its own index
// ... then query:
//   SELECT * FROM hashes
//   WHERE mih_0=? OR mih_1=? OR mih_2=? ... OR mih_7=?
// Union is your candidate set; verify with hammingDistance() in PHP.
```

MIH is typically 2–3 orders of magnitude faster than full-table
`BIT_COUNT` scans for `k ≤ 8` on 64-bit hashes. For larger thresholds or
wider hashes, use LSH banding instead.

#### LSH Banding — best for composite / wider hashes

For a wide hash, split into `B` bands of `R` bits each. Each band's
bytes become a bucket key; two hashes are "candidates" when they share
any bucket key. `Lsh::bandsByChunk()` is composite-aware — it sub-bands
each semantic chunk independently so candidates are "matched on at least
one of structure, edges, color, or layout":

```php
use LegitPHP\HashMoney\CompositeHash;
use LegitPHP\HashMoney\Lsh;

$composite = CompositeHash::default();
$hash = $composite->hashFromFile('image.jpg');

// 4 bands per chunk → 16 total bucket keys for the 256-bit quartet.
$bucketKeys = Lsh::bandsByChunk($hash, bandsPerChunk: 4);
// [
//   'perceptual'       => [k1, k2, k3, k4],
//   'dhash'            => [k5, k6, k7, k8],
//   'color-histogram'  => [k9, k10, k11, k12],
//   'block-mean'       => [k13, k14, k15, k16],
// ]

// Flat banding (no chunk awareness):
$flatKeys = Lsh::bands($hash, bandCount: 16);
```

Tune bands vs. chunks against your dataset — more bands raises recall
but inflates the candidate set. A reasonable starting point for a
256-bit composite is 16 bands (B=16, R=16 → 65,536 buckets per band).

Candidate-filtering is usually done in the database; see
[`docs/LARAVEL_BRIDGE.md`](docs/LARAVEL_BRIDGE.md) for a proposed
Laravel package that wires all of this (Eloquent cast, migrations,
scopes, pluggable MySQL-chunked / MySQL-banded drivers) on top of this
library.

### Real-World Examples

#### Database Storage Pattern

Store and retrieve hashes efficiently:

```php
// Storing in database
$hash = MashedHash::hashFromFile('product-image.jpg');
$data = [
    'image_id' => 12345,
    'hash_value' => $hash->getValue(),      // Store as BIGINT
    'hash_hex' => $hash->toHex(),          // Store as CHAR(16) for 64-bit
    'algorithm' => $hash->getAlgorithm(),   // Store algorithm type
    'metadata' => json_encode([
        'original_name' => 'product-image.jpg',
        'processed_at' => date('Y-m-d H:i:s')
    ])
];

// Retrieving from database
$row = $db->fetchRow("SELECT * FROM image_hashes WHERE image_id = ?", [12345]);
$hash = new HashValue(
    $row['hash_value'],
    64,
    $row['algorithm'],
    json_decode($row['metadata'], true)
);

// Or use hex value
$hash = HashValue::fromHex($row['hash_hex'], 64, $row['algorithm']);
```

#### API Integration

Send and receive hashes via APIs:

```php
// Sending hash data
$hash = ColorHistogramHash::hashFromFile('image.jpg');
$apiPayload = [
    'image_hash' => $hash->toUrlSafeBase64(), // URL-safe for GET requests
    'algorithm' => $hash->getAlgorithm(),
    'bits' => $hash->getBits()
];

$response = $httpClient->post('/api/check-duplicate', [
    'json' => $apiPayload
]);

// Receiving and reconstructing
$data = json_decode($response->getBody(), true);
$receivedHash = HashValue::fromBase64(
    $data['image_hash'],
    $data['bits'],
    $data['algorithm']
);
```

#### Duplicate Detection System

Build a complete duplicate detection workflow:

```php
class ImageDuplicateDetector 
{
    private array $hashDatabase = [];
    
    public function addImage(string $path): void 
    {
        // Generate multiple hash types for robust matching
        $pHash = PerceptualHash::hashFromFile($path);
        $dHash = DHash::hashFromFile($path);
        $mHash = MashedHash::hashFromFile($path);
        
        // Store with metadata
        $this->hashDatabase[$path] = [
            'perceptual' => $pHash->withMetadata(['path' => $path]),
            'dhash' => $dHash->withMetadata(['path' => $path]),
            'mashed' => $mHash->withMetadata(['path' => $path]),
            'added_at' => time()
        ];
    }
    
    public function findDuplicates(string $imagePath, int $threshold = 10): array 
    {
        $candidates = [];
        $testHashes = [
            'perceptual' => PerceptualHash::hashFromFile($imagePath),
            'dhash' => DHash::hashFromFile($imagePath),
            'mashed' => MashedHash::hashFromFile($imagePath)
        ];
        
        foreach ($this->hashDatabase as $storedPath => $storedHashes) {
            $scores = [
                'perceptual' => $testHashes['perceptual']->hammingDistance($storedHashes['perceptual']),
                'dhash' => $testHashes['dhash']->hammingDistance($storedHashes['dhash']),
                'mashed' => $testHashes['mashed']->hammingDistance($storedHashes['mashed'])
            ];
            
            // Weighted scoring
            $totalScore = ($scores['perceptual'] * 2 + $scores['dhash'] + $scores['mashed']) / 4;
            
            if ($totalScore <= $threshold) {
                $candidates[] = [
                    'path' => $storedPath,
                    'score' => $totalScore,
                    'individual_scores' => $scores
                ];
            }
        }
        
        // Sort by similarity
        usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
        
        return $candidates;
    }
}
```


## Example Scripts and Benchmarks

### Hash Generation Example

The package includes a comprehensive example script for testing hash generation:

```bash
# Test all algorithms with 64-bit hashes
php example.php

# Test specific algorithm and bit size
php example.php perceptual 32
php example.php dhash 16
php example.php color 64
php example.php all 64
```


## Testing

Run the test suite using Pest:

```bash
composer test
```

For code formatting:

```bash
composer format
```

## Performance Considerations

- **DHash** is typically 2-3x faster than **Perceptual Hash**
- **Color Histogram Hash** is comparable to DHash in speed
- **MashedHash** is slightly slower but provides the richest feature set
- **PDQ** is the slowest of the algorithms (~150–200 ms/image at the
  default 512×512 working size) — the Jarosz tent filter and 16×16 DCT
  run in pure PHP, not libvips. Tune via
  `PdqHash::configure(['workingSize' => 256])` to halve the cost at the
  expense of less Jarosz blur. Worth the headroom for production
  near-dup detection where 256-bit signal and quality filtering matter.
- Smaller bit sizes compute faster but may reduce accuracy
- VIPS caching significantly improves performance for batch operations
- The package automatically detects CPU cores for optimal concurrency

## Rolling Out at Scale

Before generating hashes for a large production dataset (~100K images or more), run the calibration and validation steps in [`PRE_BATCH_REVIEW.md`](PRE_BATCH_REVIEW.md) **inside your consumer project**. The guide covers version prerequisites, timing extrapolation, hash-distribution sanity checks, known-pair validation, failure-mode probes, database/query-plan review, and a go/no-go checklist. It's intended to be executable by either a human operator or an AI coding agent working in the consumer repo, and produces a single report that justifies the decision to run the full batch.

## Use Cases

- **Duplicate Detection**: Find exact or near-duplicate images in large collections
- **Content Moderation**: Detect previously flagged images even after modifications
- **Image Organization**: Group similar images automatically
- **Copyright Protection**: Identify unauthorized use of images
- **Quality Control**: Detect corrupted or incorrectly processed images

## Choosing the Right Hash

| Hash Type | Best For | Speed | Key Features |
|-----------|----------|-------|-------------|
| **pHash** | Near-duplicate detection, scaled/compressed variants | Medium | Robust to compression, scaling, minor edits |
| **dHash** | Quick similarity checks, cropped images | Fast | Good for crops, sensitive to rotation |
| **ColorHistogram** | Color-based matching, filter detection | Fast | Catches recolored/filtered versions |
| **MashedHash** | Reducing false positives (as augmenting signal) | Medium | 11 Gray-coded features, read via `decode()` |
| **BlockMean** | Spatial layout fingerprint | Fast | Orthogonal to pHash/dHash, 8/16/…/256-bit |
| **PDQ** | Large-scale near-duplicate detection (256-bit) | Slower | 256-bit, gradient-based **quality metric**, 8 dihedral variants |
| **Composite** | LSH-friendly multi-view fingerprint | Medium | Chunks carry independent signal types |

### Recommended Combinations

**For social media images:**
```php
// Use MashedHash + pHash for best results
$mHash = MashedHash::hashFromFile($image);
$pHash = PerceptualHash::hashFromFile($image);

if (MashedHash::distance($mHash1, $mHash2) < 20 && 
    PerceptualHash::distance($pHash1, $pHash2) < 12) {
    // High confidence match
}
```

**For copyright detection:**
```php
// Use all three spatial/color hashes
$pHash = PerceptualHash::hashFromFile($image);
$dHash = DHash::hashFromFile($image);
$colorHash = ColorHistogramHash::hashFromFile($image);
```

**For large-scale similarity search with LSH:**
```php
// One 256-bit composite instead of four separate hashes — lets you
// band-index each chunk and do indexed candidate generation in the DB.
$composite = CompositeHash::default();
$hash = $composite->hashFromFile($image);
$bucketKeys = Lsh::bandsByChunk($hash, bandsPerChunk: 4);
```

**For industrial-scale near-duplicate detection with PDQ:**
```php
use LegitPHP\HashMoney\PdqHash;
use LegitPHP\HashMoney\Strategies\PdqHashStrategy;

$hash = PdqHash::hashFromFile($image);
$quality = PdqHash::quality($hash);

// Meta's recommended thresholds
if ($quality < PdqHashStrategy::RECOMMENDED_QUALITY_THRESHOLD) {
    // Image hashes unreliably (uniform / blurry) — skip or fall back to MashedHash.
    return null;
}

if (PdqHash::distance($hash, $candidate) <= PdqHashStrategy::RECOMMENDED_DISTANCE_THRESHOLD) {
    // Near-duplicate (≤ 31 of 256 bits differ).
}

// Catch rotated / flipped duplicates by hashing all eight dihedral variants once
// and matching the candidate's "orig" hash against any of them.
$variants = PdqHash::hashesFromFile($image);
foreach ($variants['hashes'] as $name => $variantHash) {
    if (PdqHash::distance($variantHash, $candidate) <= 31) {
        // Match under transform "$name" (orig / r090 / r180 / r270 / flpx / flpy / flpp / flpm).
    }
}
```

PDQ produces a 256-bit `HashValue` directly — no `getValue()`-style int
accessor; use `toHex()`, `getBytes()`, or `toBase64()` for storage. Wide
LSH banding works exactly like for `CompositeHash` outputs: pass the
`HashValue` to `Lsh::bands($hash, $bandCount)`.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Mike Ferrara](https://github.com/mferrara)
- [Vincent Chalnot](https://github.com/VincentChalnot)
- [Jens Segers](https://github.com/jenssegers)
- [Anatoly Pashin](https://github.com/b1rdex)
- [jcupitt](https://github.com/jcupitt)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Acknowledgments

Special thanks to the authors and contributors of the libraries that made this package possible, particularly the VIPS team for their incredible image processing library.
