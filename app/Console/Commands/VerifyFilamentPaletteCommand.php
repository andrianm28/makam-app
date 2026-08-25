<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Design\FilamentPaletteGenerator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * `php artisan design:verify-filament-palette`
 *
 * design-system.md §9.5 CI gate 6: "Filament PHP palette matches
 * tokens.css (see §8.3 known gap)". This is the "helper to be written"
 * that section names by its exact command signature.
 *
 * Re-parses resources/css/tokens.css fresh and diffs it against whatever
 * app/Support/Design/generated/FilamentPalette.php currently contains.
 * Exits non-zero on ANY drift — missing family, missing shade, changed hex,
 * or a stale entry left over from a previous tokens.css — so a colour
 * change that forgets to re-run `design:generate-filament-palette` fails
 * CI instead of silently shipping a Filament panel that disagrees with the
 * public site.
 *
 * This command is a documented hard dependency for Batch 2.5 (S2-T4), which
 * wires it into ci/verify-docs.sh / .github/workflows/ci.yml as gate 6.
 * This batch does not touch either of those files — only makes this command
 * itself work when run manually.
 */
class VerifyFilamentPaletteCommand extends Command
{
    protected $signature = 'design:verify-filament-palette
        {--tokens= : Path to tokens.css (defaults to resources/css/tokens.css)}
        {--generated= : Path to the generated PHP palette file (defaults to app/Support/Design/generated/FilamentPalette.php)}';

    protected $description = 'Fail if the generated Filament palette has drifted from resources/css/tokens.css (design-system.md §8.3 OQ-09, CI gate 6).';

    public function handle(): int
    {
        $tokensPath = $this->option('tokens') ?: resource_path('css/tokens.css');
        $generatedPath = $this->option('generated') ?: app_path('Support/Design/generated/FilamentPalette.php');

        if (! is_file($generatedPath)) {
            $this->error("Generated palette not found at {$generatedPath}.");
            $this->line('Run: php artisan design:generate-filament-palette');

            return self::FAILURE;
        }

        try {
            $expected = FilamentPaletteGenerator::parseTokens($tokensPath);
        } catch (RuntimeException|Throwable $e) {
            $this->error("Failed to parse tokens.css: {$e->getMessage()}");

            return self::FAILURE;
        }

        $actual = require $generatedPath;

        if (! is_array($actual)) {
            $this->error("Generated palette file at {$generatedPath} did not return an array.");

            return self::FAILURE;
        }

        $diff = FilamentPaletteGenerator::diff($expected, $actual);

        if ($diff === []) {
            $this->info('Filament palette matches tokens.css. No drift.');

            return self::SUCCESS;
        }

        $this->error('Filament palette has drifted from tokens.css:');

        foreach ($diff as $line) {
            $this->line("  - {$line}");
        }

        $this->line('Run: php artisan design:generate-filament-palette');

        return self::FAILURE;
    }
}
