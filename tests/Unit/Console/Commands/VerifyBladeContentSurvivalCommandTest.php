<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class VerifyBladeContentSurvivalCommandTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = storage_path('framework/testing/blade-content-survival-fixtures');
        File::deleteDirectory($this->fixturePath);
        File::makeDirectory($this->fixturePath, recursive: true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    /**
     * Regression for a real false positive found while merging the
     * plot-availability-dashboard branch: `@foreach ($block->plots as
     * $plot)` immediately followed by a tag on the next line was reported
     * as lost content. The arrow operator's `->` puts a literal `>` right
     * before `plots as $plot)`, which becomes the anchor's start; the run
     * then continues across the line break to the next `<` tag. The old
     * code called trim() before checking for "\n", which stripped the
     * trailing newline/indentation and hid the multi-line evidence that
     * should have excluded this match.
     */
    public function test_an_arrow_operator_directly_before_a_tag_on_the_next_line_is_not_a_false_positive(): void
    {
        File::put($this->fixturePath.'/arrow-across-newline.blade.php', <<<'BLADE'
        <div class="flex flex-wrap gap-2">
            @foreach ($block->plots as $plot)
                <x-filament::button>{{ $plot->slot }}</x-filament::button>
            @endforeach
        </div>
        BLADE);

        Artisan::call('blade:verify-content-survival', [
            '--path' => $this->relativeFixturePath(),
        ]);

        $this->assertStringContainsString('0 corrupted', Artisan::output());
    }

    /**
     * The exact two-stage cascade the class doc block describes: a comment
     * mentions the real directive as bare text, pairs with the next real
     * closing directive and swallows the comment's own closing "--}}";
     * compileComments() then races forward to the NEXT real "--}}" in the
     * file, deleting everything up to it a second time. Content before the
     * corrupted span, and content after the second comment closes, must
     * both still be checked correctly.
     */
    public function test_the_documented_two_stage_bare_directive_mention_is_still_caught(): void
    {
        File::put($this->fixturePath.'/two-stage-n14.blade.php', <<<'BLADE'
        {{-- mentions @php as bare text --}}
        <div class="marker-one-should-be-lost">Marker one lost content</div>
        @php $a = 1; @endphp
        {{-- second real comment to give compileComments() a landing point --}}
        <div class="marker-two-should-survive">Marker two survives here</div>
        BLADE);

        Artisan::call('blade:verify-content-survival', [
            '--path' => $this->relativeFixturePath(),
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('CORRUPT', $output);
        $this->assertStringContainsString('Marker one lost content', $output);
    }

    private function relativeFixturePath(): string
    {
        return ltrim(str_replace(base_path(), '', $this->fixturePath), '/');
    }
}
