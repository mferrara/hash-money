<?php

declare(strict_types=1);

use Jcupitt\Vips\Image as VipsImage;
use LegitPHP\HashMoney\CompositeHash;
use LegitPHP\HashMoney\HashValue;
use LegitPHP\HashMoney\Strategies\PerceptualHashStrategy;

/**
 * Recoverable corruption must hash, garbage must throw.
 *
 * On newer libvips (8.18+) the fast sequential path tolerates these
 * buffers itself; on older versions (8.15 observed in production) the
 * fast path raises "Corrupt JPEG data: N extraneous bytes before
 * marker 0xdN" style errors and the random-access fail_on=none
 * fallback in AbstractHashStrategy::thumbnailFromBuffer() carries the
 * decode. These tests exercise whichever path the local libvips takes.
 */
function makeNoiseJpeg(int $restartInterval = 0): string
{
    $im = VipsImage::gaussnoise(640, 480, ['mean' => 128, 'sigma' => 40])->cast('uchar');
    $options = ['Q' => 82];
    if ($restartInterval > 0) {
        $options['restart_interval'] = $restartInterval;
    }

    return $im->jpegsave_buffer($options);
}

it('hashes a JPEG with corrupt restart-marker data', function () {
    $buf = makeNoiseJpeg(4);

    // Inject extraneous bytes before a restart marker — the corruption
    // class libjpeg reports as "N extraneous bytes before marker 0xdN".
    $pos = strpos($buf, "\xFF\xD1", 1000);
    expect($pos)->not->toBeFalse();
    $corrupt = substr($buf, 0, $pos)."\x01\x02\x03\x04".substr($buf, $pos);

    $hash = (new PerceptualHashStrategy)->hashFromString($corrupt);
    expect($hash)->toBeInstanceOf(HashValue::class);
});

it('hashes a truncated JPEG', function () {
    $buf = makeNoiseJpeg();
    $truncated = substr($buf, 0, (int) (strlen($buf) * 0.6));

    $hash = (new PerceptualHashStrategy)->hashFromString($truncated);
    expect($hash)->toBeInstanceOf(HashValue::class);
});

it('hashes recoverable corruption through the composite strategy', function () {
    $buf = makeNoiseJpeg(4);
    $pos = strpos($buf, "\xFF\xD1", 1000);
    $corrupt = substr($buf, 0, $pos)."\x01\x02\x03".substr($buf, $pos);

    $hash = CompositeHash::default()->hashFromString($corrupt);
    expect($hash)->toBeInstanceOf(HashValue::class);
});

it('still throws for bytes that are not an image', function () {
    $garbage = str_repeat('this is not an image ', 500);

    expect(fn () => (new PerceptualHashStrategy)->hashFromString($garbage))
        ->toThrow(RuntimeException::class);
});

it('produces an equivalent hash via the tolerant fallback path', function () {
    $buf = makeNoiseJpeg();
    $strategy = new PerceptualHashStrategy;

    $fast = $strategy->hashFromString($buf);

    // Drive the fallback construction directly: random-access decode
    // with fail_on=none, then thumbnail_image — the same pipeline the
    // helper falls back to when the sequential path fails.
    $source = VipsImage::newFromBuffer($buf, '', [
        'access' => 'random',
        'fail_on' => 'none',
    ])->copyMemory();
    $image = $source->thumbnail_image(32, [
        'height' => 32,
        'size' => 'force',
        'linear' => true,
        'import_profile' => 'srgb',
        'export_profile' => 'srgb',
    ])->copyMemory();
    $fallback = $strategy->hashFromVipsImage($image);

    expect($fast->hammingDistance($fallback))->toBeLessThanOrEqual(2);
});
