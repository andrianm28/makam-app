<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Models;

use App\Domain\AgreementCertificate\AgreementStatus;
use App\Domain\AgreementCertificate\AgreementType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Eloquent model for `agreements` — see
 * `2026_08_17_100000_create_agreements_table.php` for the schema. One row
 * per AGREEMENT VERSION; supersession preserves every earlier row (AC5).
 *
 * ---------------------------------------------------------------------------
 * Why this model has NO write guard, unlike `Order` / `Quote`
 * ---------------------------------------------------------------------------
 * `agreements` rows are not money-bearing the way `orders.status` is, and
 * their one legal in-place mutation (the AC2 acceptance binding via
 * `accept()`) is exactly the mutation the platform audit/outbox
 * machinery wraps around. The write discipline is structural — the
 * module's Actions are the only in-repo writers, the `AgreementStatus`
 * CHECK pins the vocabulary on PostgreSQL, and the
 * `(subject_type, subject_id, type, version_number)` unique pair is the
 * database's version backstop — the same "operational record, no guard"
 * reasoning `FuneralCase`'s class doc block argues at length.
 *
 * `accept()` and `supersede()` are the model's two legal status
 * transitions; `delete()` is deliberately guarded where history would be
 * destroyed.
 */
final class Agreement extends Model
{
    use HasUuids;

    protected $table = 'agreements';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'type',
        'version_number',
        'status',
        'subject_type',
        'subject_id',
        'accepted_by_ref',
        'accepted_quote_id',
        'accepted_agreement_version_id',
        'price_guarantee',
        'cancellation_refund',
        'transferability',
        'term',
        'included_services',
        'responsible_entity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
        ];
    }

    public function status(): AgreementStatus
    {
        return AgreementStatus::from($this->status);
    }

    public function type(): AgreementType
    {
        return AgreementType::from($this->type);
    }

    /**
     * The agreement's one legal forward transition in the acceptance
     * direction: draft -> accepted, binding the acceptor and the exact
     * versions to THIS version row — AC2 ("bind it to the actor and the
     * exact document version").
     *
     * `accepted_agreement_version_id` is this row's own id: each row IS an
     * agreement version, so the acceptance evidence records precisely which
     * version was signed. `Actions\AcceptAgreement` separately validates
     * that the caller's `$agreementVersionId` equals this id BEFORE calling
     * us, so the "exact version" claim holds at both layers.
     *
     * @throws InvalidArgumentException when the row is not a draft.
     */
    public function accept(CarbonInterface $now, string $actorRef, ?string $quoteId): void
    {
        if ($this->status !== AgreementStatus::Draft->value) {
            throw new InvalidArgumentException(
                "agreements row [{$this->getKey()}] is [{$this->status}]; only a draft agreement can be accepted."
            );
        }

        $this->status = AgreementStatus::Accepted->value;
        $this->accepted_by_ref = $actorRef;
        $this->accepted_quote_id = $quoteId;
        $this->accepted_agreement_version_id = (string) $this->getKey();
        $this->save();
    }

    /**
     * The incumbent -> superseded transition, called by
     * `Actions\SupersedeAgreement` after the next version row is written.
     *
     * Accepts BOTH an accepted and an active incumbent, never a draft and
     * never an already-superseded row: the plan phrases this as "active ->
     * superseded", but no Lane-1 action promotes accepted -> active (that
     * seam lands with Lane 2's pre-need settlement), and refusing an
     * accepted incumbent would make AC5 history preservation unreachable.
     * The same "current -> superseded" allowance is the one `Quote::
     * supersede()` documents for its accepted incumbent.
     *
     * @throws InvalidArgumentException for a draft or superseded incumbent.
     */
    public function supersede(): void
    {
        if ($this->status === AgreementStatus::Superseded->value) {
            throw new InvalidArgumentException(
                "agreements row [{$this->getKey()}] is already superseded."
            );
        }

        if (! in_array($this->status, [AgreementStatus::Accepted->value, AgreementStatus::Active->value], true)) {
            throw new InvalidArgumentException(
                "agreements row [{$this->getKey()}] is [{$this->status}]; only an accepted or active agreement can be superseded."
            );
        }

        $this->status = AgreementStatus::Superseded->value;
        $this->save();
    }

    /**
     * Always throws for a non-draft row, and for a draft that any
     * certificate references.
     *
     * The two refusals are the same AC5 history rule in two directions:
     * an accepted/superseded row is preserved history (never deletable),
     * and a row a certificate points at must outlive that reference (the
     * certificate subject link is application-enforced — see the
     * `certificates` migration's doc block — so the guard carries the
     * referential integrity the schema does not).
     *
     * @throws LogicException when the row is not a draft or is referenced
     *                        by a certificate.
     */
    public function delete(): ?bool
    {
        if ($this->status !== AgreementStatus::Draft->value) {
            throw new LogicException(
                "agreements row [{$this->getKey()}] is [{$this->status}]; accepted/superseded rows are preserved history (AC5) and cannot be deleted."
            );
        }

        $referenced = Certificate::query()
            ->where('subject_type', self::class)
            ->where('subject_id', $this->getKey())
            ->exists();

        if ($referenced) {
            throw new LogicException(
                "agreements row [{$this->getKey()}] is referenced by a certificate and cannot be deleted."
            );
        }

        return parent::delete();
    }
}
