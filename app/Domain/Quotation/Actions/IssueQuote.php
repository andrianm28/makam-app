<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Actions;

use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\Models\QuoteLine;
use App\Domain\Quotation\QuoteStatus;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Platform\FinancialLedger\Money;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * ---------------------------------------------------------------------------
 * LINE FAMILIES — the P0 ruling (14 Aug 2026) and ADR-0042 (17 Sep 2026)
 * ---------------------------------------------------------------------------
 * `IssueQuote` accepts one of three line families per line:
 *
 * - A PACKAGE line (`service_package_version_id` present): the Task 4
 *   shape above, unchanged — marketplace/operator quotes keep working.
 * - A SERVICE line (`service_definition_id` present): the booking wizard
 *   quotes individual SERVICES, not packages, so a line may reference a
 *   `ServiceDefinition` directly with the shape
 *   `list<array{service_definition_id: int, price_version_id: int,
 *   price_version_number: int, quantity: int, unit_amount: string,
 *   currency: string, fulfillment_owner: string}>`.
 * - A PLOT line (`grave_plot_id` AND `cemetery_package_id` both present,
 *   ADR-0042): a grave plot sold with the `CemeteryPackage` that priced
 *   it, shape `list<array{grave_plot_id: string, cemetery_package_id: int,
 *   price_version_id: int, price_version_number: int, quantity: int,
 *   unit_amount: string, currency: string, fulfillment_owner: string}>`.
 *   Note `grave_plot_id` is a UUID string — `cemetery_package_id` is a
 *   bigint — the two columns are deliberately different types.
 *
 * A line must carry EXACTLY ONE family's key group (a partial or doubled
 * group is rejected). Per ADR-0042 the SET of families present across a
 * quote's lines must be one of `[package]`, `[service]`, `[plot]`, or
 * `[plot, service]` — a plot and the funeral services bought with it are
 * one pricing universe, but PACKAGE stays exclusive, mirroring the
 * single-currency rule's reasoning (a quote snapshots ONE kind of pricing
 * universe).
 *
 * For a service line the referenced `PriceVersion` must EXIST and BE THE
 * CURRENT (non-superseded) version OF THAT SERVICE — the frozen-snapshot
 * invariant, checked here with real queries because the append-only
 * price_versions table holds rows for both `ServiceDefinition` and
 * `ServicePackageVersion` priceables and a caller could name either. The
 * line's `unit_amount`/`currency`/`price_version_number` are caller-supplied
 * but CROSS-CHECKED against the version's OWN stored values — an anchor
 * that contradicts its frozen version is refused — then validated and
 * converted exactly once (same as the package branch); `description` is
 * NOT accepted on a service line — it is derived from the service
 * definition's canonical name, so no line-level description can drift
 * from the catalogue.
 *
 * An unpriced service is refused here with `InvalidArgumentException`
 * (the referenced version is missing or not current) — consistent with
 * the package branch's own `InvalidArgumentException`. Composition-time
 * detection of "no current price exists at all" is the mapper's
 * `UnpricedBookingServiceException`; by the time a line reaches this
 * Action a concrete `price_version_id` is mandatory.
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
    private const string SERVICE_LINE = 'service';

    private const string PACKAGE_LINE = 'package';

    /**
     * ADR-0042. A plot line names `grave_plot_id` (what was sold) AND
     * `cemetery_package_id` (the pricing vehicle that produced the amount).
     */
    private const string PLOT_LINE = 'plot';

    /**
     * The family SETS a quote may carry, per ADR-0042. `{PLOT, SERVICE}` is
     * the pay-first path: a plot and the funeral services bought with it are
     * one pricing universe. PACKAGE stays exclusive, so the marketplace
     * invariant the original ruling protected is untouched.
     *
     * Sorted arrays, compared against a sorted set — the order lines arrive
     * in must not change the verdict.
     *
     * @var list<list<string>>
     */
    private const array LEGAL_FAMILY_SETS = [
        [self::PACKAGE_LINE],
        [self::SERVICE_LINE],
        [self::PLOT_LINE],
        [self::PLOT_LINE, self::SERVICE_LINE],
    ];

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
                    'service_definition_id' => $line['service_definition_id'],
                    'service_package_version_id' => $line['service_package_version_id'],
                    'grave_plot_id' => $line['grave_plot_id'] ?? null,
                    'cemetery_package_id' => $line['cemetery_package_id'] ?? null,
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
        $familiesSeen = [];
        $normalized = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException("Quote line [{$index}] must be an array.");
            }

            $lineFamily = $this->lineFamilyOf($line, $index);
            $familiesSeen[$lineFamily] = true;

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

            if ($lineFamily === self::SERVICE_LINE) {
                $normalized[] = $this->normalizeServiceLine($line, $index, $quantity, $unitAmountMinor, $lineCurrency, $fulfillmentOwner);

                continue;
            }

            if ($lineFamily === self::PLOT_LINE) {
                $normalized[] = $this->normalizePlotLine($line, $index, $quantity, $unitAmountMinor, $lineCurrency, $fulfillmentOwner);

                continue;
            }

            $servicePackageVersionId = (int) $this->requiredInt($line, 'service_package_version_id', $index);
            $version = ServicePackageVersion::query()->find($servicePackageVersionId);

            if (! $version instanceof ServicePackageVersion || ! $version->isPublished()) {
                throw new InvalidArgumentException(
                    "Quote line [{$index}] references service package version [{$servicePackageVersionId}], ".
                    'which is not a frozen published version.'
                );
            }

            $normalized[] = [
                'service_definition_id' => null,
                'service_package_version_id' => $servicePackageVersionId,
                'grave_plot_id' => null,
                'cemetery_package_id' => null,
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

        // ADR-0042: the SET of families present must be one of the legal
        // combinations. This is a per-set rule, so unlike the per-row rule it
        // has no database backstop; that is stated in the ADR rather than
        // assumed away.
        $present = array_keys($familiesSeen);
        sort($present);

        if (! in_array($present, self::LEGAL_FAMILY_SETS, true)) {
            throw new InvalidArgumentException(
                'A quote may not mix line families this way: got combination ['.
                implode(', ', $present).']. ADR-0042 permits [package], [service], [plot], '.
                'or [plot + service].'
            );
        }

        return $normalized;
    }

    /**
     * Exactly one family's key group must be present: `service_definition_id`
     * alone, `service_package_version_id` alone, or BOTH `grave_plot_id` and
     * `cemetery_package_id` (a plot line naming only one of that pair is as
     * ambiguous as naming none). Any other combination is refused outright.
     *
     * @param  array<string, mixed>  $line
     */
    private function lineFamilyOf(array $line, int $index): string
    {
        $families = [];

        if (array_key_exists('service_definition_id', $line)) {
            $families[] = self::SERVICE_LINE;
        }

        if (array_key_exists('service_package_version_id', $line)) {
            $families[] = self::PACKAGE_LINE;
        }

        // A plot line names BOTH keys, so both are required to claim the
        // family and neither alone is accepted — the same pair the
        // `quote_lines_line_family_check` constraint enforces in the database.
        if (array_key_exists('grave_plot_id', $line) || array_key_exists('cemetery_package_id', $line)) {
            if (! array_key_exists('grave_plot_id', $line) || ! array_key_exists('cemetery_package_id', $line)) {
                throw new InvalidArgumentException(
                    "Quote line [{$index}] is a plot line and must carry BOTH ".
                    '[grave_plot_id] and [cemetery_package_id].'
                );
            }

            $families[] = self::PLOT_LINE;
        }

        if (count($families) !== 1) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] must carry exactly one of ".
                '[service_definition_id] (service line), [service_package_version_id] (package line), '.
                'or [grave_plot_id]+[cemetery_package_id] (plot line).'
            );
        }

        return $families[0];
    }

    /**
     * A service line's frozen-snapshot branch: the named `PriceVersion` must
     * exist and be the CURRENT (non-superseded) version of the named
     * `ServiceDefinition` — never a stale or foreign version — and the line's
     * caller-supplied `unit_amount`/`currency`/`price_version_number` must
     * match that version's OWN stored anchor values (a contradicting anchor
     * is refused). `description` is derived from the definition's canonical
     * name; a caller-supplied value is neither accepted nor required.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizeServiceLine(
        array $line,
        int $index,
        int $quantity,
        int $unitAmountMinor,
        string $lineCurrency,
        string $fulfillmentOwner,
    ): array {
        $serviceDefinitionId = (int) $this->requiredInt($line, 'service_definition_id', $index);

        $definition = ServiceDefinition::query()->find($serviceDefinitionId);

        if (! $definition instanceof ServiceDefinition) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references unknown service definition [{$serviceDefinitionId}]."
            );
        }

        $priceVersionId = (int) $this->requiredInt($line, 'price_version_id', $index);
        $priceVersion = PriceVersion::query()->find($priceVersionId);

        if (! $priceVersion instanceof PriceVersion
            || ! $priceVersion->isCurrent()
            || $priceVersion->priceable_type !== ServiceDefinition::class
            || (int) $priceVersion->priceable_id !== $serviceDefinitionId) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references price version [{$priceVersionId}], ".
                "which is not the current price version of service definition [{$serviceDefinitionId}]."
            );
        }

        // The line's own anchor fields must not contradict the frozen
        // version it names: the stored amount/currency/version_number are
        // authoritative, so a caller-supplied mismatch is refused outright.
        $priceVersionNumber = $this->requiredInt($line, 'price_version_number', $index);

        if (Money::fromDecimal((string) $priceVersion->amount) !== $unitAmountMinor
            || $lineCurrency !== (string) $priceVersion->currency
            || $priceVersionNumber !== (int) $priceVersion->version_number) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] unit amount, currency, or version number contradicts ".
                "price version [{$priceVersionId}]'s frozen anchor."
            );
        }

        return [
            'service_definition_id' => $serviceDefinitionId,
            'service_package_version_id' => null,
            'grave_plot_id' => null,
            'cemetery_package_id' => null,
            'price_version_id' => $priceVersionId,
            'price_version_number' => $priceVersionNumber,
            'description' => $definition->name,
            'quantity' => $quantity,
            'unit_amount_minor' => $unitAmountMinor,
            'line_total_minor' => $this->lineTotalMinor($unitAmountMinor, $quantity),
            'currency' => $lineCurrency,
            'fulfillment_owner' => $fulfillmentOwner,
        ];
    }

    /**
     * A plot line's frozen-snapshot branch, mirroring the service branch.
     *
     * Four things are checked, and the fourth is the one most easily missed:
     * the named `PriceVersion` must exist, be CURRENT, belong to the named
     * `CemeteryPackage` — `price_versions` is polymorphic and holds rows for
     * `ServiceDefinition` and `ServicePackageVersion` too — and that package
     * must belong to the SAME cemetery as the plot, compared through the
     * plot's own path (`grave_plots.block_id` -> `cemetery_blocks.cemetery_id`).
     * Without the last one a draft could freeze another cemetery's package
     * price onto this plot, a defect visible only when somebody asks why the
     * amount is what it is.
     *
     * `grave_plot_id` is read with `requiredString`, not `requiredInt`:
     * `grave_plots.id` is a UUID (`2026_08_16_100010_create_grave_plots_
     * table.php`), while `cemetery_package_id` names `cemetery_packages.id`,
     * an ordinary bigint. The two columns are deliberately different types
     * and must not be coerced into one.
     *
     * `description` is derived from the plot and its package, never
     * caller-supplied, so no line description can drift from the catalogue.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizePlotLine(
        array $line,
        int $index,
        int $quantity,
        int $unitAmountMinor,
        string $lineCurrency,
        string $fulfillmentOwner,
    ): array {
        // Spec: "a plot line is always quantity 1" — a plot is a unique
        // physical unit, unlike a service or package line, which may
        // legitimately repeat. The composer already hardcodes 1
        // (`ComposeQuoteLinesFromBookingDraft::plotLines()`), so this is a
        // guard-layer backstop against any other caller.
        if ($quantity !== 1) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] is a plot line and must have quantity exactly 1, got [{$quantity}]."
            );
        }

        $gravePlotId = $this->requiredString($line, 'grave_plot_id', $index);
        $cemeteryPackageId = (int) $this->requiredInt($line, 'cemetery_package_id', $index);

        // `grave_plots.id` is a UUID column; comparing a non-UUID string
        // against it is a Postgres type error (`SQLSTATE[22P02]`), not a
        // miss — the same reason `BookingWizard::holdPlotForDiscovery()`
        // guards with `Str::isUuid()` before ever querying. Refusing here
        // keeps the readable-message contract layer 2 promises.
        if (! Str::isUuid($gravePlotId)) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references grave plot [{$gravePlotId}], which is not a valid UUID."
            );
        }

        $plot = GravePlot::query()->with('block')->find($gravePlotId);

        if (! $plot instanceof GravePlot) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references unknown grave plot [{$gravePlotId}]."
            );
        }

        $package = CemeteryPackage::query()->find($cemeteryPackageId);

        if (! $package instanceof CemeteryPackage) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references unknown cemetery package [{$cemeteryPackageId}]."
            );
        }

        if ((string) $package->cemetery_id !== (string) $plot->block?->cemetery_id) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] prices grave plot [{$gravePlotId}] with cemetery package ".
                "[{$cemeteryPackageId}], which belongs to a different cemetery."
            );
        }

        $priceVersionId = (int) $this->requiredInt($line, 'price_version_id', $index);
        $priceVersion = PriceVersion::query()->find($priceVersionId);

        if (! $priceVersion instanceof PriceVersion
            || ! $priceVersion->isCurrent()
            || $priceVersion->priceable_type !== CemeteryPackage::class
            || (int) $priceVersion->priceable_id !== $cemeteryPackageId) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references price version [{$priceVersionId}], ".
                "which is not the current price version of cemetery package [{$cemeteryPackageId}]."
            );
        }

        $priceVersionNumber = $this->requiredInt($line, 'price_version_number', $index);

        if (Money::fromDecimal((string) $priceVersion->amount) !== $unitAmountMinor
            || $lineCurrency !== (string) $priceVersion->currency
            || $priceVersionNumber !== (int) $priceVersion->version_number) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] unit amount, currency, or version number contradicts ".
                "price version [{$priceVersionId}]'s frozen anchor."
            );
        }

        return [
            'service_definition_id' => null,
            'service_package_version_id' => null,
            'grave_plot_id' => $gravePlotId,
            'cemetery_package_id' => $cemeteryPackageId,
            'price_version_id' => $priceVersionId,
            'price_version_number' => $priceVersionNumber,
            'description' => $package->name.' — '.$plot->slot,
            'quantity' => $quantity,
            'unit_amount_minor' => $unitAmountMinor,
            'line_total_minor' => $this->lineTotalMinor($unitAmountMinor, $quantity),
            'currency' => $lineCurrency,
            'fulfillment_owner' => $fulfillmentOwner,
        ];
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
