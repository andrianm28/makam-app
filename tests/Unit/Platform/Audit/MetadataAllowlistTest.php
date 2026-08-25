<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Audit;

use App\Platform\Audit\Exceptions\AuditMetadataKeyNotAllowedException;
use App\Platform\Audit\MetadataAllowlist;
use Tests\TestCase;

/**
 * AC5: the metadata allowlist. Pure unit coverage — no database.
 */
final class MetadataAllowlistTest extends TestCase
{
    public function test_empty_metadata_is_always_allowed(): void
    {
        MetadataAllowlist::assertAllowed([]);

        $this->addToAssertionCount(1);
    }

    public function test_each_allowed_key_individually_passes(): void
    {
        foreach (MetadataAllowlist::ALLOWED_KEYS as $key) {
            MetadataAllowlist::assertAllowed([$key => 'value']);
        }

        $this->addToAssertionCount(count(MetadataAllowlist::ALLOWED_KEYS));
    }

    public function test_all_allowed_keys_together_pass(): void
    {
        $metadata = array_fill_keys(MetadataAllowlist::ALLOWED_KEYS, 'value');

        MetadataAllowlist::assertAllowed($metadata);

        $this->addToAssertionCount(1);
    }

    public function test_a_key_outside_the_allowlist_throws(): void
    {
        $this->expectException(AuditMetadataKeyNotAllowedException::class);

        MetadataAllowlist::assertAllowed(['ktp_number' => '3171xxxxxxxxxxxx']);
    }

    public function test_one_restricted_key_alongside_allowed_keys_still_throws(): void
    {
        // The whole call must be rejected, not silently stripped down
        // to the allowed subset — a silent strip would let a caller
        // believe restricted data was recorded when it was actually
        // dropped, which is its own kind of unreliable audit trail.
        $this->expectException(AuditMetadataKeyNotAllowedException::class);

        MetadataAllowlist::assertAllowed([
            'reference_number' => 'INV-1',
            'bank_account_number' => '1234567890',
        ]);
    }

    public function test_the_exception_message_names_the_rejected_keys(): void
    {
        try {
            MetadataAllowlist::assertAllowed(['ktp_number' => 'x', 'bank_account_number' => 'y']);
            $this->fail('Expected AuditMetadataKeyNotAllowedException to be thrown.');
        } catch (AuditMetadataKeyNotAllowedException $exception) {
            $this->assertStringContainsString('ktp_number', $exception->getMessage());
            $this->assertStringContainsString('bank_account_number', $exception->getMessage());
        }
    }
}
