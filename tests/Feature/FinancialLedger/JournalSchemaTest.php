<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\JournalEntry;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class JournalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_journal_schema_has_the_required_columns_and_guarded_models(): void
    {
        $this->assertTrue(Schema::hasColumns('journal_batches', [
            'id',
            'business_key',
            'entity_ref',
            'source_type',
            'source_id',
            'correlation_id',
            'occurred_at',
            'status',
        ]));
        $this->assertTrue(Schema::hasColumns('journal_entries', [
            'id',
            'batch_id',
            'account_code',
            'direction',
            'amount_minor',
            'currency',
            'reference',
        ]));

        $this->assertSame(['*'], (new JournalBatch)->getGuarded());
        $this->assertSame(['*'], (new JournalEntry)->getGuarded());
        $this->assertFalse((new JournalBatch)->usesTimestamps());
        $this->assertFalse((new JournalEntry)->usesTimestamps());
        $this->assertFalse(Schema::hasColumn('journal_batches', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('journal_entries', 'updated_at'));

        $entryIndexes = Schema::getIndexes('journal_entries');
        $this->assertContains(['batch_id', 'direction'], array_column($entryIndexes, 'columns'));
    }

    public function test_balanced_entries_inserted_as_one_statement_group_are_accepted(): void
    {
        $batchId = $this->insertBatch();

        DB::table('journal_entries')->insert([
            $this->entryAttributes($batchId, '1000', 'DR', 1000),
            $this->entryAttributes($batchId, '4000', 'CR', 1000),
        ]);

        $batch = JournalBatch::query()->findOrFail($batchId);

        $this->assertTrue($batch->isBalanced());
        $this->assertSame(1000, $batch->total());
        $this->assertSame(2, $batch->entries()->count());
    }

    public function test_unbalanced_entries_are_rejected_by_the_postgres_balance_trigger(): void
    {
        $this->skipUnlessPostgres(
            'The assert_balanced_batch constraint trigger is PostgreSQL-only; run this test with DB_CONNECTION=pgsql.'
        );

        $this->expectException(QueryException::class);

        DB::transaction(function (): void {
            $batchId = $this->insertBatch();

            DB::table('journal_entries')->insert([
                $this->entryAttributes($batchId, '1000', 'DR', 1000),
            ]);
        });
    }

    public function test_business_key_is_unique(): void
    {
        $attributes = $this->batchAttributes('payment:duplicate-event');
        DB::table('journal_batches')->insert($attributes);

        $collision = $this->batchAttributes('payment:duplicate-event');

        $this->assertConstraintViolation(function () use ($collision): void {
            DB::table('journal_batches')->insert($collision);
        });
    }

    public function test_entity_ref_is_required(): void
    {
        $attributes = $this->batchAttributes();
        $attributes['entity_ref'] = null;

        $this->assertConstraintViolation(function () use ($attributes): void {
            DB::table('journal_batches')->insert($attributes);
        });
    }

    public function test_journal_entries_reference_existing_batches_and_accounts(): void
    {
        $missingBatch = $this->entryAttributes((string) Str::uuid(), '1000', 'DR', 1000);
        $this->assertConstraintViolation(function () use ($missingBatch): void {
            DB::table('journal_entries')->insert($missingBatch);
        });

        $batchId = $this->insertBatch();
        $missingAccount = $this->entryAttributes($batchId, '9999', 'DR', 1000);
        $this->assertConstraintViolation(function () use ($missingAccount): void {
            DB::table('journal_entries')->insert($missingAccount);
        });
    }

    public function test_closed_lists_are_rejected_by_postgres_checks(): void
    {
        $this->skipUnlessPostgres(
            'The journal closed-list CHECK constraints are PostgreSQL-only; run this test with DB_CONNECTION=pgsql.'
        );

        $invalidBatch = $this->batchAttributes();
        $invalidBatch['source_type'] = 'not-a-source';
        $this->assertConstraintViolation(function () use ($invalidBatch): void {
            DB::table('journal_batches')->insert($invalidBatch);
        });

        $invalidStatus = $this->batchAttributes();
        $invalidStatus['status'] = 'not-a-status';
        $this->assertConstraintViolation(function () use ($invalidStatus): void {
            DB::table('journal_batches')->insert($invalidStatus);
        });

        $batchId = $this->insertBatch();

        $invalidDirection = $this->entryAttributes($batchId, '1000', 'XX', 1000);
        $this->assertConstraintViolation(function () use ($invalidDirection): void {
            DB::table('journal_entries')->insert($invalidDirection);
        });

        $invalidCurrency = $this->entryAttributes($batchId, '1000', 'DR', 1000);
        $invalidCurrency['currency'] = 'USD';
        $this->assertConstraintViolation(function () use ($invalidCurrency): void {
            DB::table('journal_entries')->insert($invalidCurrency);
        });
    }

    public function test_amount_minor_cannot_be_negative(): void
    {
        $batchId = $this->insertBatch();
        $entry = $this->entryAttributes($batchId, '1000', 'DR', -1);

        if ($this->isPostgres()) {
            $this->assertConstraintViolation(function () use ($entry): void {
                DB::table('journal_entries')->insert($entry);
            });

            return;
        }

        // SQLite has no ALTER TABLE ADD CONSTRAINT path in the migrations.
        // The production PostgreSQL CHECK is covered above and in CI.
        $this->markTestSkipped(
            'The amount_minor non-negative CHECK is PostgreSQL-only; run this test with DB_CONNECTION=pgsql.'
        );
    }

    private function insertBatch(?string $businessKey = null): string
    {
        $id = (string) Str::uuid();

        DB::table('journal_batches')->insert($this->batchAttributes($businessKey, $id));

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function batchAttributes(?string $businessKey = null, ?string $id = null): array
    {
        return [
            'id' => $id ?? (string) Str::uuid(),
            'business_key' => $businessKey ?? 'payment:'.Str::uuid(),
            'entity_ref' => 'badan-usaha-1',
            'source_type' => 'payment',
            'source_id' => 'provider-event-1',
            'correlation_id' => 'trace-1',
            'occurred_at' => now(),
            'status' => 'posted',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryAttributes(string $batchId, string $accountCode, string $direction, int $amountMinor): array
    {
        return [
            'batch_id' => $batchId,
            'account_code' => $accountCode,
            'direction' => $direction,
            'amount_minor' => $amountMinor,
            'currency' => 'IDR',
            'reference' => 'fixture-reference',
        ];
    }

    private function assertConstraintViolation(Closure $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function skipUnlessPostgres(string $message): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped($message);
        }
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
