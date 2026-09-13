<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault;

use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Adapters\MockScanner;
use App\Platform\DocumentVault\Contracts\MalwareScanner;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Providers\DocumentVaultServiceProvider;
use Illuminate\Support\Env;
use LogicException;
use Tests\TestCase;

final class DocumentVaultConfigurationTest extends TestCase
{
    public function test_development_defaults_are_available_for_application_flow_tests(): void
    {
        $this->assertSame(LocalFilesystemObjectStorage::class, config('document-vault.object_storage'));
        $this->assertSame(MockScanner::class, config('document-vault.malware_scanner'));
    }

    public function test_the_private_local_disk_is_not_served_by_generic_storage_routes(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }

    /**
     * STAT-02: this originally tried `Illuminate\Support\Env::getRepository()
     * ->set()/clear()` (the "cleaner" option the finding offered) instead of
     * touching getenv()/$_ENV/$_SERVER by hand. That API exists in this
     * Laravel version, but does NOT work for this purpose: phpdotenv builds
     * that repository `->immutable()` (`Env::getRepository()`'s own source),
     * and `ImmutableWriter::write()`/`delete()` explicitly refuse to
     * overwrite or clear a variable that is "externally defined" — which
     * `APP_ENV` always is here, since PHPUnit's own `<env name="APP_ENV"
     * value="testing"/>` sets it as a real process environment variable
     * before this test ever runs. Confirmed empirically: `->set('APP_ENV',
     * 'staging')` silently returns without changing anything readable
     * through `env()`, so both tests below fell back to the OTHER option
     * the finding named — writing getenv()/$_ENV/$_SERVER directly, all
     * three, together, in one try/finally, restored the same way. See
     * `test_a_value_set_only_via_server_super_global_is_picked_up_by_the_staging_gate()`
     * below for where `Env::getRepository()` DOES help: reading (not
     * writing) a value, which is not subject to the immutable-writer
     * restriction.
     */
    public function test_staging_defaults_do_not_silently_select_local_storage_and_mock_scanning(): void
    {
        $original = [
            'APP_ENV' => [getenv('APP_ENV'), $_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null],
            'DOCUMENT_VAULT_OBJECT_STORAGE' => [getenv('DOCUMENT_VAULT_OBJECT_STORAGE'), $_ENV['DOCUMENT_VAULT_OBJECT_STORAGE'] ?? null, $_SERVER['DOCUMENT_VAULT_OBJECT_STORAGE'] ?? null],
            'DOCUMENT_VAULT_MALWARE_SCANNER' => [getenv('DOCUMENT_VAULT_MALWARE_SCANNER'), $_ENV['DOCUMENT_VAULT_MALWARE_SCANNER'] ?? null, $_SERVER['DOCUMENT_VAULT_MALWARE_SCANNER'] ?? null],
        ];

        $this->setAcrossAllThreeEnvSources('APP_ENV', 'staging');
        $this->clearAcrossAllThreeEnvSources('DOCUMENT_VAULT_OBJECT_STORAGE');
        $this->clearAcrossAllThreeEnvSources('DOCUMENT_VAULT_MALWARE_SCANNER');

        try {
            $configuration = require base_path('config/document-vault.php');

            $this->assertNull($configuration['object_storage']);
            $this->assertNull($configuration['malware_scanner']);
        } finally {
            $this->restoreAcrossAllThreeEnvSources($original);
        }
    }

    public function test_staging_rejects_an_explicit_mock_scanner_configuration(): void
    {
        $original = [
            'APP_ENV' => [getenv('APP_ENV'), $_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null],
            'DOCUMENT_VAULT_MALWARE_SCANNER' => [getenv('DOCUMENT_VAULT_MALWARE_SCANNER'), $_ENV['DOCUMENT_VAULT_MALWARE_SCANNER'] ?? null, $_SERVER['DOCUMENT_VAULT_MALWARE_SCANNER'] ?? null],
        ];

        $this->setAcrossAllThreeEnvSources('APP_ENV', 'staging');
        $this->setAcrossAllThreeEnvSources('DOCUMENT_VAULT_MALWARE_SCANNER', MockScanner::class);

        try {
            $configuration = require base_path('config/document-vault.php');

            $this->assertNull($configuration['malware_scanner']);
        } finally {
            $this->restoreAcrossAllThreeEnvSources($original);
        }
    }

    /**
     * Overrides `$name` via getenv/putenv, `$_ENV`, and `$_SERVER` together
     * — the three-way write STAT-02 requires so a real staging host's env
     * var, however it arrives, is exercised by this test.
     */
    private function setAcrossAllThreeEnvSources(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function clearAcrossAllThreeEnvSources(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    /**
     * @param  array<string, array{0: string|false, 1: string|null, 2: string|null}>  $original
     */
    private function restoreAcrossAllThreeEnvSources(array $original): void
    {
        foreach ($original as $name => [$getenvValue, $envValue, $serverValue]) {
            if ($getenvValue === false) {
                putenv($name);
            } else {
                putenv("{$name}={$getenvValue}");
            }

            if ($envValue === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $envValue;
            }

            if ($serverValue === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $serverValue;
            }
        }
    }

    /**
     * Regression for STAT-02: the fail-closed staging gate above is only a
     * real guarantee if it also catches a value that arrives via $_SERVER
     * alone — the shape a real staging host can hand PHP-FPM (an
     * `env[...]` pool directive, or a webserver `fastcgi_param`) without
     * ever calling `putenv()` or populating `$_ENV`. Before this test
     * existed, the suite only ever exercised getenv()/$_ENV overrides, so a
     * config that silently trusted `getenv()`/`$_ENV` alone (and skipped
     * `Illuminate\Support\Env`, which also reads `$_SERVER` via
     * `ServerConstAdapter`) could pass every other test here while still
     * failing open in that real deployment shape.
     */
    public function test_a_value_set_only_via_server_super_global_is_picked_up_by_the_staging_gate(): void
    {
        $repository = Env::getRepository();

        $original = [
            'APP_ENV' => $_SERVER['APP_ENV'] ?? null,
            'DOCUMENT_VAULT_MALWARE_SCANNER' => $_SERVER['DOCUMENT_VAULT_MALWARE_SCANNER'] ?? null,
        ];

        // Bypass putenv()/$_ENV entirely: write $_SERVER directly, the way a
        // webserver/FPM pool would, without going through Env::set().
        $_SERVER['APP_ENV'] = 'staging';
        $_SERVER['DOCUMENT_VAULT_MALWARE_SCANNER'] = MockScanner::class;

        $this->assertFalse(
            getenv('DOCUMENT_VAULT_MALWARE_SCANNER'),
            'Precondition: this value must not also be visible via getenv(), or the test would not prove the $_SERVER-only path.'
        );
        $this->assertArrayNotHasKey(
            'DOCUMENT_VAULT_MALWARE_SCANNER',
            $_ENV,
            'Precondition: this value must not also be visible via $_ENV, or the test would not prove the $_SERVER-only path.'
        );

        try {
            $this->assertSame(
                'staging',
                $repository->get('APP_ENV'),
                'Illuminate\Support\Env must resolve a $_SERVER-only value.'
            );

            $configuration = require base_path('config/document-vault.php');

            $this->assertNull($configuration['malware_scanner']);
        } finally {
            foreach ($original as $name => $value) {
                if ($value === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $value;
                }
            }
        }
    }

    public function test_provider_registration_survives_bootstrap_without_configured_providers(): void
    {
        config([
            'document-vault.object_storage' => null,
            'document-vault.malware_scanner' => null,
        ]);

        (new DocumentVaultServiceProvider($this->app))->register();

        $this->assertTrue($this->app->bound(ObjectStorage::class));
        $this->assertTrue($this->app->bound(MalwareScanner::class));
    }

    public function test_provider_fails_closed_when_a_provider_is_not_configured(): void
    {
        config([
            'document-vault.object_storage' => null,
            'document-vault.malware_scanner' => null,
        ]);

        (new DocumentVaultServiceProvider($this->app))->register();

        $this->assertThrows(fn (): mixed => $this->app->make(ObjectStorage::class), LogicException::class);
        $this->assertThrows(fn (): mixed => $this->app->make(MalwareScanner::class), LogicException::class);
    }
}
