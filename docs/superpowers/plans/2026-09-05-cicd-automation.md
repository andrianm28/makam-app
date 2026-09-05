# CI/CD Automation for the Dev+Beta Deploy Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an automated destructive-migration gate and two self-hosted-runner deploy jobs (`deploy-dev`, `deploy-beta`) to `.github/workflows/ci.yml`, update the operational docs to describe the new pipeline honestly, and write the host-setup runbook a human will later execute to actually activate it.

**Architecture:** A new PHP scanner + Artisan command implements the destructive-migration gate as a new `verify-migrations` CI job. Two new jobs (`deploy-dev`, `deploy-beta`) run on a self-hosted runner that does not exist yet — they are written and reviewed now, activated later once the runbook (also written in this plan) is executed by a human. Nothing in this plan touches the live host; the only host-affecting artifact this plan produces is the runbook text itself.

**Tech Stack:** PHP 8.5 / Laravel 13 (Artisan console command, PHPUnit), GitHub Actions YAML, Markdown runbooks.

**Spec:** `docs/superpowers/specs/2026-09-05-cicd-automation-design.md`

## Global Constraints

- `declare(strict_types=1);` on every new PHP file.
- No SQLite/Postgres/Redis dependency anywhere in this plan's tests — the scanner and command operate on plain files and `git`, nothing touches a database.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` (no path arguments — this repo's `phpstan.neon` scopes `paths: app` only) must stay clean after every task.
- `bash ci/verify-docs.sh` must stay clean after Task 4 and Task 5.
- Never claim a job that cannot execute in this environment has been tested. Tasks 2 and 3 add jobs gated to `[self-hosted, makam-deploy]` — a runner that does not exist until the Task 5 runbook is executed by a human, separately, after this plan's PRs merge. Their own verification is YAML validity + a careful line-by-line diff against the spec, explicitly never a claimed live run.
- The Task 5 runbook must never contain a real secret, token, or password value — only the commands to run and a pointer to where a human obtains the real value, matching every existing runbook in this repo (`deploy-stg-vhost.md`, `deploy-production.md`).
- Every job/command added in this plan follows the spec's four validated constraints exactly (§1.3 of the spec): the deploy jobs' `if:` always includes an explicit ref check, the migration scanner is comment-aware (never raw grep), no Horizon-specific command appears anywhere, and `build-image` gains a real `outputs:` block that `deploy-beta` reads by listing `build-image` directly in its own `needs:` (not only through `deploy-dev`).

---

### Task 1: Destructive-migration gate — scanner, command, tests, and CI wiring

**Files:**
- Create: `app/Support/Migrations/DestructiveMigrationScanner.php`
- Create: `app/Console/Commands/VerifyNoDestructiveMigrationsCommand.php`
- Create: `tests/Unit/Support/Migrations/DestructiveMigrationScannerTest.php`
- Create: `tests/Feature/Console/VerifyNoDestructiveMigrationsCommandTest.php`
- Modify: `.github/workflows/ci.yml` (add `outputs:` to the `build-image` job; add a new `verify-migrations` job)

**Interfaces:**
- Produces: `App\Support\Migrations\DestructiveMigrationScanner::scan(string $path): array` — returns a list of `['line' => int, 'pattern' => string]` violations. Tasks 2/3 do not depend on this class.
- Produces: Artisan command `migrations:verify-no-destructive-changes {base-ref} {--repo=}`, exit code `0` (no violations) or `1` (violations found, printed to stderr via `$this->error()`).
- Produces (CI): `build-image` job gains `outputs: { image, sha_tag, digest }` — Tasks 2 and 3 consume `needs.build-image.outputs.image` / `.digest` / `.sha_tag`.

- [ ] **Step 1: Write the failing scanner tests**

Create `tests/Unit/Support/Migrations/DestructiveMigrationScannerTest.php`:

```php
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

        $this->assertSame([], $violations);
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
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Support/Migrations/DestructiveMigrationScannerTest.php`
Expected: FAIL — `Class "App\Support\Migrations\DestructiveMigrationScanner" not found`

- [ ] **Step 3: Implement the scanner**

Create `app/Support/Migrations/DestructiveMigrationScanner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Migrations;

/**
 * Flags a destructive schema call (dropColumn/dropTable/... ) that appears
 * inside a migration's up() method without an explicit override — see
 * `docs/superpowers/specs/2026-09-05-cicd-automation-design.md` §1.3 and
 * §3.4 for why this exists and why it is comment-aware rather than a raw
 * grep: this codebase's own migrations routinely NAME these calls inside
 * doc-block comments while describing why the migration is safe (e.g.
 * "every dropColumn()/DELETE among them is confined to a down() rollback
 * method, never up()") — a naive text search would flag those comments as
 * violations. Comments are stripped (blanked to spaces, preserving line
 * numbers) before any pattern is searched for.
 */
final class DestructiveMigrationScanner
{
    /** @var list<string> */
    private const DESTRUCTIVE_PATTERNS = [
        'dropColumn',
        'dropTable',
        'dropForeign',
        'dropUnique',
        'dropIndex',
        'DB::delete',
        '->truncate(',
        'DELETE FROM',
        'TRUNCATE',
    ];

    /**
     * @return list<array{line: int, pattern: string}>
     */
    public function scan(string $path): array
    {
        $original = file_get_contents($path);

        if ($original === false) {
            return [];
        }

        $stripped = $this->stripComments($original);
        $upStart = $this->findFunctionOffset($stripped, 'up');

        if ($upStart === null) {
            return [];
        }

        $downStart = $this->findFunctionOffset($stripped, 'down');
        $upBody = $downStart !== null && $downStart > $upStart
            ? substr($stripped, $upStart, $downStart - $upStart)
            : substr($stripped, $upStart);

        $upBodyStartLine = substr_count($stripped, "\n", 0, $upStart) + 1;
        $originalLines = explode("\n", $original);

        $violations = [];

        foreach (explode("\n", $upBody) as $offset => $lineText) {
            foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
                if (! str_contains($lineText, $pattern)) {
                    continue;
                }

                $lineNumber = $upBodyStartLine + $offset;
                $precedingLine = $originalLines[$lineNumber - 2] ?? '';

                if (str_contains($precedingLine, 'contract-approved')) {
                    continue;
                }

                $violations[] = ['line' => $lineNumber, 'pattern' => $pattern];
            }
        }

        return $violations;
    }

    private function findFunctionOffset(string $source, string $name): ?int
    {
        if (preg_match('/function\s+'.$name.'\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $matches[0][1];
    }

    private function stripComments(string $source): string
    {
        $result = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $result .= (string) preg_replace('/[^\n]/', ' ', $token[1]);

                continue;
            }

            $result .= is_array($token) ? $token[1] : $token;
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run the scanner tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Support/Migrations/DestructiveMigrationScannerTest.php`
Expected: PASS — 5 tests, all green.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Migrations/DestructiveMigrationScanner.php \
        tests/Unit/Support/Migrations/DestructiveMigrationScannerTest.php
git commit -m "feat(ci): add comment-aware destructive-migration scanner"
```

- [ ] **Step 6: Write the failing command test**

Create `tests/Feature/Console/VerifyNoDestructiveMigrationsCommandTest.php`:

```php
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
```

- [ ] **Step 7: Run the command test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Console/VerifyNoDestructiveMigrationsCommandTest.php`
Expected: FAIL — `The command "migrations:verify-no-destructive-changes" does not exist.`

- [ ] **Step 8: Implement the command**

Create `app/Console/Commands/VerifyNoDestructiveMigrationsCommand.php`:

```php
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
```

- [ ] **Step 9: Run the command test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Console/VerifyNoDestructiveMigrationsCommandTest.php`
Expected: PASS — 3 tests, all green.

- [ ] **Step 10: Run pint and phpstan**

Run: `vendor/bin/pint --test` — expect clean.
Run: `vendor/bin/phpstan analyse --no-progress` — expect no errors. If phpstan flags the `Process` constructor array-shape or the `$this->option('repo')` nullable-string return, add a narrow inline `@phpstan-ignore` only if genuinely a false positive — do not silence a real type error.

- [ ] **Step 11: Commit**

```bash
git add app/Console/Commands/VerifyNoDestructiveMigrationsCommand.php \
        tests/Feature/Console/VerifyNoDestructiveMigrationsCommandTest.php
git commit -m "feat(ci): add migrations:verify-no-destructive-changes command"
```

- [ ] **Step 12: Wire `build-image`'s new outputs block**

Open `.github/workflows/ci.yml`. Find the `build-image` job (its `name: Build and push image`). It currently ends with the `Record image reference` step and has no top-level `outputs:` key. Add one immediately after the job's `permissions:` block (before `steps:`):

```yaml
  build-image:
    name: Build and push image
    runs-on: ubuntu-latest
    if: github.event_name == 'push'
    needs: [docs-gates, verify-versions, php, frontend, contracts, security-audit]
    permissions:
      contents: read
      packages: write
    outputs:
      image: ${{ steps.meta.outputs.image }}
      sha_tag: ${{ steps.meta.outputs.sha_tag }}
      digest: ${{ steps.build.outputs.digest }}
    steps:
```

(Everything from `steps:` onward is unchanged — only the new `outputs:` block is inserted.)

- [ ] **Step 13: Add the `verify-migrations` job**

In the same file, add a new job. Place it near `docs-gates` (it needs no application boot, same category):

```yaml
  # ---------------------------------------------------------------------------
  # Destructive-migration gate (cicd-automation-design.md §3.4). A required PR
  # status check — the automated replacement for the manual "read every
  # migration before running it" step every deploy has needed by hand so far.
  # ---------------------------------------------------------------------------
  verify-migrations:
    name: Verify no unapproved destructive migrations
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.5"
          tools: composer:v2
          coverage: none
      - name: Install dependencies from lockfile
        run: composer install --no-interaction --prefer-dist --no-progress
      - name: Run the destructive-migration gate
        run: php artisan migrations:verify-no-destructive-changes "origin/${{ github.event.pull_request.base.ref || 'docs/design-system-and-planning' }}"
```

`fetch-depth: 0` is required — the command's own `git diff` needs the full history and the base branch's ref to exist locally, which a shallow (default depth-1) checkout does not provide.

- [ ] **Step 14: Validate the YAML parses**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"`
Expected: no output, exit code 0 (a parse error would raise and print a traceback).

- [ ] **Step 15: Run `ci/verify-docs.sh`**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS` (this file lives under `.github/`, not scanned by the design-token gates, but the YAML-parse gate in that script covers it too — confirm it still passes).

- [ ] **Step 16: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "feat(ci): wire build-image outputs and the verify-migrations job"
```

---

### Task 2: `deploy-dev` job

**Files:**
- Modify: `.github/workflows/ci.yml` (append a new `deploy-dev` job after `build-image`)

**Interfaces:**
- Consumes: `needs.build-image.outputs.image` / `.digest` (Task 1).
- Produces: nothing consumed by Task 1; Task 3 (`deploy-beta`) consumes `needs.deploy-dev` (as a `needs:` entry, not its outputs — `deploy-dev` produces no outputs of its own).

- [ ] **Step 1: Add the `deploy-dev` job**

Append to `.github/workflows/ci.yml`, after `build-image`:

```yaml
  # ---------------------------------------------------------------------------
  # Deploy to dev.makam.co.id (cicd-automation-design.md §3.2). Runs on the
  # self-hosted runner living on this same host (docs/operations/runbooks/
  # setup-cicd-self-hosted-runner.md — NOT yet executed as of this job's
  # addition; this job cannot run for real until that runbook is). Gated
  # explicitly on the real trunk ref — deliberately NOT inherited from
  # build-image's own `if: github.event_name == 'push'`, which has no ref
  # check at all and would otherwise deploy on every branch push (spec §1.3
  # finding 1).
  # ---------------------------------------------------------------------------
  deploy-dev:
    name: Deploy to dev.makam.co.id
    needs: [build-image]
    if: github.event_name == 'push' && github.ref == 'refs/heads/docs/design-system-and-planning'
    runs-on: [self-hosted, makam-deploy]
    concurrency:
      group: makam-deploy
      cancel-in-progress: false
    steps:
      - name: Promote the artifact to dev
        working-directory: /opt/makam/compose
        run: |
          export APP_IMAGE="${{ needs.build-image.outputs.image }}@${{ needs.build-image.outputs.digest }}"
          docker compose up -d dev-web
          git add -A
          git commit -m "deploy(dev): ${{ needs.build-image.outputs.sha_tag }}" || true

      - name: Run dev migrations
        working-directory: /opt/makam/compose
        run: docker compose exec -T dev-web php artisan migrate --force

      - name: Smoke-test dev
        run: |
          for i in $(seq 1 30); do
            curl -sf https://dev.makam.co.id/health/live > /dev/null && break
            sleep 2
          done
          curl -sf https://dev.makam.co.id/health/live
          curl -sf https://dev.makam.co.id/health/ready
          code=$(curl -sf -o /dev/null -w '%{http_code}' https://dev.makam.co.id/)
          [ "$code" = "200" ] || { echo "dev homepage returned $code, expected 200"; exit 1; }
```

No worker/scheduler restart step — `dev-web` is the only container in the `dev` environment (spec §1.2, matches `dev-staging-environment.md` §9: development has no persistent Horizon/scheduler).

- [ ] **Step 2: Validate the YAML parses**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"`
Expected: no output, exit code 0.

- [ ] **Step 3: Review against the spec — this job cannot be executed yet**

This job targets `runs-on: [self-hosted, makam-deploy]`, a runner that does not exist until `docs/operations/runbooks/setup-cicd-self-hosted-runner.md` (Task 5) is executed by a human, separately, after this plan's PRs merge. Do not attempt to run it and do not report any execution result for it. Verification for this task is: (a) the YAML parses (Step 2), (b) a line-by-line comparison against spec §3.2 confirming every constraint from spec §1.3 is present — the explicit ref check, the correct `needs:`, no Horizon command, the exact `/health/live`/`/health/ready`/homepage checks. Record this comparison in the task report explicitly as a reading-based review, not a run.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "feat(ci): add deploy-dev job (not yet activated — runner does not exist)"
```

---

### Task 3: `deploy-beta` job

**Files:**
- Modify: `.github/workflows/ci.yml` (append a new `deploy-beta` job after `deploy-dev`)

**Interfaces:**
- Consumes: `needs.build-image.outputs.image` / `.digest` (Task 1) — listed directly in this job's own `needs:`, not only reached through `deploy-dev` (job outputs are not transitive in GitHub Actions — spec §1.3 finding 4).
- Consumes: `needs.deploy-dev` as a `needs:` entry (sequencing only; `deploy-dev` has no outputs of its own).

- [ ] **Step 1: Add the `deploy-beta` job**

Append to `.github/workflows/ci.yml`, after `deploy-dev`:

```yaml
  # ---------------------------------------------------------------------------
  # Promote the identical digest to makam.co.id (cicd-automation-design.md
  # §3.3). `beta` is real, live production traffic (ADR-0027's "production
  # graduation" decision; confirmed live during this design's own validation
  # pass — https://makam.co.id/health/live already serves this exact stack).
  # `needs` lists BOTH build-image and deploy-dev explicitly: GitHub Actions
  # job outputs are not transitive, so `needs.build-image.outputs.*` would be
  # unavailable here if only `deploy-dev` were listed (spec §1.3 finding 4).
  # No `horizon:terminate`/`horizon:pause-supervisor` anywhere below —
  # beta-worker runs plain `php artisan queue:work`, not Horizon (confirmed
  # against the real compose.yml; several docs, including
  # ci-cd-and-release.md §5 and deploy-production.md, describe a
  # Horizon-based restart that does not apply to what is actually deployed —
  # spec §1.3 finding 3). `docker compose up -d` alone is sufficient: queue:
  # work's own SIGTERM handler finishes its in-flight job within the
  # already-configured `stop_grace_period: 90s` before the container is
  # recreated.
  # ---------------------------------------------------------------------------
  deploy-beta:
    name: Promote to makam.co.id (beta/production)
    needs: [build-image, deploy-dev]
    if: github.event_name == 'push' && github.ref == 'refs/heads/docs/design-system-and-planning'
    runs-on: [self-hosted, makam-deploy]
    concurrency:
      group: makam-deploy
      cancel-in-progress: false
    steps:
      - name: Promote the same artifact to beta
        working-directory: /opt/makam/compose
        run: |
          export APP_IMAGE="${{ needs.build-image.outputs.image }}@${{ needs.build-image.outputs.digest }}"
          docker compose up -d beta-web beta-worker beta-scheduler
          git add -A
          git commit -m "deploy(beta): ${{ needs.build-image.outputs.sha_tag }}" || true

      - name: Run beta migrations
        working-directory: /opt/makam/compose
        run: docker compose exec -T beta-web php artisan migrate --force

      - name: Smoke-test beta (live production domain)
        run: |
          for i in $(seq 1 30); do
            curl -sf https://makam.co.id/health/live > /dev/null && break
            sleep 2
          done
          curl -sf https://makam.co.id/health/live
          curl -sf https://makam.co.id/health/ready
          code=$(curl -sf -o /dev/null -w '%{http_code}' https://makam.co.id/)
          [ "$code" = "200" ] || { echo "beta homepage returned $code, expected 200"; exit 1; }
```

- [ ] **Step 2: Validate the YAML parses**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"`
Expected: no output, exit code 0.

- [ ] **Step 3: Review against the spec — this job cannot be executed yet**

Same caveat as Task 2 Step 3: this job cannot run until the Task 5 runbook is executed. Verify by reading, against spec §3.3: `needs: [build-image, deploy-dev]` present with both entries, the ref check present, no Horizon command anywhere in the diff, migrations run against `beta-web` specifically, and the smoke test targets the real `makam.co.id` domain (not a `beta.makam.co.id` subdomain — confirmed in the spec that beta is served at the live apex).

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "feat(ci): add deploy-beta job (not yet activated — runner does not exist)"
```

---

### Task 4: Documentation updates — describe the pipeline honestly as implemented-but-pending-activation

**Files:**
- Modify: `docs/operations/dev-staging-environment.md` (§10)
- Modify: `docs/operations/deployment.md` (§5)
- Modify: `docs/operations/ci-cd-and-release.md` (§5.1 area / §8)

**Interfaces:** None — documentation only, no code interfaces produced or consumed.

- [ ] **Step 1: Update `dev-staging-environment.md` §10**

Find the section starting `## 10. Build and deployment`. Immediately after the existing fenced `Git push -> ... -> staging smoke/UAT checks` block, add:

```markdown
> **Updated <date this task is executed> — automated via CI.** The sequence
> above is now implemented as `deploy-dev`/`deploy-beta` jobs in
> `.github/workflows/ci.yml` (`docs/superpowers/specs/2026-09-05-cicd-
> automation-design.md`), running on a self-hosted GitHub Actions runner on
> this same host once `docs/operations/runbooks/setup-cicd-self-hosted-
> runner.md` has been executed. **As of this note, that runbook has not yet
> been executed** — the jobs exist in `ci.yml` but cannot run until the
> runner is registered. Do not assume this pipeline is live without checking
> whether that runbook's own completion evidence has been recorded. Once
> active: a push to `docs/design-system-and-planning` deploys to dev, smoke-
> tests it, and — only if that passes — automatically promotes the identical
> digest to beta and smoke-tests it too, with no human approval step in
> between (an explicit decision, matching the same self-hosted-runner model
> `galangdana`, an unrelated project on this host, already uses in
> production). A failed smoke test fails the job and stops; nothing rolls
> back automatically (see `runbooks/rollback-deploy.md`).
```

- [ ] **Step 2: Update `deployment.md` §5**

Find `## 5. Non-production deployment procedure`. After its existing numbered list (ending "8. Observe host memory..."), add:

```markdown
> **Updated <date this task is executed>.** Steps 2-7 above are now
> automated by `deploy-dev`/`deploy-beta` in `.github/workflows/ci.yml` —
> see `dev-staging-environment.md` §10's own note for the same caveat: the
> jobs exist but cannot run for real until `docs/operations/runbooks/setup-
> cicd-self-hosted-runner.md` has been executed. This procedure's steps
> remain the correct manual fallback if the automated pipeline is ever
> unavailable or intentionally bypassed.
```

- [ ] **Step 3: Update `ci-cd-and-release.md` §8**

Find `## 8. Deployment checks`. After its existing bullet list, add:

```markdown
- Since `docs/superpowers/specs/2026-09-05-cicd-automation-design.md`: the
  first four bullets above (`/health/live`, `/health/ready`, homepage) run
  automatically as part of `deploy-dev`/`deploy-beta`'s own smoke-test
  steps once the self-hosted runner is active (see
  `dev-staging-environment.md` §10's note on activation status). The
  remaining bullets — authenticated Filament smoke checks, outbox/queue
  confirmation, provider-sandbox webhook checks, and §5.1 manual-step
  confirmation — are not automated by this pipeline and remain a human's
  responsibility after an automated deploy, exactly as after a manual one.
```

- [ ] **Step 4: Run `ci/verify-docs.sh`**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`. Pay particular attention to GATE 4 (markdown link resolution) and GATE 7 (traceability rows naming real test files) — this task adds no new links or traceability rows, but confirm the gate still reports the same pass state as before this task's edits.

- [ ] **Step 5: Commit**

```bash
git add docs/operations/dev-staging-environment.md \
        docs/operations/deployment.md \
        docs/operations/ci-cd-and-release.md
git commit -m "docs(ops): describe the new automated deploy pipeline, pending activation"
```

---

### Task 5: Host-setup runbook

**Files:**
- Create: `docs/operations/runbooks/setup-cicd-self-hosted-runner.md`

**Interfaces:** None — a standalone document. Its own accuracy is checked against the spec (`docs/superpowers/specs/2026-09-05-cicd-automation-design.md` §4.2) and the real host facts already recorded there, not against any code interface.

- [ ] **Step 1: Write the runbook**

Create `docs/operations/runbooks/setup-cicd-self-hosted-runner.md`:

```markdown
# Runbook: Activate the makam-app CI/CD Self-Hosted Deploy Pipeline — v0.1

## Status

**Prepared, not executed.** This runbook is a human-executed artifact, following the same convention as `deploy-stg-vhost.md`/`deploy-production.md`. No command in this document has been run by the preparing agent. It has no execution access to host secrets, GitHub's runner-registration token flow, or sudo. **Do not execute this runbook without a human operator present** — every step below either handles a short-lived credential that must never reach chat/logs, or touches the live `/opt/makam/compose/compose.yml` backing real running production containers.

## Scope

Activate the `deploy-dev`/`deploy-beta` jobs added to `.github/workflows/ci.yml` by `docs/superpowers/plans/2026-09-05-cicd-automation.md` Tasks 2-3. Those jobs target `runs-on: [self-hosted, makam-deploy]` — a runner that does not exist until this runbook is executed. This runbook does not touch application code; it is entirely host/GitHub-repo-setting work.

Related documents:
- `docs/superpowers/specs/2026-09-05-cicd-automation-design.md` §4.2 — the decisions this runbook instantiates
- `docs/operations/dev-staging-environment.md` §10 and `docs/operations/deployment.md` §5 — updated to describe the pipeline this runbook activates
- `docs/operations/runbooks/rollback-deploy.md` — unchanged; still the manual procedure for a bad deploy, automated or not

## Preconditions — do not proceed until all are true

1. **Tasks 1-4 of the implementation plan are merged** to `docs/design-system-and-planning` — `verify-migrations`, `deploy-dev`, `deploy-beta` all exist in the real `.github/workflows/ci.yml` on that branch.
2. **A human operator is present** for the entire runbook, not just the steps that say so explicitly.

## Step 1 — Settle the `.env.dev`/`.env.beta` permission question

The design's own validation pass found a real, unresolved contradiction: `/opt/makam/compose/compose.yml`'s comment on `dev-web` claims `.env.dev`/`.env.beta` are root:root mode 0400 ("`docker compose` commands touching this service need sudo -n to read it"), but an unprivileged `docker compose config` run during that validation succeeded with no sudo and no permission error. Settle this before deciding whether Step 4 is needed at all:

```bash
sudo stat -c '%U:%G %a' /opt/makam/compose/.env.dev
sudo stat -c '%U:%G %a' /opt/makam/compose/.env.beta
```

If both report an owner/mode the `ubuntu` user (or whichever account will run the self-hosted runner) can already read, skip Step 4 entirely and note that in this runbook's evidence record (Step 8). If either is genuinely unreadable by that account, Step 4 is required.

## Step 2 — Generate the runner registration token and register the runner

In GitHub: **Settings → Actions → Runners → New self-hosted runner**, for `andrianm28/makam-app`. Copy the registration command GitHub shows — it includes a short-lived token. Run it yourself, directly (via `!` in an interactive session, or your own terminal) — **never paste that token into a tool call or any AI session's input**, since it would then appear in that session's logs.

```bash
mkdir -p /home/ubuntu/actions-runner-makam-app
cd /home/ubuntu/actions-runner-makam-app
curl -o actions-runner-linux-x64.tar.gz -L <the tarball URL GitHub's own page shows>
tar xzf actions-runner-linux-x64.tar.gz
./config.sh --url https://github.com/andrianm28/makam-app --token <the token GitHub's own page shows> --labels makam-deploy --name makam-host-runner
```

Use the same tarball version and directory-naming convention already proven working for `andrianm28/galangdana` on this same host (`/home/ubuntu/actions-runner-galangdana`) — mirror it exactly for `makam-app`, just with its own directory and labels.

## Step 3 — Install the runner as a systemd service

```bash
cd /home/ubuntu/actions-runner-makam-app
sudo ./svc.sh install ubuntu
sudo ./svc.sh start
sudo systemctl status actions.runner.andrianm28-makam-app.makam-host-runner.service
```

Confirm it shows `enabled`/`active (running)` — matching galangdana's own real, working `actions.runner.andrianm28-galangdana.galangdana-host-runner.service` exactly in shape, just for this repo.

## Step 4 — Add a sudoers grant, ONLY if Step 1 showed it's needed

Skip this step entirely if Step 1's `stat` output showed the runner's user can already read both env files. If not, add a narrowly-scoped rule — a human edits this file directly, never an AI agent:

```bash
sudo visudo -f /etc/sudoers.d/makam-deploy-runner
```

Contents (narrowest working scope — confirm `docker compose` really needs no other sudo-gated action first):

```text
ubuntu ALL=(root) NOPASSWD: /usr/bin/docker compose -f /opt/makam/compose/compose.yml *
```

## Step 5 — Bring `/opt/makam/compose` under git and refactor to `${APP_IMAGE}`

Take a real backup first, matching the existing convention one last time:

```bash
cd /opt/makam/compose
cp compose.yml compose.yml.bak-$(date +%Y%m%d%H%M%S)-pre-git-init
git init
git add -A
git commit -m "chore: bring compose.yml under version control (was hand-timestamped .bak copies)"
```

Then edit `compose.yml`: for each of `dev-web`, `beta-web`, `beta-worker`, `beta-scheduler`, replace the hardcoded `image: ghcr.io/andrianm28/makam-app@sha256:...` line (each currently preceded by a long inline comment recording that promotion's history) with:

```yaml
    image: ${APP_IMAGE:?APP_IMAGE is required}
```

Keep each service's own large historical comment block above the `image:` line as-is — it is now the last entry in that inline history; all future promotions are recorded as real git commits instead (per `deploy-dev`/`deploy-beta`'s own `git commit` step). Validate before touching any running container:

```bash
export APP_IMAGE="$(docker inspect --format='{{.Config.Image}}' $(docker compose ps -q dev-web))"
docker compose config > /tmp/compose-config-check.yml
diff <(docker compose config) /tmp/compose-config-check.yml  # sanity: config is stable/idempotent
```

Confirm the rendered config's `dev-web`/`beta-web`/`beta-worker`/`beta-scheduler` image references match what is currently actually running (via `docker compose ps` / `docker inspect`) before committing:

```bash
git add compose.yml
git commit -m "refactor: parameterize image digests via \${APP_IMAGE} (cicd-automation-design.md decision 3)"
```

## Step 6 — Branch protection on `docs/design-system-and-planning`

```bash
gh api --method PUT repos/andrianm28/makam-app/branches/docs%2Fdesign-system-and-planning/protection \
  -f 'required_status_checks[strict]=true' \
  -f 'required_status_checks[contexts][]=Docs and design gates' \
  -f 'required_status_checks[contexts][]=Verify pinned runtime versions' \
  -f 'required_status_checks[contexts][]=PHP (validate, lint, analyse, test)' \
  -f 'required_status_checks[contexts][]=Frontend build' \
  -f 'required_status_checks[contexts][]=OpenAPI and YAML validation' \
  -f 'required_status_checks[contexts][]=Dependency audit' \
  -f 'required_status_checks[contexts][]=Browser + a11y smoke test (Playwright)' \
  -f 'required_status_checks[contexts][]=Load and performance benchmarks (k6, AC4)' \
  -f 'required_status_checks[contexts][]=Verify no unapproved destructive migrations' \
  -f 'required_pull_request_reviews[required_approving_review_count]=1' \
  -F 'enforce_admins=true' \
  -F 'restrictions=null'
```

Confirm afterward:

```bash
gh api repos/andrianm28/makam-app/branches/docs%2Fdesign-system-and-planning/protection
```

## Step 7 — First real activation

Merge any small PR to `docs/design-system-and-planning` (or push a trivial change directly, if branch protection from Step 6 permits a final unprotected test push before it's confirmed active) and watch the real run:

```bash
gh run list --branch docs/design-system-and-planning --limit 1
gh run watch
```

Confirm `deploy-dev` and `deploy-beta` both appear and complete, and that `https://dev.makam.co.id/health/live` and `https://makam.co.id/health/live` both reflect the newly deployed digest afterward.

## Step 8 — Record evidence and close this runbook

Record: Step 1's real permission-check output (and whether Step 4 was needed), the registered runner's name/labels, `compose.yml`'s first real git commit hash, Step 6's confirmed protection settings, and Step 7's first real run URL. Update this runbook's own Status line from "Prepared, not executed" only after Step 7 has genuinely completed — not before.

## Rollback

Activating this runbook does not itself require a rollback procedure — it only adds automation around an already-existing manual process. If `deploy-dev`/`deploy-beta`'s first real run (Step 7) causes a bad deploy, use the existing `docs/operations/runbooks/rollback-deploy.md` exactly as if the bad deploy had been manual — the mechanism it rolls back (`APP_IMAGE` re-pin) is unchanged by this runbook.
```

- [ ] **Step 2: Run `ci/verify-docs.sh`**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`. This new file references `docs/superpowers/specs/2026-09-05-cicd-automation-design.md` and several existing docs by relative path — confirm GATE 4 (link resolution) passes for all of them.

- [ ] **Step 3: Verify no secret value was written**

Read the file back and confirm no line contains a real token, password, or credential — only commands and placeholders like `<the token GitHub's own page shows>`. This is a manual read, not an automated check; record it explicitly in the task report.

- [ ] **Step 4: Commit**

```bash
git add docs/operations/runbooks/setup-cicd-self-hosted-runner.md
git commit -m "docs(ops): add the CI/CD self-hosted-runner activation runbook"
```
