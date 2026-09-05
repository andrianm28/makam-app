<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class VerifyNoDestructiveMigrationsCommandTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/verify-migrations-cmd-'.uniqid();
        mkdir($this->repo.'/database/migrations', 0777, true);
        $this->git('init -q -b trunk');
        $this->git('config user.email t@example.com');
        $this->git('config user.name Test');
        file_put_contents($this->repo.'/.keep', '');
        $this->git('add -A');
        $this->git('commit -q -m base');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));
        parent::tearDown();
    }

    private function git(string $args): void
    {
        exec('git -C '.escapeshellarg($this->repo).' '.$args.' 2>&1');
    }

    public function test_it_passes_when_the_only_changed_migration_has_no_destructive_up_call(): void
    {
        file_put_contents($this->repo.'/database/migrations/2026_01_01_000000_safe.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->string('bar');
        });
    }
    public function down(): void {}
};
PHP);
        $this->git('add -A');
        $this->git('commit -q -m "add safe migration"');

        $this->artisan('migrations:verify-no-destructive-changes', [
            'base-ref' => 'trunk~1',
            '--repo' => $this->repo,
        ])->assertExitCode(0);
    }

    public function test_it_fails_when_the_changed_migration_has_a_destructive_up_call(): void
    {
        file_put_contents($this->repo.'/database/migrations/2026_01_01_000001_unsafe.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('foo', function ($table) {
            $table->dropColumn('bar');
        });
    }
    public function down(): void {}
};
PHP);
        $this->git('add -A');
        $this->git('commit -q -m "add unsafe migration"');

        $this->artisan('migrations:verify-no-destructive-changes', [
            'base-ref' => 'trunk~1',
            '--repo' => $this->repo,
        ])->assertExitCode(1);
    }

    public function test_it_passes_when_no_migration_files_changed(): void
    {
        file_put_contents($this->repo.'/README.md', 'unrelated change');
        $this->git('add -A');
        $this->git('commit -q -m "unrelated change"');

        $this->artisan('migrations:verify-no-destructive-changes', [
            'base-ref' => 'trunk~1',
            '--repo' => $this->repo,
        ])->assertExitCode(0);
    }
}
