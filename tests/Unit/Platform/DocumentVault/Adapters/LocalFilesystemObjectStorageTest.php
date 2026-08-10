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

    public function test_put_refuses_a_path_naming_the_accepted_prefix(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $this->expectException(ObjectStorageException::class);

        try {
            $storage->put('KTP/accepted/forged-key', $this->streamFor('forged content'));
        } finally {
            $this->assertFileDoesNotExist(
                $this->root.'/KTP/accepted/forged-key',
                'put() must never place a file under the accepted/ prefix.',
            );
        }
    }

    public function test_put_refuses_a_path_with_accepted_as_a_nested_segment(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $this->expectException(ObjectStorageException::class);

        $storage->put('KTP/quarantine/accepted/forged-key', $this->streamFor('forged content'));
    }

    public function test_put_refuses_a_traversal_path(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        // 'KTP/quarantine/../../../../etc/passwd' normalizes (2 segments
        // down, 4 '..' up) to two levels above $this->root, i.e. a real
        // writable path outside the intended sandbox — this is the exact
        // path that, before this fix, actually wrote a file to
        // /home/ubuntu/.tmp/etc/passwd on this host.
        $escapedPath = dirname($this->root, 2).'/etc/passwd';

        $this->expectException(ObjectStorageException::class);

        try {
            $storage->put('KTP/quarantine/../../../../etc/passwd', $this->streamFor('malicious content'));
        } finally {
            $this->assertFileDoesNotExist(
                $escapedPath,
                'put() must never resolve a traversal path to a location outside its configured root.',
            );

            @unlink($escapedPath);
            @rmdir(dirname($escapedPath));
        }
    }

    public function test_put_refuses_an_absolute_path(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $this->expectException(ObjectStorageException::class);

        $storage->put('/etc/passwd', $this->streamFor('malicious content'));
    }

    public function test_put_refuses_a_path_with_an_empty_segment(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $this->expectException(ObjectStorageException::class);

        $storage->put('KTP//quarantine/key', $this->streamFor('content'));
    }

    public function test_copy_refuses_a_traversal_source_path(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $this->expectException(ObjectStorageException::class);

        $storage->copy('../../../../etc/passwd', 'KTP/accepted/key');
    }

    public function test_copy_refuses_a_traversal_destination_path(): void
    {
        // Deliberately not asserting on a filesystem side effect here: with
        // 4 '..' segments this particular destination normalizes to the
        // real host /etc/passwd, which this test must not touch even to
        // check for its absence. expectException() alone is the assertion
        // — copy() must reject the path before ever calling the filesystem
        // `copy()` function with it.
        $storage = new LocalFilesystemObjectStorage($this->root);
        $storage->put('KTP/quarantine/key-traversal', $this->streamFor('content'));

        $this->expectException(ObjectStorageException::class);

        $storage->copy('KTP/quarantine/key-traversal', '../../../../etc/passwd');
    }

    public function test_delete_refuses_a_traversal_path(): void
    {
        $storage = new LocalFilesystemObjectStorage($this->root);

        $this->expectException(ObjectStorageException::class);

        $storage->delete('KTP/quarantine/../../../../etc/passwd');
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
