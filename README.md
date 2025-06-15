# Hash Money 💰

[![Latest Version on Packagist](https://img.shields.io/packagist/v/legitphp/hash-money.svg?style=flat-square)](https://packagist.org/packages/legitphp/hash-money)
[![Tests](https://img.shields.io/github/actions/workflow/status/mferrara/hash/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mferrara/hash/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/legitphp/hash-money.svg?style=flat-square)](https://packagist.org/packages/legitphp/hash-money)

**Cache rules everything around me.**

Onions? Eggs? What do you like with your hash?

# Hash Money

We're serving up a performance-oriented and opinionated collection of similarity hashing algorithms for PHP. Whether you're comparing images, finding duplicates, or measuring how alike things are - we got you covered. We're riding dirty with [php-vips](https://github.com/php-vips/php-vips) for maximum speed. Get your FFI poppin'.

## Perceptual Hashing

I lifted much of this directly from the [VincentChalnot/PerceptualHash](https://github.com/VincentChalnot/perceptual-hash) package, 
which is a great library for generating perceptual hashes of images.

Many of the performance improvements come from various optimizations I've come across in comments on issues and pull
requests by [jcupitt](https://github.com/jcupitt) and others, which I've tried to incorporate into this package.

## Installation

You can install the package via composer:

```bash
composer require legitphp/hash-money
```

## Usage

```php
use LegitPHP\HashMoney\PerceptualHash;

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
