<?php

declare(strict_types=1);
use App\Support\Design\BrandAssetBuilder;

/**
 * Plain-CLI driver for BrandAssetBuilder — mirrors the
 * FilamentPaletteGenerator precedent (app/Support/Design/FilamentPaletteGenerator.php)
 * of being "exercised directly with the plain `php` CLI" on a host where
 * `php artisan` cannot run (PHP 8.3.6 here, composer.lock requires >=8.5).
 *
 * Usage:
 *   php docs/design/brand/build.php              # white-key on (default; source has no alpha channel)
 *   php docs/design/brand/build.php --no-key      # source is already transparent
 */
require __DIR__.'/../../../app/Support/Design/BrandAssetBuilder.php';

$noKey = in_array('--no-key', $argv, true);

$m = BrandAssetBuilder::build(
    __DIR__.'/source/logo.png',
    __DIR__.'/../../../public/brand',
    __DIR__.'/../../../public',
    whiteKey: ! $noKey
);

echo implode("\n", $m), "\n";
