<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\Models\QuoteLine;
use App\Domain\Quotation\QuoteStatus;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Platform\FinancialLedger\Money;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;

/**
 * AC8 — Task 4 of
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`.
 * Writes ONE immutable quote version for an order. The ONLY writer of
 * `quotes` and `quote_lines` rows.
 *
 * ---------------------------------------------------------------------------
 * Versioning: the incumbent version is superseded, never rewritten
 * ---------------------------------------------------------------------------
 * "WHEN a quote is revised THE SYSTEM SHALL create a new version." Within
 * one `DB::transaction()`: the order row is re-read under `lockForUpdate()`
 * (serializing concurrent issuance for one order, the same row-lock
 * discipline `RecordOrderStatusChange` uses), the incumbent
 * non-superseded version — if any — is superseded through `Quote::supersede()`
 * (its stored amounts stay byte-identical), and the next `version_number`
 * is derived as `MAX(version_number) + 1`. The `(order_id, version_number)`
 * unique pair is the database backstop; a duplicate insert surfaces as a
 * `QueryException`, not a swallowed check-then-act race.
 *
 * ---------------------------------------------------------------------------
 * Money: the decimal -> minor-units conversion happens EXACTLY ONCE here
 * ---------------------------------------------------------------------------
 * Each line's `unit_amount` is a decimal:2 string; `Money::fromDecimal()`
 * converts it to integer minor units at this Action and nowhere else.
 * `line_total_minor = unit_amount_minor * quantity` is COMPUTED here, never
 * trusted from the caller, and the quote total is the `Money::add` sum of
 * the line totals. A single-currency set is required (the quote's `currency`
 * is that one value) and a zero or negative total is rejected outright
 * (`Money::isPositive()`).
 *
 * The referenced `service_package_version_id` must be an existing, PUBLISHED
 * (therefore frozen) `ServicePackageVersion` — the snapshot invariant the
 * brief names. `price_version_id` is enforced by the `quote_lines` restrict
 * FK, not re-checked here: it is a reference into an append-only table.
 *
 * The whole mutation and its `quote.issued.v1` outbox row commit together
 * (`Outbox::record()` inside this transaction — `AGENTS.md` §Queue and event
 * reliability).
 *
 * NOT VERIFIED ON THIS HOST, stated rather than assumed: true CONCURRENT
 * issuance is not exercisable on the hermetic single-connection in-memory
 * SQLite suite (`lockForUpdate()` is additionally a no-op there). Task 10
 * owns real PostgreSQL 18 verification.
 */
final readonly class IssueQuote
{
    /**
     * @param  list<array<string, mixed>>  $lines  See the test suite's class
     *                                             doc block and `task-4-report.md`
     *                                             for the ratified shape.
     */
    public function __invoke(
        Order $order,
        array $lines,
        CarbonInterface $expiresAt,
        string $actorRef,
        string $actorRole,
    ): Quote {
        $normalized = $this->validateAndNormalizeLines($lines);

        return DB::transaction(function () use ($order, $normalized, $expiresAt, $actorRef, $actorRole): Quote {
            $currentOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $incumbent = Quote::query()
                ->where('order_id', $currentOrder->getKey())
                ->where('status', '!=', QuoteStatus::SUPERSEDED->value)
                ->first();

            if ($incumbent !== null) {
                $incumbent->supersede(CarbonImmutable::now());
            }

            $nextVersion = (int) (Quote::query()
                ->where('order_id', $currentOrder->getKey())
                ->max('version_number') ?? 0) + 1;

            $total = new Money(0);

            foreach ($normalized as $line) {
                $total = $total->add(new Money($line['line_total_minor']));
            }

            if (! $total->isPositive()) {
                throw new InvalidArgumentException(
                    "A quote for order [{$currentOrder->getKey()}] must have a strictly positive total."
                );
            }

            $quote = Quote::query()->create([
                'order_id' => $currentOrder->getKey(),
                'version_number' => $nextVersion,
                'status' => QuoteStatus::ISSUED->value,
                'total_minor' => $total->toMinorInt(),
                'currency' => $normalized[0]['currency'],
                'issued_at' => CarbonImmutable::now(),
                'expires_at' => $expiresAt,
                'issued_by_ref' => $actorRef,
                'issued_by_role' => $actorRole,
            ]);

            foreach ($normalized as $line) {
                QuoteLine::query()->create([
                    'quote_id' => $quote->getKey(),
                    'service_package_version_id' => $line['service_package_version_id'],
                    'price_version_id' => $line['price_version_id'],
                    'price_version_number' => $line['price_version_number'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_amount_minor' => $line['unit_amount_minor'],
                    'line_total_minor' => $line['line_total_minor'],
                    'currency' => $line['currency'],
                    'fulfillment_owner' => $line['fulfillment_owner'],
                ]);
            }

            // `event-catalog.md:17` — a catalogued event, not invented here.
            // References only: no amounts, no restricted data.
            Outbox::record(
                eventName: 'quote.issued.v1',
                eventVersion: 1,
                aggregateType: 'quote',
                aggregateId: $quote->getKey(),
                data: [
                    'quote_id' => $quote->getKey(),
                    'order_id' => $currentOrder->getKey(),
                    'version_number' => $quote->version_number,
                    'status' => $quote->status,
                ],
                classification: OutboxClassification::Internal,
                idempotencyKey: "quote_issued:{$quote->getKey()}",
            );

            return $quote;
        });
    }

    /**
     * Validates and computes every amount-bearing field ONCE, before any
     * transaction opens (same "reject before the transaction" discipline as
     * `RecordOrderStatusChange`'s metadata allowlist check).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function validateAndNormalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A quote must carry at least one line.');
        }

        $currency = null;
        $normalized = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException("Quote line [{$index}] must be an array.");
            }

            $servicePackageVersionId = (int) $this->requiredInt($line, 'service_package_version_id', $index);
            $version = ServicePackageVersion::query()->find($servicePackageVersionId);

            if (! $version instanceof ServicePackageVersion || ! $version->isPublished()) {
                throw new InvalidArgumentException(
                    "Quote line [{$index}] references service package version [{$servicePackageVersionId}], ".
                    'which is not a frozen published version.'
                );
            }

            $quantity = $this->requiredInt($line, 'quantity', $index);

            if ($quantity < 1) {
                throw new InvalidArgumentException("Quote line [{$index}] quantity must be a positive integer.");
            }

            $unitAmount = $this->requiredString($line, 'unit_amount', $index);
            $unitAmountMinor = Money::fromDecimal($unitAmount);

            $lineCurrency = $this->requiredString($line, 'currency', $index);

            if ($currency === null) {
                $currency = $lineCurrency;
            } elseif ($lineCurrency !== $currency) {
                throw new InvalidArgumentException(
                    "Quote line [{$index}] currency [{$lineCurrency}] does not match the line set's ".
                    "single currency [{$currency}]."
                );
            }

            $fulfillmentOwner = $this->requiredString($line, 'fulfillment_owner', $index);
            FulfillmentOwner::assertKnown($fulfillmentOwner);

            $normalized[] = [
                'service_package_version_id' => $servicePackageVersionId,
                'price_version_id' => $this->requiredInt($line, 'price_version_id', $index),
                'price_version_number' => $this->requiredInt($line, 'price_version_number', $index),
                'description' => $this->requiredString($line, 'description', $index),
                'quantity' => $quantity,
                'unit_amount_minor' => $unitAmountMinor,
                'line_total_minor' => $this->lineTotalMinor($unitAmountMinor, $quantity),
                'currency' => $lineCurrency,
                'fulfillment_owner' => $fulfillmentOwner,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function requiredString(array $line, string $key, int $index): string
    {
        if (! array_key_exists($key, $line) || ! is_string($line[$key]) || trim($line[$key]) === '') {
            throw new InvalidArgumentException("Quote line [{$index}] requires a non-blank [{$key}].");
        }

        return $line[$key];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function requiredInt(array $line, string $key, int $index): int
    {
        if (! array_key_exists($key, $line) || ! is_int($line[$key])) {
            throw new InvalidArgumentException("Quote line [{$index}] requires an integer [{$key}].");
        }

        return $line[$key];
    }

    /**
     * `unit_amount_minor * quantity`, with an explicit overflow guard so a
     * maliciously large quantity cannot silently wrap into a float (which
     * `Money` would then refuse at construction, but only after the caller
     * already got further than it should have).
     */
    private function lineTotalMinor(int $unitAmountMinor, int $quantity): int
    {
        if ($unitAmountMinor > intdiv(PHP_INT_MAX, $quantity)
            || $unitAmountMinor < intdiv(PHP_INT_MIN, $quantity)) {
            throw new OverflowException('Quote line total exceeds the integer range.');
        }

        return $unitAmountMinor * $quantity;
    }
}
