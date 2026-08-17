<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Design\BrandAssetBuilder;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * `php artisan design:build-brand-assets`
 *
 * Artisan wrapper around BrandAssetBuilder (app/Support/Design/BrandAssetBuilder.php)
 * for CI and any environment running PHP >= 8.5 (this host's PHP 8.3.6 is
 * below composer.lock's floor and cannot run artisan — the plain-CLI driver
 * at docs/design/brand/build.php is what actually built and verified the
 * committed assets; see this batch's task-3 report).
 *
 * Output targets are fixed by web convention, not options: the eight
 * brand/* assets always go to public/brand, and favicon.ico +
 * apple-touch-icon.png always go to public/. Only the source PNG and the
 * white-key toggle are configurable.
 */
class BuildBrandAssets extends Command
{
    protected $signature = 'design:build-brand-assets
        {--source=docs/design/brand/source/logo.png : Path to the master logo PNG}
        {--no-key : Skip white-keying — use when the source PNG already has an alpha channel}';

    protected $description = 'Build the brand raster asset manifest (mark, inverse mark, lockups, favicon.ico, apple-touch-icon.png) from the master logo PNG.';

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $sourcePath = str_starts_with($source, '/') ? $source : base_path($source);
        $whiteKey = ! $this->option('no-key');

        try {
            $manifest = BrandAssetBuilder::build(
                $sourcePath,
                public_path('brand'),
                public_path(),
                whiteKey: $whiteKey,
            );
        } catch (RuntimeException|Throwable $e) {
            $this->error("Failed to build brand assets: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Built brand assets:');

        foreach ($manifest as $file) {
            $this->line("  {$file}");
        }

        return self::SUCCESS;
    }
}
