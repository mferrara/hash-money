# Hashes.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/legitphp/hash.svg?style=flat-square)](https://packagist.org/packages/legitphp/hash)
[![Tests](https://img.shields.io/github/actions/workflow/status/legitphp/hash/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/legitphp/hash/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/legitphp/hash.svg?style=flat-square)](https://packagist.org/packages/legitphp/hash)

Onions? Eggs? What do you like with your hash?

# Hash

We're aiming to provide a performance-oriented and opinionated collection of image hashing algorithms for PHP. We'll 
be leaning hard on [php-vips](https://github.com/php-vips/php-vips). Get your FFI poppin'.

## Perceptual Hashing

I lifted much of this directly from the [PerceptualHash](https://github.com/sidus/perceptual-hash) package, 
which is a great library for generating perceptual hashes of images.

Many of the performance improvements come from various optimizations I've come across in comments on issues and pull
requests by [jcupitt](https://github.com/jcupitt) and others, which I've tried to incorporate into this package.

## Installation

You can install the package via composer:

```bash
composer require legitphp/hash
```

## Usage

```php
use LegitPHP\Hash\PerceptualHash;

// Generate a perceptual hash from a file
$hash = PerceptualHash::hashFromFile('/path/to/image.jpg');

// Generate a perceptual hash from image data in memory
$imageData = file_get_contents('/path/to/image.jpg');
$hash = PerceptualHash::hashFromString($imageData);

// Compare two images using Hamming distance
$hash1 = PerceptualHash::hashFromFile('/path/to/image1.jpg');
$hash2 = PerceptualHash::hashFromFile('/path/to/image2.jpg');
$distance = PerceptualHash::distance($hash1, $hash2);

// Distance of 0-10 usually means images are very similar
// Distance of 10-20 means images are somewhat similar
// Distance > 20 means images are likely different
if ($distance <= 10) {
    echo "Images are very similar!";
}

// Configure VIPS settings (optional)
PerceptualHash::configure([
    'concurrency' => 4,
    'cache_max' => 100 * 1024 * 1024, // 100MB
]);
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

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently, or ever.

## Credits

- [Mike Ferrara](https://github.com/mferrara)
- [Vincent Chalnot](https://github.com/VincentChalnot)
- [Jens Segers](https://github.com/jenssegers)
- [Anatoly Pashin](https://github.com/b1rdex)
- [jcupitt](https://github.com/jcupitt)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
