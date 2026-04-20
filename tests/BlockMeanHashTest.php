<?php

declare(strict_types=1);

use Jcupitt\Vips\Image;
use LegitPHP\HashMoney\BlockMeanHash;
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\PerceptualHash;

beforeEach(function () {
    BlockMeanHash::configure(['cores' => 2]);
});

test('can generate 64-bit block mean hash from file', function () {
    $hash = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getBits())->toBe(64);
    expect($hash->getAlgorithm())->toBe('block-mean');
    expect($hash->getValue())->toBeInt();
});

test('same image produces same block mean hash', function () {
    $hash1 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash1->equals($hash2))->toBeTrue();
    expect(BlockMeanHash::distance($hash1, $hash2))->toBe(0);
});

test('file and memory hashes match', function () {
    $fileHash = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $memHash = BlockMeanHash::hashFromString(file_get_contents(__DIR__.'/../images/cat1.jpg'));

    expect($fileHash->equals($memHash))->toBeTrue();
});

test('similar images (color vs bw) have small block mean distance', function () {
    $hash1 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1-bw.jpg');

    expect(BlockMeanHash::distance($hash1, $hash2))->toBeLessThan(10);
});

test('different images have larger block mean distance', function () {
    $hash1 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $hash2 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat2.jpg');

    expect(BlockMeanHash::distance($hash1, $hash2))->toBeGreaterThan(10);
});

test('supports multiple bit sizes', function () {
    $hash8 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 8);
    $hash64 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 64);
    $hash128 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 128);
    $hash256 = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 256);

    expect($hash8->getBits())->toBe(8);
    expect($hash64->getBits())->toBe(64);
    expect($hash128->getBits())->toBe(128);
    expect($hash256->getBits())->toBe(256);

    expect(strlen($hash256->getBytes()))->toBe(32);
});

test('128- and 256-bit hashes cannot use getValue()', function () {
    $hash = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg', 256);
    expect(fn () => $hash->getValue())->toThrow(LogicException::class);
});

test('throws on unsupported bit size', function () {
    $image = Image::newFromFile(__DIR__.'/../images/cat1.jpg');
    expect(fn () => BlockMeanHash::hashFromVipsImage($image, 48))
        ->toThrow(InvalidArgumentException::class, 'Unsupported bit size');
});

test('cannot compare with perceptual hash', function () {
    $b = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $p = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect(fn () => BlockMeanHash::distance($b, $p))
        ->toThrow(InvalidArgumentException::class);
});

test('produces different values than perceptual hash for same image', function () {
    $b = BlockMeanHash::hashFromFile(__DIR__.'/../images/cat1.jpg');
    $p = PerceptualHash::hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($b->getValue())->not->toBe($p->getValue());
});
