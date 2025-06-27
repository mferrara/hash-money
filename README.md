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

- 🚀 **Multiple Algorithms**: Perceptual Hash (pHash), Difference Hash (dHash), and Color Histogram Hash
- 🔒 **Type Safety**: Value objects ensure you can't compare incompatible hashes
- 🎯 **Configurable Bit Sizes**: Support for 8, 16, 32, and 64-bit hashes
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

## Usage

### Basic Usage

```php
use LegitPHP\HashMoney\PerceptualHash;
use LegitPHP\HashMoney\DHash;
use LegitPHP\HashMoney\ColorHistogramHash;
use LegitPHP\HashMoney\MashedHash;

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
// The new API returns HashValue objects with type safety
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
```

### Configure VIPS

```php
// Configure VIPS settings for performance tuning
PerceptualHash::configure([
    'concurrency' => 4,
    'cache_max' => 100 * 1024 * 1024, // 100MB
]);

// DHash uses the same configuration
DHash::configure([
    'concurrency' => 8,
]);

// Configure Color Histogram Hash quantization
ColorHistogramHash::configureQuantization(16, 8, 8); // 16 hue bins, 8 saturation bins, 8 value bins

// MashedHash uses standard VIPS configuration
MashedHash::configure([
    'concurrency' => 4,
]);
```

### Distance Interpretation

The Hamming distance between two hashes indicates how similar the images are.

## Advanced Usage

### Working with Hash Values

```php
use LegitPHP\HashMoney\HashValue;

$hash = PerceptualHash::hashFromFile('image.jpg');

// Get hash information
$value = $hash->getValue();        // Raw integer value
$hex = $hash->toHex();            // Hex representation (e.g., "a1b2c3d4e5f6")
$binary = $hash->toBinary();      // Binary string (e.g., "101010110010...")
$bits = $hash->getBits();         // Size in bits (8, 16, 32, or 64)
$algorithm = $hash->getAlgorithm(); // Algorithm name ("perceptual" or "dhash")

// Compare hashes
if ($hash1->equals($hash2)) {
    echo "Exact match!";
}

if ($hash1->isCompatibleWith($hash2)) {
    $distance = PerceptualHash::distance($hash1, $hash2);
    echo "Distance: $distance";
}
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
    'concurrency' => 8,              // Use 8 CPU cores
    'cache_max' => 200 * 1024 * 1024, // 200MB cache
    'disable_cache' => false,         // Enable caching
]);

// Configure with different settings
DHash::configure([
    'concurrency' => 4,
    'cache_max' => 100 * 1024 * 1024, // 100MB cache
]);

// Process from memory to avoid disk I/O
$imageData = file_get_contents('large-image.jpg');
$hash = PerceptualHash::hashFromString($imageData);
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
- Smaller bit sizes compute faster but may reduce accuracy
- VIPS caching significantly improves performance for batch operations
- The package automatically detects CPU cores for optimal concurrency

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
| **MashedHash** | Comprehensive matching, reducing false positives | Medium | 11 features including borders, textures, layout |

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
