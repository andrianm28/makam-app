<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Design\FilamentPaletteGenerator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * `php artisan design:generate-filament-palette`
 *
 * Resolves OQ-09 (design-system.md §8.3): parses resources/css/tokens.css
 * and writes the Filament `colors()` PHP array to
 * app/Support/Design/generated/FilamentPalette.php. That file is generated,
 * not hand-maintained — AdminPanelProvider consumes it instead of a
 * hand-copied hex array, so tokens.css stays the single source of truth
 * design-system.md §9.1 requires.
 *
 * Companion command: `design:verify-filament-palette` fails CI when the
 * generated file has drifted from tokens.css without being regenerated.
 */
class GenerateFilamentPaletteCommand extends Command
{
    protected $signature = 'design:generate-filament-palette
        {--tokens= : Path to tokens.css (defaults to resources/css/tokens.css)}
        {--output= : Path to write the generated PHP palette file (defaults to app/Support/Design/generated/FilamentPalette.php)}';

    protected $description = 'Generate the Filament panel colors() array from resources/css/tokens.css (resolves design-system.md §8.3 OQ-09).';

    public function handle(): int
    {
        $tokensPath = $this->option('tokens') ?: resource_path('css/tokens.css');
        $outputPath = $this->option('output') ?: app_path('Support/Design/generated/FilamentPalette.php');

        try {
            $palette = FilamentPaletteGenerator::parseTokens($tokensPath);
        } catch (RuntimeException|Throwable $e) {
            $this->error("Failed to parse tokens.css: {$e->getMessage()}");

            return self::FAILURE;
        }

        $directory = dirname($outputPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Unable to create output directory: {$directory}");

            return self::FAILURE;
        }

        $php = FilamentPaletteGenerator::render($palette);

        if (file_put_contents($outputPath, $php) === false) {
            $this->error("Unable to write generated palette to: {$outputPath}");

            return self::FAILURE;
        }

        $families = count($palette);
        $this->info("Generated Filament palette at {$outputPath} ({$families} colour families) from {$tokensPath}.");
        $this->line('Run `php artisan design:verify-filament-palette` to confirm there is no drift.');

        return self::SUCCESS;
    }
}
