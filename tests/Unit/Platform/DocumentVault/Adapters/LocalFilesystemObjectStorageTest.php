<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Adapters;

use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Exceptions\ObjectStorageException;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LocalFilesystemObjectStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway root per test — never the real dev storage/app/private
        // tree — so this suite cannot leave documents behind on the host.
        $this->root = sys_get_temp_dir().'/document-vault-test-'.Str::random(12);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_put_writes_the_full_stream_content_under_the_private_root(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);
        $stream = $this->streamFor('quarantine content');

        $storage->put('KTP/quarantine/key-1', $stream);

        $this->assertSame('quarantine content', file_get_contents($this->root.'/KTP/quarantine/key-1'));
    }

    public function test_put_creates_intermediate_directories(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);
        $stream = $this->streamFor('nested content');

        $storage->put('GRAVE_IMPORT/quarantine/deep/key-2', $stream);

        $this->assertFileExists($this->root.'/GRAVE_IMPORT/quarantine/deep/key-2');
    }

    public function test_copy_then_delete_round_trip_moves_a_document_from_quarantine_to_accepted(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);
        $storage->put('KTP/quarantine/key-3', $this->streamFor('promoted content'));

        $storage->copy('KTP/quarantine/key-3', 'KTP/accepted/key-3');

        $this->assertFileExists($this->root.'/KTP/quarantine/key-3');
        $this->assertFileExists($this->root.'/KTP/accepted/key-3');
        $this->assertSame('promoted content', file_get_contents($this->root.'/KTP/accepted/key-3'));

        $storage->delete('KTP/quarantine/key-3');

        $this->assertFileDoesNotExist($this->root.'/KTP/quarantine/key-3');
        $this->assertFileExists($this->root.'/KTP/accepted/key-3');
    }

    public function test_copy_of_a_missing_source_throws(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $this->expectException(ObjectStorageException::class);

        $storage->copy('KTP/quarantine/does-not-exist', 'KTP/accepted/does-not-exist');
    }

    public function test_delete_of_a_missing_path_throws(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $this->expectException(ObjectStorageException::class);

        $storage->delete('KTP/quarantine/does-not-exist');
    }

    public function test_temporary_url_resolves_to_the_literal_private_download_path(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $url = $storage->temporaryUrl('11111111-1111-1111-1111-111111111111', 'opaque-grant-token');

        $this->assertStringEndsWith(
            '/internal/documents/11111111-1111-1111-1111-111111111111/download/opaque-grant-token',
            $url,
        );
    }

    public function test_default_root_is_the_private_storage_documents_directory(): void
    {
        $storage = new LocalFilesystemObjectStorage;

        $storage->put('KTP/quarantine/default-root-key', $this->streamFor('default root content'));

        try {
            $this->assertFileExists(storage_path('app/private/documents/KTP/quarantine/default-root-key'));
        } finally {
            @unlink(storage_path('app/private/documents/KTP/quarantine/default-root-key'));
            @rmdir(storage_path('app/private/documents/KTP/quarantine'));
            @rmdir(storage_path('app/private/documents/KTP'));
        }
    }

    /**
     * @return resource
     */
    private function streamFor(string $content)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
