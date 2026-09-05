<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Migrations\DestructiveMigrationScanner;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * CI gate: `docs/superpowers/specs/2026-09-05-cicd-automation-design.md`
 * §3.4. Runs as the `verify-migrations` job, a required PR status check
 * (branch protection, spec §2 decision 2) — this is the automated
 * replacement for the manual "read every migration before running it" step
 * every deploy has needed by hand so far (see `/opt/makam/compose/
 * compose.yml`'s own extensive comment history).
 */
final class VerifyNoDestructiveMigrationsCommand extends Command
{
    protected $signature = 'migrations:verify-no-destructive-changes {base-ref} {--repo=}';

    protected $description = 'Fails if a changed migration performs a destructive schema operation inside up() without an explicit contract-approved override.';

    public function handle(DestructiveMigrationScanner $scanner): int
    {
        $baseRef = (string) $this->argument('base-ref');
        $repo = $this->option('repo') ?? base_path();

        $diff = new Process(['git', 'diff', '--name-only', '--diff-filter=ACMR', "{$baseRef}...HEAD", '--', 'database/migrations']);
        $diff->setWorkingDirectory($repo);
        $diff->run();

        if (! $diff->isSuccessful()) {
            $this->error('git diff failed: '.$diff->getErrorOutput());

            return self::FAILURE;
        }

        $files = array_filter(explode("\n", trim($diff->getOutput())));
        $failed = false;

        foreach ($files as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }

            $path = $repo.'/'.$file;

            if (! is_file($path)) {
                continue;
            }

            foreach ($scanner->scan($path) as $violation) {
                $failed = true;
                $this->error("{$file}:{$violation['line']}: `{$violation['pattern']}` inside up() with no contract-approved override on the preceding line");
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('One or more migrations perform a destructive operation in up() without an explicit override.');
            $this->error('Per ci-cd-and-release.md §4, a genuine contract-phase migration needs a `// contract-approved: <reference>` comment on the line immediately before the destructive call.');

            return self::FAILURE;
        }

        $this->info('No unapproved destructive migration changes found.');

        return self::SUCCESS;
    }
}
