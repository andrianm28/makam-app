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

    public function test_it_does_not_flag_a_doc_comment_that_merely_mentions_dropcolumn(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
/**
 * This migration never calls dropColumn() in up() — see down() for the
 * rollback, which does.
 */
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
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropTable('legacy');
        });
    }

    public function down(): void
    {
        //
    }
};
PHP);

        $violations = (new DestructiveMigrationScanner)->scan($path);

        $this->assertCount(1, $violations);
        $this->assertSame('dropTable', $violations[0]['pattern']);
        $this->assertSame('violation', $violations[0]['status']);
    }
}
