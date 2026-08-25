<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Models;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\Quotation\Exceptions\IssuedQuoteIsImmutableException;
use App\Domain\Quotation\QuoteStatus;
use App\Platform\FinancialLedger\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Eloquent model for `quotes` — one row per QUOTE VERSION for an order. See
 * `2026_08_12_100040_create_quotes_table.php` for the schema.
 *
 * ---------------------------------------------------------------------------
 * AC8, structurally: what the write guard closes and what it cannot
 * ---------------------------------------------------------------------------
 * "THE SYSTEM SHALL NOT modify a quote after it is issued." That is
 * enforced at the Eloquent layer exactly the way this lane's other
 * money-bearing rows are (`App\Platform\Payment\Models\PaymentIntent`,
 * `App\Domain\OrderWorkflow\Models\OrderStatusEvent`, and — most closely —
 * `App\Domain\OrderWorkflow\Models\Order`): `performUpdate()` is a
 * universal refusal unless a private authorization flag is set, and
 * `delete()` is a universal refusal.
 *
 * Unlike `OrderStatusEvent` there is no `update()` override: `update()` is
 * a thin wrapper over `fill()+save()`, and `save()` on an existing row
 * routes through `performUpdate()` — so `performUpdate()` is the single
 * choke point that every model-level write path funnels through, including
 * `saveQuietly()`/`updateQuietly()` (which suppress events but still call
 * `performUpdate()`) and `fill()`/`forceFill()`+`save()`. The `Order`
 * review cycle (Task 2) verified that `update()`/`performUpdate()`/`delete()`
 * overrides cover every model-level write path in Laravel 13; `performUpdate()`
 * + `delete()` here is exactly that set minus the redundant `update()`
 * override, which would otherwise make the mutation check vacuous (see
 * `task-4-report.md`).
 *
 * What this does NOT stop, stated plainly rather than assumed closed: a
 * builder-level mass write (`Quote::query()->...->update([...])` /
 * `->delete()`), `DB::table('quotes')->update(...)`, and any raw SQL — those
 * never instantiate the model. Closing them needs a database trigger, which
 * is not this task's scope; the same disclosure `Order` and `PaymentIntent`
 * make in their own doc blocks.
 *
 * ---------------------------------------------------------------------------
 * The two legal transitions, and who is allowed to perform them
 * ---------------------------------------------------------------------------
 * A persisted quote is otherwise immutable, so `performUpdate()` has exactly
 * two authorized callers, both private-flag doors on this model:
 *
 *  - `accept()`: issued -> accepted, stamping `accepted_at` +
 *    `accepted_by_ref`. Refuses a superseded/accepted quote and a quote
 *    whose `expires_at` has passed — expiry is evaluated HERE, lazily and
 *    authoritatively at guard time (`task-4-brief.md` Q5), never by a
 *    scheduled job.
 *  - `supersede()`: the current version -> superseded, stamping
 *    `superseded_at`. Called by `Actions\IssueQuote` on the incumbent
 *    version when it writes a newer one. Deliberately allowed on BOTH an
 *    issued and an accepted incumbent: superseding an ACCEPTED version must
 *    not move the order backward (the tests pin exactly that), so the
 *    transition is "current -> superseded", not "issued -> superseded".
 *    Refuses a quote already superseded.
 *
 * The doors are public because `Actions\` are separate namespaces, but they
 * validate every precondition themselves, so a direct call cannot corrupt
 * quote state — what a direct call WOULD skip is the `quote.issued.v1` /
 * `quote.accepted.v1` outbox emission the Actions own. In-repo, the Actions
 * are the only callers.
 *
 * ---------------------------------------------------------------------------
 * Versioning semantics
 * ---------------------------------------------------------------------------
 * Acceptance and supersession are properties of the VERSION, not the order:
 * superseding an accepted v1 never moves `orders.status` (that column is
 * `Order`'s, and its own guard already makes any write here impossible), and
 * `currentFor()` returns the newest non-superseded version. A
 * superseded-accepted v1 makes `isAcceptedAndUnexpired()` false for the
 * current version — which is how the Task 6 payment guard fails its
 * condition 3 without any backward transition.
 */
final class Quote extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'quotes';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'version_number',
        'status',
        'total_minor',
        'currency',
        'issued_at',
        'expires_at',
        'accepted_at',
        'superseded_at',
        'issued_by_ref',
        'issued_by_role',
        'accepted_by_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'total_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Set only for the duration of `accept()` / `supersede()`.
     * `performUpdate()` is an unconditional refusal for every other caller.
     */
    private bool $mutationAuthorized = false;

    /**
     * The single choke point of the write guard — see the class-level doc
     * block for why `update()` is deliberately NOT overridden here: a
     * redundant `update()` throw would make the mutation check (plan Step 5)
     * vacuous, because commenting it out would still leave
     * `performUpdate()`'s refusal standing.
     */
    protected function performUpdate(Builder $query): bool
    {
        if (! $this->mutationAuthorized) {
            throw IssuedQuoteIsImmutableException::forQuote($this->getKey(), 'save');
        }

        return parent::performUpdate($query);
    }

    /**
     * Always throws — see the class-level doc block. A quote is a frozen
     * commercial record; `quote_lines.quote_id` is `restrictOnDelete()` for
     * the same reason.
     */
    public function delete(): ?bool
    {
        throw IssuedQuoteIsImmutableException::forQuote($this->getKey(), 'delete');
    }

    /**
     * The one legal transition in the acceptance direction. Validates both
     * preconditions itself so the door is safe standalone:
     *
     *  1. the version must be `ISSUED` (an accepted or superseded version
     *     cannot be accepted again);
     *  2. the version must not have expired — `$now` must be strictly before
     *     `expires_at`. Expiry is evaluated here, at guard time, and nowhere
     *     else (`task-4-brief.md` Q5).
     */
    public function accept(CarbonInterface $now, string $actorRef): void
    {
        if ($this->status !== QuoteStatus::ISSUED->value) {
            throw new InvalidArgumentException(
                "quotes row [{$this->getKey()}] is [{$this->status}]; only an issued quote can be accepted."
            );
        }

        if ($this->expires_at === null || ! $now->lessThan($this->expires_at)) {
            throw new InvalidArgumentException(
                "quotes row [{$this->getKey()}] expired at [{$this->expires_at}] and cannot be accepted."
            );
        }

        $this->mutationAuthorized = true;

        try {
            $this->forceFill([
                'status' => QuoteStatus::ACCEPTED->value,
                'accepted_at' => $now,
                'accepted_by_ref' => $actorRef,
            ]);
            $this->save();
        } finally {
            $this->mutationAuthorized = false;
        }
    }

    /**
     * The other legal transition: the incumbent version -> superseded,
     * stamping `superseded_at`. Called by `Actions\IssueQuote` when it
     * writes a newer version for the same order.
     *
     * Deliberately accepts an ACCEPTED incumbent too — see the class-level
     * doc block. Refuses an already-superseded version.
     */
    public function supersede(CarbonInterface $now): void
    {
        if ($this->status === QuoteStatus::SUPERSEDED->value) {
            throw new InvalidArgumentException(
                "quotes row [{$this->getKey()}] is already superseded."
            );
        }

        $this->mutationAuthorized = true;

        try {
            $this->forceFill([
                'status' => QuoteStatus::SUPERSEDED->value,
                'superseded_at' => $now,
            ]);
            $this->save();
        } finally {
            $this->mutationAuthorized = false;
        }
    }

    /**
     * The stored integer minor-units total, as a `Money` value.
     */
    public function totalMinor(): Money
    {
        return new Money($this->total_minor);
    }

    /**
     * The Task 6 guard's condition 3, evaluated lazily and authoritatively
     * at guard time: the version must be `ACCEPTED` (accepted_at stamped)
     * AND `$now` must be strictly before `expires_at`. A superseded-accepted
     * version is no longer `ACCEPTED`, so this returns false for it without
     * any backward transition.
     */
    public function isAcceptedAndUnexpired(CarbonInterface $now): bool
    {
        return $this->status === QuoteStatus::ACCEPTED->value
            && $this->accepted_at !== null
            && $this->expires_at !== null
            && $now->lessThan($this->expires_at);
    }

    /**
     * The current version of an order's quote history — the newest
     * non-superseded one, or null when the order has never been quoted.
     *
     * Exactly one version is ever current: `Actions\IssueQuote` supersedes
     * the incumbent before inserting a newer row, so `orderByDesc` here is a
     * belt-and-suspenders over an invariant the write path maintains.
     */
    public static function currentFor(Order $order): ?Quote
    {
        return self::query()
            ->where('order_id', $order->getKey())
            ->where('status', '!=', QuoteStatus::SUPERSEDED->value)
            ->orderByDesc('version_number')
            ->first();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return HasMany<QuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class, 'quote_id');
    }
}
