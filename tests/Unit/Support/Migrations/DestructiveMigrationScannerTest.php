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

    public function test_it_flags_a_destructive_call_in_a_private_helper_declared_after_down(): void
    {
        // The original blind spot: scan() sliced "up() start to down()
        // start" as up()'s body. A private helper method declared AFTER
        // down() — called FROM up() — fell outside that slice AND outside
        // down()'s own body, so it was invisible to the old scanner.
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        $this->dropLegacyColumn();
    }

    public function down(): void
    {
    }

    private function dropLegacyColumn(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropColumn('legacy');
        });
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('dropColumn', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_flags_a_destructive_helper_after_down_when_down_is_declared_before_up(): void
    {
        // Same blind spot, opposite declaration order: down() declared
        // FIRST in the file, up() second, and the destructive helper is
        // declared after BOTH. A fix that only reasons about "down always
        // comes after up" would miss this ordering.
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function down(): void
    {
    }

    public function up(): void
    {
        $this->dropLegacyColumn();
    }

    private function dropLegacyColumn(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropColumn('legacy');
        });
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('dropColumn', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_still_excludes_downs_own_body_when_down_is_declared_before_up(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function down(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropColumn('bar');
        });
    }

    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->string('bar');
        });
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertSame([], $violations);
    }

    public function test_it_excludes_downs_body_even_with_nested_braces(): void
    {
        // down()'s body here contains its own nested closure braces
        // (Schema::table's callback) — a naive "next closing brace after
        // down(" would truncate the exclusion at the closure's own `}`
        // instead of down()'s, leaving the rest of down() (and its real
        // DELETE FROM) exposed to the scanner.
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
        \Illuminate\Support\Facades\DB::statement('DELETE FROM foo');
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertSame([], $violations);
    }

    public function test_it_flags_a_change_call(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->string('bar', 100)->change();
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('->change(', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_flags_a_renamecolumn_call(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->renameColumn('old_name', 'new_name');
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('renameColumn', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_flags_a_dropprimary_call(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropPrimary();
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('dropPrimary', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_flags_a_raw_drop_constraint_statement(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE foo DROP CONSTRAINT foo_check');
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('DROP CONSTRAINT', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_flags_an_alter_column_type_statement(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE foo ALTER COLUMN bar TYPE integer');
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('ALTER COLUMN ... TYPE', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }

    public function test_it_does_not_flag_an_alter_column_set_not_null_statement(): void
    {
        // A bare `ALTER COLUMN` also appears in safe forms (SET NOT NULL,
        // DROP DEFAULT, SET DEFAULT) that must not be flagged — only the
        // co-occurrence with `TYPE` is destructive.
        $path = $this->writeFixture(<<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE foo ALTER COLUMN bar SET NOT NULL');
    }

    public function down(): void
    {
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertSame([], $violations);
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
