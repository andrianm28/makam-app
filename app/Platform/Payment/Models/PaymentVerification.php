<?php

declare(strict_types=1);

namespace App\Platform\Payment\Models;

use App\Platform\Payment\Exceptions\PaymentVerificationAlreadyDecidedException;
use App\Platform\Payment\PaymentVerificationDecision;
use App\Platform\Payment\PaymentVerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Eloquent model for `payment_verifications` — see
 * `2026_08_11_100000_create_payment_verifications_table.php` for the schema
 * and for why this table has no foreign key to `payment_sessions` or any
 * order/booking table.
 *
 * ---------------------------------------------------------------------------
 * Three doors, no others — the "one write API per table" convention this
 * lane already uses for `ProviderEvent`/`Document`
 * ---------------------------------------------------------------------------
 * `createSubmitted()`, `attachProof()`, and `decide()` are the only methods
 * that write `status`, `proof_document_id`, or the `decided_*` columns.
 * `status` and the `decided_*` columns are deliberately excluded from
 * `$fillable`, so `PaymentVerification::create([...])` or a plain
 * `$verification->fill([...])` cannot set them — a caller that tries lands
 * on the `saving` hook's "unknown status" refusal below, because `status`
 * was never actually assigned.
 *
 * `App\Platform\Payment\SubmitManualPayment` is the only caller of
 * `createSubmitted()`/`attachProof()`; `App\Platform\Payment\
 * VerifyManualPayment` is the only caller of `decide()`.
 */
final class PaymentVerification extends Model
{
    use HasUuids;

    protected $table = 'payment_verifications';

    protected $keyType = 'string';

    /**
     * `id`, `status`, `proof_document_id`, `submitted_at`, and every
     * `decided_*` column are deliberately NOT fillable — see class doc
     * block. `HasUuids` generates `id`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'payment_method',
        'payment_reference',
        'instructions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $verification): void {
            // The status column is CHECK-constrained on Postgres; this makes
            // the same closed list real on SQLite (the test default) and
            // fails at the model rather than at the driver. Also the
            // structural guard against `::create()`/`fill()` bypassing
            // `createSubmitted()` — see class doc block.
            if (! in_array($verification->status, PaymentVerificationStatus::values(), true)) {
                throw new LogicException(
                    'A payment_verifications row must have a known status before it can be saved; '
                    .'use PaymentVerification::createSubmitted() rather than create()/fill().'
                );
            }
        });
    }

    /**
     * The only path that may write a new row. Mirrors
     * `App\Platform\DocumentVault\Models\Document::createQuarantined()` —
     * every created row starts at `SUBMITTED`, never any other status.
     *
     * @param  array<string, mixed>  $attributes  `reference`, `payment_method`,
     *                                            `payment_reference`, `instructions`, `submitted_at`.
     */
    public static function createSubmitted(array $attributes): static
    {
        $verification = new self;
        $verification->fill($attributes);
        $verification->forceFill([
            'status' => PaymentVerificationStatus::Submitted->value,
            'submitted_at' => $attributes['submitted_at'] ?? now(),
        ]);
        $verification->save();

        return $verification;
    }

    /**
     * Records the proof document's reference on an already-created row —
     * called by `SubmitManualPayment` AFTER `App\Platform\DocumentVault\
     * Actions\UploadDocument::upload()` returns, exactly the sequencing the
     * brief specifies: "create the row first inside the same transaction,
     * then upload, then set `proof_document_id`."
     */
    public function attachProof(string $documentId): void
    {
        if ($this->status() !== PaymentVerificationStatus::Submitted) {
            throw new LogicException(
                "Cannot attach a proof document to payment verification {$this->id}: it is no longer SUBMITTED."
            );
        }

        $this->forceFill(['proof_document_id' => $documentId]);
        $this->save();
    }

    /**
     * AC8's "separate authorized action" — the only path that may move this
     * row out of `SUBMITTED`. Decides exactly once: a second call on an
     * already-decided row throws rather than silently overwriting the first
     * decision.
     */
    public function decide(PaymentVerificationDecision $decision, string $decidedByActorRef, ?string $reason = null): void
    {
        if ($this->status() !== PaymentVerificationStatus::Submitted) {
            throw PaymentVerificationAlreadyDecidedException::forStatus($this->status);
        }

        $this->forceFill([
            'status' => $decision->resultingStatus()->value,
            'decided_at' => now(),
            'decided_reason' => $reason,
            'decided_by_actor_ref' => $decidedByActorRef,
        ]);
        $this->save();
    }

    public function status(): PaymentVerificationStatus
    {
        return PaymentVerificationStatus::from($this->status);
    }
}
