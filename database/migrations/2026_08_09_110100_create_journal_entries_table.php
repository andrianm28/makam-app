<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('batch_id')->constrained('journal_batches');
            $table->string('account_code', 16);
            $table->string('direction', 2);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('IDR');
            $table->text('reference')->nullable();

            $table->foreign('account_code')->references('code')->on('coa_accounts');
            $table->index('account_code');
            $table->index(['batch_id', 'direction']);
        });

        // SQLite cannot add these constraints or create the PostgreSQL
        // constraint trigger. The focused tests skip these checks locally;
        // production and CI run the authoritative path on PostgreSQL 18.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_direction_check '.
            "CHECK (direction IN ('DR', 'CR'))"
        );
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_amount_minor_check '.
            'CHECK (amount_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_currency_check '.
            "CHECK (currency = 'IDR')"
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_balanced_batch()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            DECLARE
                batch_balance numeric;
            BEGIN
                SELECT COALESCE(
                    SUM(CASE WHEN direction = 'DR' THEN amount_minor ELSE -amount_minor END),
                    0
                )
                INTO batch_balance
                FROM journal_entries
                WHERE batch_id = NEW.batch_id;

                IF batch_balance <> 0 THEN
                    RAISE EXCEPTION
                        'Journal batch % is unbalanced: debit/credit difference is % minor units',
                        NEW.batch_id,
                        batch_balance
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $function$;

            CREATE CONSTRAINT TRIGGER assert_balanced_batch
            AFTER INSERT ON journal_entries
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW
            EXECUTE FUNCTION assert_balanced_batch();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS assert_balanced_batch ON journal_entries;
                DROP FUNCTION IF EXISTS assert_balanced_batch();
                SQL);
        }

        Schema::dropIfExists('journal_entries');
    }
};
