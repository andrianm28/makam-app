<?php

declare(strict_types=1);

use App\Platform\Payment\ProviderEventStatus;
use App\Platform\Payment\ProviderEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `provider_events` — `.kiro/specs/platform-payment-adapter/design.md` §Data:
 * "raw durable webhook store, unique by provider event id", and "append-only
 * and … the replay source of truth".
 *
 * This is the table AC5 exists for: "WHEN a webhook is received THE SYSTEM
 * SHALL persist it, acknowledge it within 2 seconds, and then process it
 * asynchronously." The row is written BEFORE the payload is validated, so the
 * evidence survives even when the webhook is a forgery — AC6's "record and
 * reject … SHALL NOT silently ignore it" has nowhere else to write.
 *
 * ---------------------------------------------------------------------------
 * Two unique guards, and why the second one is partial
 * ---------------------------------------------------------------------------
 * `docs/contracts/payment-webhook.md` §Idempotency: "Primary key should use
 * provider + event ID. A secondary guard should prevent the same provider
 * transaction from settling multiple invoices."
 *
 *   1. `(provider, provider_event_id)` — the primary idempotency key. A
 *      redelivery of the same provider event collides on insert; the receiver
 *      catches that collision and acks success with the original row, never
 *      writing a second one (AC7).
 *
 *   2. `(provider, provider_transaction_id, invoice_reference)` — the plan's
 *      named secondary guard, applied ONLY to settling event types
 *      (`ProviderEventType::settlingValues()`), as a partial unique index.
 *
 * The partiality is deliberate and is the one place this migration narrows
 * what the plan's one-line description literally says, so it is spelled out
 * rather than left to be discovered:
 *
 *   - Applied to every row, the guard would make Task 4's explicitly required
 *     out-of-order case impossible to receive at all. The plan's Task 4 says
 *     "an `expired` arriving after `completed` is a no-op (state already
 *     terminal), never a regression" — but `payment.expired` and
 *     `payment.completed` for one transaction share the same
 *     (transaction, invoice) pair, so a total unique index would reject the
 *     second one at INSERT. AC5's durable-persistence requirement would lose
 *     to an idempotency rule that was never about non-settling events.
 *   - Scoped to settling events, the guard does exactly what the contract
 *     sentence asks and nothing more.
 *
 * KNOWN GAP, recorded here rather than implied: this triple prevents the same
 * (transaction, invoice) pair from being settled twice. It does NOT prevent one
 * provider transaction from settling two DIFFERENT invoices — that would need
 * uniqueness on `(provider, provider_transaction_id)` alone, which is a
 * stronger rule than the plan names and belongs to Task 4's apply-time claim
 * (the plan assigns the secondary-guard re-check there). Flagged for that task.
 *
 * NOT TESTED on PostgreSQL: the suite runs on SQLite (`phpunit.xml`
 * `DB_CONNECTION=sqlite`). Both unique indexes and the status CHECK constraint
 * below are created with driver-specific SQL; only the SQLite forms are
 * exercised locally. The PostgreSQL forms are first executed by CI.
 *
 * ---------------------------------------------------------------------------
 * `raw_payload` is encrypted at rest
 * ---------------------------------------------------------------------------
 * `payment-webhook.md` §Security: "Store raw payload privately with retention
 * policy TBD." The column holds the verbatim request body — the only artefact
 * a later replay or dispute can be resolved against — and a provider body can
 * carry personal data. `Models\ProviderEvent` casts it `encrypted`, so the
 * column stores ciphertext and the plaintext never exists in a database dump,
 * a replica, or a backup. Retention remains TBD upstream; nothing here sets one.
 *
 * The body is stored VERBATIM, never redacted: a redacted body cannot be
 * re-verified against its signature, which would destroy the evidentiary value
 * the table exists for. Redaction (`Http\Middleware\RedactProviderPayload`)
 * applies to logs and error trackers, which is where AC14 aims it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            // UUID for the same reason as `payment_intents`/`payment_sessions`:
            // this id is quoted back to a provider/support engineer as the
            // "original processing reference" payment-webhook.md §Idempotency
            // requires, and a sequential id would leak webhook volume.
            $table->uuid('id')->primary();

            $table->string('provider', 32);

            // The provider's own event identity. `svix-id` when the delivery
            // carries Svix headers; otherwise a `sha256:` digest of the raw
            // body — see `event_id_source` and `ReceiveWebhook`.
            $table->string('provider_event_id', 191);
            $table->string('event_id_source', 16);

            // Nullable because a malformed or unsigned body may not yield them
            // and the row must still be written (AC5/AC6 both require the
            // record to exist before anything is known to be true about it).
            $table->string('provider_transaction_id', 128)->nullable();
            $table->string('invoice_reference', 128)->nullable();
            $table->string('event_type', 64)->nullable();

            // AC13: "bind merchant and `badan_usaha` explicitly on every
            // session and … reconcile the binding on every webhook." NOT NULL
            // because it comes from the merchant-scoped route segment, which
            // always exists — the reconciliation against the session's
            // `merchant_ref`/`badan_usaha_ref` happens in `WebhookValidator`.
            $table->string('merchant_ref', 128);

            // Wave 0 ruling 0c: integer minor units, never a float or decimal.
            // Nullable for the same reason as the identity columns above.
            $table->bigInteger('amount_minor')->nullable();

            // The currency the payload itself declared, when it declared one.
            // ADR-0033's webhook envelope does not include a currency field
            // (`event_type`, `data.payment_id`, `data.order_id`, `data.amount`,
            // `data.fee`, `data.net_amount`, `data.status`,
            // `data.payment_method`, `data.completed_at`), so this is usually
            // null and AC6's currency check falls to the session's currency.
            $table->string('declared_currency', 3)->nullable();

            $table->timestamp('event_occurred_at')->nullable();

            // Encrypted by the model's cast — see the class doc block.
            $table->text('raw_payload');

            // SHA-256 of the raw body, in the clear. Lets an operator or a
            // reconciliation job detect two deliveries with identical bodies
            // (and tampering with the ciphertext) without decrypting anything.
            // A digest of an already-signed provider body discloses nothing
            // the signature did not already commit to.
            $table->string('payload_digest', 64);

            // Which verification mechanism accepted (or was attempted for)
            // this delivery: ADR-0033 allows Svix signatures or a shared
            // `X-Webhook-Token`. Never the secret, never the signature value.
            $table->string('signature_mechanism', 16)->nullable();

            // `App\Platform\Payment\ProviderEventStatus`. CHECK-constrained
            // below on Postgres, and enforced in the model for SQLite.
            $table->string('status', 32);

            // Internal-only explanation of a rejection. Composed exclusively
            // from closed-list values and field NAMES by `WebhookValidator` —
            // never a credential, never a payload value (`AGENTS.md`
            // §Observability).
            $table->string('rejection_detail', 191)->nullable();

            $table->timestamp('received_at');
            $table->timestamp('validated_at')->nullable();

            // `platform-audit` design.md: "correlation_id originates at the
            // request boundary and is propagated into outbox events, queue
            // jobs, provider calls, and notifications."
            $table->string('correlation_id', 128)->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'provider_events_provider_event_unq');

            $table->index('status', 'provider_events_status_idx');
            $table->index('merchant_ref', 'provider_events_merchant_idx');
            $table->index(['provider', 'provider_transaction_id'], 'provider_events_transaction_idx');
            $table->index('received_at', 'provider_events_received_at_idx');
        });

        $settling = implode("', '", ProviderEventType::settlingValues());

        // Partial unique index — see the class doc block. Laravel's schema
        // builder has no partial-index API, so this is raw SQL per driver.
        // Both PostgreSQL and SQLite support `CREATE UNIQUE INDEX … WHERE`,
        // with the same semantics for the NULL rows this deliberately skips
        // (a rejected, identity-less delivery must never collide with another).
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX provider_events_settlement_unq ON provider_events '.
                '(provider, provider_transaction_id, invoice_reference) '.
                "WHERE event_type IN ('{$settling}') ".
                'AND provider_transaction_id IS NOT NULL AND invoice_reference IS NOT NULL'
            );
        }

        // Same pgsql guard and rationale as
        // `2026_08_09_100100_create_payment_sessions_table.php`: SQLite cannot
        // ADD CONSTRAINT, phpunit.xml defaults to sqlite, CI and every real
        // environment run Postgres. `Models\ProviderEvent` enforces the same
        // closed list at the model layer so SQLite is not left unguarded.
        if ($driver === 'pgsql') {
            $statuses = implode("', '", ProviderEventStatus::values());

            DB::statement(
                'ALTER TABLE provider_events ADD CONSTRAINT provider_events_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
