<?php

declare(strict_types=1);

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\PdqHash;
use LegitPHP\HashMoney\PerceptualHash;
use LegitPHP\HashMoney\Strategies\PdqHashStrategy;

beforeEach(function () {
    PdqHash::configure(['cores' => 2]);
});

test('produces a 256-bit hash from a file', function () {
    $hash = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getBits())->toBe(256);
    expect($hash->getAlgorithm())->toBe('pdq');
    expect(strlen($hash->getBytes()))->toBe(32);
    expect(strlen($hash->toHex()))->toBe(64);
});

test('hash is exactly half-set on natural images (median-thresholded)', function () {
    $hash = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash->countSetBits())->toBe(128);
});

test('exposes a quality score in [0, 100]', function () {
    $hash = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $quality = PdqHash::quality($hash);

    expect($quality)->toBeInt();
    expect($quality)->toBeGreaterThanOrEqual(0);
    expect($quality)->toBeLessThanOrEqual(100);
});

test('quality is also retrievable via HashValue metadata', function () {
    $hash = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash->getMetadata('pdq_quality'))->toBe(PdqHash::quality($hash));
});

test('same image produces same hash', function () {
    $h1 = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $h2 = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($h1->equals($h2))->toBeTrue();
    expect(PdqHash::distance($h1, $h2))->toBe(0);
});

test('file and string entry points produce identical hashes', function () {
    $fileHash = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $stringHash = PdqHash::hashFromString(file_get_contents(__DIR__.'/../images/cat1.jpg'));

    expect($fileHash->equals($stringHash))->toBeTrue();
});

test('grayscale variant is well within match threshold of color original', function () {
    $color = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $bw = PdqHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg');

    $distance = PdqHash::distance($color, $bw);

    expect($distance)->toBeLessThan(PdqHashStrategy::RECOMMENDED_DISTANCE_THRESHOLD);
});

test('different images are far apart', function () {
    $h1 = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $h2 = PdqHash::hashFromFile(__DIR__.'/../images/cat2.jpg');

    expect(PdqHash::distance($h1, $h2))->toBeGreaterThan(PdqHashStrategy::RECOMMENDED_DISTANCE_THRESHOLD);
});

test('only 256-bit hashes are supported', function () {
    expect(fn () => PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 64))
        ->toThrow(InvalidArgumentException::class, 'PDQ produces 256-bit hashes only');
});

test('cannot getValue() on a 256-bit PDQ hash', function () {
    $hash = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect(fn () => $hash->getValue())->toThrow(LogicException::class);
});

test('cannot compare PDQ to a different algorithm', function () {
    $pdq = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $phash = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect(fn () => PdqHash::distance($pdq, $phash))
        ->toThrow(InvalidArgumentException::class);
});

test('hashFromVipsImage works on a manually-loaded image', function () {
    $image = VipsImage::thumbnail(__DIR__.'/../images/cat1.jpg', 512, [
        'height' => 512,
        'size' => 'down',
    ]);

    $hash = PdqHash::hashFromVipsImage($image);

    expect($hash->getBits())->toBe(256);
    expect($hash->getAlgorithm())->toBe('pdq');
});

test('eight dihedral hashes are computed and quality is shared', function () {
    $result = PdqHash::hashesFromFile(__DIR__.'/../images/cat1.jpg');

    expect($result)->toHaveKeys(['hashes', 'quality']);
    expect($result['quality'])->toBeInt();

    $expected = [
        PdqHashStrategy::DIH_ORIGINAL,
        PdqHashStrategy::DIH_ROTATE_90,
        PdqHashStrategy::DIH_ROTATE_180,
        PdqHashStrategy::DIH_ROTATE_270,
        PdqHashStrategy::DIH_FLIP_X,
        PdqHashStrategy::DIH_FLIP_Y,
        PdqHashStrategy::DIH_FLIP_PLUS_1,
        PdqHashStrategy::DIH_FLIP_MINUS_1,
    ];
    expect(array_keys($result['hashes']))->toBe($expected);

    foreach ($result['hashes'] as $hash) {
        expect($hash)->toBeInstanceOf(HashValue::class);
        expect($hash->getBits())->toBe(256);
        expect($hash->countSetBits())->toBe(128);
        expect(PdqHash::quality($hash))->toBe($result['quality']);
    }

    $orig = $result['hashes'][PdqHashStrategy::DIH_ORIGINAL];
    expect($orig->equals($result['hashes'][PdqHashStrategy::DIH_ROTATE_90]))->toBeFalse();
    expect($orig->equals($result['hashes'][PdqHashStrategy::DIH_FLIP_X]))->toBeFalse();
});

test('distance is symmetric', function () {
    $h1 = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $h2 = PdqHash::hashFromFile(__DIR__.'/../images/cat2.jpg');

    expect(PdqHash::distance($h1, $h2))->toBe(PdqHash::distance($h2, $h1));
});

test('configure rejects too-small workingSize', function () {
    expect(fn () => PdqHash::configure(['workingSize' => 32]))
        ->toThrow(InvalidArgumentException::class, 'PDQ workingSize must be >= 64');
});

test('hex round-trip via HashValue::fromHex preserves the hash and bits', function () {
    $hash = PdqHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $rebuilt = HashValue::fromHex($hash->toHex(), 256, 'pdq');

    expect($rebuilt->getBytes())->toBe($hash->getBytes());
    expect($hash->isCompatibleWith($rebuilt))->toBeTrue();
    expect($hash->hammingDistance($rebuilt))->toBe(0);
});
