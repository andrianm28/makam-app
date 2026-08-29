<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\View\Compilers\BladeCompiler;
use Symfony\Component\Finder\Finder;

/**
 * `php artisan blade:verify-content-survival`
 *
 * design-system.md §9.5 CI gate 13. Closes finding N-14's real blind spot,
 * discovered 8 Aug 2026 by three independent agent-team teammates
 * (`renewal-builder`, `marketplace-builder`, `directory-builder`) auditing
 * the same class of bug from different angles, and confirmed the hard way:
 * this repo's own N-14 correction comment in `faq/index.blade.php` spelled
 * out the literal raw-PHP directive it was warning about, and was live —
 * defused only by chance (a second real directive pair that used to sit
 * later in the same file was removed by an unrelated edit) rather than by
 * any check catching it.
 *
 * ---------------------------------------------------------------------------
 * Why `php -l` and "it compiles" are NOT this gate
 * ---------------------------------------------------------------------------
 * `BladeCompiler::compileString()` runs `storeUncompiledBlocks()` — which
 * extracts `@php ... @endphp` (and `@verbatim ... @endverbatim`) into raw
 * placeholders — BEFORE `compileComments()` strips `{{-- --}}` comments. A
 * comment that merely *mentions* the opening directive as bare text pairs
 * with the next real closing directive and silently swallows everything
 * between, including the comment's own closing `--}}`. That is only step
 * one — confirmed by stepping through both compiler methods directly
 * (reflection on `storeUncompiledBlocks`/`compileComments` against this
 * repo's own real incident, 8 Aug 2026): with the comment's real `--}}`
 * gone, its `{{--` is now unclosed in the working string, so
 * `compileComments()` races forward and closes it against the *next* real
 * `--}}` anywhere later in the file — silently deleting everything in
 * between a second time, including the raw-block placeholder that would
 * otherwise have preserved the swallowed text verbatim. Without a later
 * `--}}` for that second stage to land on, the raw block instead survives
 * intact but wrapped in a literal `<?php ... ?>` tag containing non-PHP
 * markup, which fails `php -l` loudly — a different, already-caught bug.
 * It is specifically the two-stage form, needing a later real comment to
 * complete the cascade, that produces output which is still syntactically
 * valid PHP — it is just missing template content — so `php -l` on the
 * compiled output, or a bare `compileString()` call with no further check,
 * reports success on a genuinely broken file. This command is the check
 * that actually looks.
 *
 * ---------------------------------------------------------------------------
 * How it works — content survival, not syntax
 * ---------------------------------------------------------------------------
 * For every `.blade.php` file: strip `{{-- --}}` comments from the SOURCE
 * (they are supposed to vanish — that is not corruption), derive a set of
 * anchors from what remains (contiguous visible text runs >= 12 chars,
 * literal `class="..."` attribute values, `<x-...>` component tags, and
 * `<x-slot:name>` named slots), compile the ORIGINAL (unstripped) source
 * through the real installed Blade compiler, and assert every anchor
 * survives into the compiled output. A named slot compiles to
 * `$__env->slot('name', ...)`/`endSlot()`, never a component resolve call,
 * so it is checked separately from ordinary component tags — conflating the
 * two was an early false-positive `renewal-builder` found and fixed before
 * handing this algorithm over.
 *
 * This is a heuristic, not a formal proof — a wrapped multi-line sentence is
 * intentionally excluded (it was never contiguous in the compiled output
 * either), and a false negative is theoretically possible for content this
 * heuristic cannot see. It was run repo-wide during development and
 * confirmed to have zero false positives across all Livewire views once the
 * component/slot distinction was fixed. Where it does report a loss, that is
 * real corruption, not this gate being wrong — do not weaken the anchors to
 * make a corrupted file pass.
 *
 * Command signature and structure follow
 * `VerifyFilamentPaletteCommand`'s own precedent (CI gate 6) — same reason:
 * this needs a bootstrapped Laravel app with the real Blade compiler, which
 * `ci/verify-docs.sh` (pure bash+python, no `vendor/`) cannot provide, so it
 * runs as a step in `.github/workflows/ci.yml`'s `php` job instead.
 */
class VerifyBladeContentSurvivalCommand extends Command
{
    protected $signature = 'blade:verify-content-survival
        {--path=resources/views : Directory to scan for .blade.php files, relative to the app base path}';

    protected $description = 'Fail if any Blade view loses content when compiled — catches the N-14 doc-comment-corruption class that php -l cannot see (design-system.md CI gate 13).';

    public function handle(): int
    {
        $scanPath = base_path((string) $this->option('path'));

        if (! is_dir($scanPath)) {
            $this->error("Scan path not found: {$scanPath}");

            return self::FAILURE;
        }

        $files = Finder::create()->files()->in($scanPath)->name('*.blade.php')->sortByName();

        /** @var BladeCompiler $compiler */
        $compiler = $this->laravel->make('blade.compiler');

        $corrupt = 0;
        $checked = 0;

        foreach ($files as $file) {
            $checked++;
            $path = $file->getRealPath();
            $source = (string) file_get_contents($path);

            $compiled = $compiler->compileString($source);

            $lost = self::findLostAnchors($source, $compiled);

            if ($lost !== []) {
                $corrupt++;
                $this->error('CORRUPT  '.$file->getRelativePathname());

                foreach ($lost as $l) {
                    $this->line('           LOST: '.$l);
                }
            }
        }

        if ($corrupt === 0) {
            $this->info("Content survival verified — {$checked} Blade file(s), 0 corrupted.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error("{$corrupt} of {$checked} Blade file(s) lose content when compiled — see finding N-14.");
        $this->line('Likely cause: a {{-- --}} comment mentions "the raw-PHP directive" or "the verbatim directive" as bare text before a real one appears later in the file. Describe the directive in prose instead of writing it literally.');

        return self::FAILURE;
    }

    /**
     * @return list<string> human-readable descriptions of lost anchors, empty when nothing was lost
     */
    private static function findLostAnchors(string $source, string $compiled): array
    {
        // Blade comments are SUPPOSED to vanish — strip them from the
        // SOURCE before deriving anchors, or every real comment reads as a
        // false loss. The compiled string is left untouched; corruption is
        // judged by what the real compiler actually produced.
        $body = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $normalizedCompiled = (string) preg_replace('/\s+/', ' ', $compiled);

        $lost = [];

        // 1. Visible copy: text between > and <, ignoring anything touching
        //    a directive/echo (those legitimately change shape at compile
        //    time). Single-line runs only — a sentence wrapped across
        //    source lines was never contiguous in the output either.
        preg_match_all('/>([^<>{}@]{12,})</s', $body, $textMatches);
        $copyAnchors = [];
        foreach ($textMatches[1] as $t) {
            // The multi-line check MUST run before trim(): a match that only
            // spans a line break because it captured trailing whitespace up
            // to the next tag (e.g. an arrow operator's `>` resetting the
            // anchor start mid-directive, such as `->plots as $plot)` before
            // a tag on the next line) has its only "\n" trimmed away first,
            // silently defeating this exclusion — confirmed against a real
            // false positive on `plot-floor-map/granular.blade.php`.
            if (str_contains($t, "\n")) {
                continue;
            }

            $t = trim($t);
            if ($t !== '') {
                $copyAnchors[$t] = true;
            }
        }
        foreach (array_keys($copyAnchors) as $anchor) {
            $needle = (string) preg_replace('/\s+/', ' ', $anchor);
            if (! str_contains($normalizedCompiled, $needle)) {
                $lost[] = 'copy: '.mb_substr($anchor, 0, 70);
            }
        }

        // 2. Structure: literal class="..." attribute values (no
        //    interpolation — those are a different, already-covered risk).
        preg_match_all('/class="([^"{}@]{10,})"/', $body, $classMatches);
        $classAnchors = [];
        foreach ($classMatches[1] as $c) {
            $classAnchors[trim((string) preg_replace('/\s+/', ' ', $c))] = true;
        }
        foreach (array_keys($classAnchors) as $anchor) {
            if (! str_contains($normalizedCompiled, $anchor)) {
                $lost[] = 'class: '.mb_substr($anchor, 0, 70);
            }
        }

        // 3. Component tags must EXPAND, not vanish. Named slots
        //    (<x-slot:foo>) are NOT components — they compile to
        //    $__env->slot('foo', ...)/endSlot(), never a component
        //    resolve call — so they are checked separately. Treating a
        //    named slot as a component produces a false CORRUPT, which is
        //    exactly the kind of noise that gets a real finding ignored.
        preg_match_all('/<x-([\w.:-]+)/', $body, $tagMatches);
        $allTags = array_unique($tagMatches[1]);
        $components = array_values(array_filter($allTags, static fn (string $c): bool => ! str_starts_with($c, 'slot:')));
        $slots = array_values(array_map(
            static fn (string $c): string => substr($c, 5),
            array_filter($allTags, static fn (string $c): bool => str_starts_with($c, 'slot:'))
        ));

        foreach ($components as $c) {
            if (! str_contains($compiled, $c)) {
                $lost[] = "component <x-{$c}> disappeared entirely";
            }
        }
        foreach ($slots as $slotName) {
            if (! str_contains($compiled, "slot('{$slotName}'")) {
                $lost[] = "named slot <x-slot:{$slotName}> disappeared entirely";
            }
        }

        return $lost;
    }
}
