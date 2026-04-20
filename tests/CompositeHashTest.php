<?php

declare(strict_types=1);

use LegitPHP\HashMoney\CompositeHash;
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\Strategies\BlockMeanHashStrategy;
use LegitPHP\HashMoney\Strategies\CompositeHashStrategy;
use LegitPHP\HashMoney\Strategies\DHashStrategy;
use LegitPHP\HashMoney\Strategies\PerceptualHashStrategy;

test('default composite produces 256-bit hash', function () {
    $composite = CompositeHash::default();
    $hash = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($hash)->toBeInstanceOf(HashValue::class);
    expect($hash->getBits())->toBe(256);
    expect($hash->getAlgorithm())->toBe('composite:perceptual+dhash+color-histogram+block-mean');
    expect(strlen($hash->getBytes()))->toBe(32);
});

test('default composite has chunk layout metadata', function () {
    $composite = CompositeHash::default();
    $hash = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');

    $chunks = $hash->getMetadata('chunks');
    expect($chunks)->toBeArray();
    expect($chunks)->toHaveCount(4);

    expect($chunks[0]['algorithm'])->toBe('perceptual');
    expect($chunks[0]['bits'])->toBe(64);
    expect($chunks[0]['offset_bytes'])->toBe(0);

    expect($chunks[1]['algorithm'])->toBe('dhash');
    expect($chunks[1]['offset_bytes'])->toBe(8);

    expect($chunks[2]['algorithm'])->toBe('color-histogram');
    expect($chunks[2]['offset_bytes'])->toBe(16);

    expect($chunks[3]['algorithm'])->toBe('block-mean');
    expect($chunks[3]['offset_bytes'])->toBe(24);
});

test('composite chunks match individual strategy output', function () {
    $phash = (new PerceptualHashStrategy)->hashFromFile(__DIR__.'/../images/cat1.jpg');
    $dhash = (new DHashStrategy)->hashFromFile(__DIR__.'/../images/cat1.jpg');

    $composite = CompositeHash::of(new PerceptualHashStrategy, new DHashStrategy);
    $hash = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');

    $bytes = $hash->getBytes();
    expect(substr($bytes, 0, 8))->toBe($phash->getBytes());
    expect(substr($bytes, 8, 8))->toBe($dhash->getBytes());
});

test('same image produces same composite hash', function () {
    $composite = CompositeHash::default();
    $h1 = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');
    $h2 = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect($h1->equals($h2))->toBeTrue();
    expect($composite->distance($h1, $h2))->toBe(0);
});

test('different images have large composite hamming distance', function () {
    $composite = CompositeHash::default();
    $h1 = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');
    $h2 = $composite->hashFromFile(__DIR__.'/../images/cat2.jpg');

    expect($composite->distance($h1, $h2))->toBeGreaterThan(40);
});

test('hashFromString matches hashFromFile', function () {
    $composite = CompositeHash::default();
    $fromFile = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');
    $fromString = $composite->hashFromString(file_get_contents(__DIR__.'/../images/cat1.jpg'));

    expect($fromFile->equals($fromString))->toBeTrue();
});

test('cannot compare composites with different components', function () {
    $a = CompositeHash::of(new PerceptualHashStrategy, new DHashStrategy)
        ->hashFromFile(__DIR__.'/../images/cat1.jpg');
    $b = CompositeHash::of(new DHashStrategy, new PerceptualHashStrategy)
        ->hashFromFile(__DIR__.'/../images/cat1.jpg');

    expect(fn () => $a->hammingDistance($b))->toThrow(InvalidArgumentException::class);
});

test('mixed custom bit sizes', function () {
    $composite = new CompositeHashStrategy([
        ['strategy' => new PerceptualHashStrategy, 'bits' => 32],
        ['strategy' => new DHashStrategy, 'bits' => 64],
        ['strategy' => new BlockMeanHashStrategy, 'bits' => 32],
    ]);

    $hash = $composite->hashFromFile(__DIR__.'/../images/cat1.jpg');
    expect($hash->getBits())->toBe(128);
    expect($composite->getTotalBits())->toBe(128);
});

test('empty components list throws', function () {
    expect(fn () => new CompositeHashStrategy([]))
        ->toThrow(InvalidArgumentException::class);
});

test('explicit mismatched bits parameter throws', function () {
    $composite = CompositeHash::default();
    expect(fn () => $composite->hashFromFile(__DIR__.'/../images/cat1.jpg', 512))
        ->toThrow(InvalidArgumentException::class);
});
