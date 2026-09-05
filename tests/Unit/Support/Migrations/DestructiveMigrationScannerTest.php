<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Migrations;

use App\Support\Migrations\DestructiveMigrationScanner;
use Tests\TestCase;

final class DestructiveMigrationScannerTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir().'/destructive-migration-scanner-'.uniqid();
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtureDir.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->fixtureDir);
        parent::tearDown();
    }

    private function writeFixture(string $contents): string
    {
        $path = $this->fixtureDir.'/'.uniqid('migration_').'.php';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_it_flags_a_dropcolumn_call_inside_up(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropColumn('bar');
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('dropColumn', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_flags_dropifexists_inside_up(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('legacy_table');
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('dropIfExists', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_flags_a_raw_sql_drop_table_case_insensitively(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement('drop table legacy_table');
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('DROP TABLE', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_does_not_flag_a_dropcolumn_call_confined_to_down(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->string('bar');
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropColumn('bar');
        });
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertSame([], $violations);
    }

    public function test_it_does_not_flag_a_comment_inside_up_that_merely_mentions_dropcolumn(): void
    {
        // This fixture's whole point is to exercise stripComments() for
        // real: the mention lives INSIDE up(), not above the class (a
        // prior version of this test placed it above the class, where the
        // scanner never even looks — deleting the entire comment-stripping
        // implementation left that version green; this one does not).
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        // this up() deliberately avoids dropColumn() -- see down() for
        // the real DELETE FROM this migration guards against
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->string('bar');
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropColumn('bar');
        });
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertSame([], $violations);
    }

    public function test_it_allows_a_destructive_up_call_with_a_contract_approved_marker(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            // contract-approved: PR-999
            $table->dropColumn('legacy_bar');
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('dropColumn', $violations[0]['pattern']);
        $this->assertSame('overridden', $violations[0]['status']);
    }

    public function test_it_still_flags_a_destructive_up_call_with_no_override_even_when_a_down_exists(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP TABLE legacy');
    }

    public function down(): void
    {
        //
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('DROP TABLE', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_does_not_flag_a_safe_index_swap(): void
    {
        // dropIndex/dropUnique were removed from the pattern list entirely
        // — dropping an index does not destroy data, so it never belonged
        // in this gate. This fixture mirrors a real migration in this repo
        // (2026_08_10_130200_harden_reconciliation_exceptions.php) that
        // does exactly this safely.
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->unique(['a', 'b'], 'foo_a_b_unique');
        });
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropUnique('foo_a_unique');
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertSame([], $violations);
    }
}
