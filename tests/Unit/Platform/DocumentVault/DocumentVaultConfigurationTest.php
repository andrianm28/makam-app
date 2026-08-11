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

    public function test_staging_defaults_do_not_silently_select_local_storage_and_mock_scanning(): void
    {
        $variables = [
            'APP_ENV' => getenv('APP_ENV'),
            'DOCUMENT_VAULT_OBJECT_STORAGE' => getenv('DOCUMENT_VAULT_OBJECT_STORAGE'),
            'DOCUMENT_VAULT_MALWARE_SCANNER' => getenv('DOCUMENT_VAULT_MALWARE_SCANNER'),
        ];
        $serverVariables = [
            'APP_ENV' => $_ENV['APP_ENV'] ?? null,
            'DOCUMENT_VAULT_OBJECT_STORAGE' => $_ENV['DOCUMENT_VAULT_OBJECT_STORAGE'] ?? null,
            'DOCUMENT_VAULT_MALWARE_SCANNER' => $_ENV['DOCUMENT_VAULT_MALWARE_SCANNER'] ?? null,
        ];

        putenv('APP_ENV=staging');
        putenv('DOCUMENT_VAULT_OBJECT_STORAGE');
        putenv('DOCUMENT_VAULT_MALWARE_SCANNER');
        $_ENV['APP_ENV'] = 'staging';
        unset($_ENV['DOCUMENT_VAULT_OBJECT_STORAGE'], $_ENV['DOCUMENT_VAULT_MALWARE_SCANNER']);
        Env::enablePutenv();

        try {
            $configuration = require base_path('config/document-vault.php');

            $this->assertNull($configuration['object_storage']);
            $this->assertNull($configuration['malware_scanner']);
        } finally {
            foreach ($variables as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv("{$name}={$value}");
                }
            }

            foreach ($serverVariables as $name => $value) {
                if ($value === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $value;
                }
            }

            Env::enablePutenv();
        }
    }

    public function test_staging_rejects_an_explicit_mock_scanner_configuration(): void
    {
        $variables = [
            'APP_ENV' => getenv('APP_ENV'),
            'DOCUMENT_VAULT_OBJECT_STORAGE' => getenv('DOCUMENT_VAULT_OBJECT_STORAGE'),
            'DOCUMENT_VAULT_MALWARE_SCANNER' => getenv('DOCUMENT_VAULT_MALWARE_SCANNER'),
        ];
        $serverVariables = [
            'APP_ENV' => $_ENV['APP_ENV'] ?? null,
            'DOCUMENT_VAULT_OBJECT_STORAGE' => $_ENV['DOCUMENT_VAULT_OBJECT_STORAGE'] ?? null,
            'DOCUMENT_VAULT_MALWARE_SCANNER' => $_ENV['DOCUMENT_VAULT_MALWARE_SCANNER'] ?? null,
        ];

        putenv('APP_ENV=staging');
        putenv('DOCUMENT_VAULT_MALWARE_SCANNER='.MockScanner::class);
        $_ENV['APP_ENV'] = 'staging';
        $_ENV['DOCUMENT_VAULT_MALWARE_SCANNER'] = MockScanner::class;
        Env::enablePutenv();

        try {
            $configuration = require base_path('config/document-vault.php');

            $this->assertNull($configuration['malware_scanner']);
        } finally {
            foreach ($variables as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv("{$name}={$value}");
                }
            }

            foreach ($serverVariables as $name => $value) {
                if ($value === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $value;
                }
            }

            Env::enablePutenv();
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
