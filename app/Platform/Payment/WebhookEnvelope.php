<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\FinancialLedger\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One inbound provider body, read once.
 *
 * `payment-webhook.md` §Required envelope lists what a webhook must carry;
 * ADR-0033 §Decision gives SumoPod's concrete shape for it:
 *
 *   `event_type`, `data.payment_id`, `data.order_id`, `data.amount`,
 *   `data.fee`, `data.net_amount`, `data.status`, `data.payment_method`,
 *   `data.completed_at`
 *
 * mapped to the contract's vocabulary as: provider transaction ID =
 * `data.payment_id`; invoice/order reference = `data.order_id`; event type =
 * `event_type`; amount = `data.amount`.
 *
 * Two contract fields have NO field in ADR-0033's envelope, and both are
 * handled by looking elsewhere rather than by inventing one:
 *   - **merchant / `badan_usaha` identifier** — supplied instead by the
 *     merchant-scoped route segment and reconciled against the session's
 *     binding (AC13). See `WebhookValidator`.
 *   - **currency** — read opportunistically from `data.currency`/`currency`
 *     if the provider ever sends one, and otherwise left null so AC6's
 *     currency check falls to the session's own currency.
 *
 * The provider EVENT ID is likewise absent from the body: it is the `svix-id`
 * header. `ReceiveWebhook` resolves it, not this class.
 *
 * ---------------------------------------------------------------------------
 * Parsed once, and only from the raw body
 * ---------------------------------------------------------------------------
 * The plan's Task 3: "Read raw body (never JSON-decoded-again after persist)."
 * The signature is computed over the exact bytes received, so re-encoding a
 * decoded structure and validating that would validate something the provider
 * never signed. `parse()` is the single decode; `ProviderEvent::$raw_payload`
 * keeps the bytes.
 *
 * ---------------------------------------------------------------------------
 * No float reaches the money path (Wave 0 ruling 0c)
 * ---------------------------------------------------------------------------
 * `data.amount` is accepted as an integer, as a numeric string, or as a JSON
 * number whose value is exactly integral (`10000.00` decodes to the double
 * 10000.0, which is exact and loses nothing). Anything with real fractional
 * content is refused as `NonIntegerAmount` rather than rounded — this is the
 * amount AC6 compares against a payment session, so a value this class is not
 * certain of must not become a number the comparison trusts. IDR amounts are
 * whole rupiah in practice, so the refused shape is not expected to occur;
 * Task 8's sandbox smoke run is where a real body would prove otherwise.
 *
 * Conversion to integer minor units goes through `Money::fromDecimal()`, the
 * financial-ledger lane's read seam (consumed read-only here), so this module
 * never re-implements the minor-unit factor.
 */
final readonly class WebhookEnvelope
{
    private function __construct(
        public ?string $eventType = null,
        public ?string $providerTransactionId = null,
        public ?string $invoiceReference = null,
        public ?int $amountMinor = null,
        public ?string $declaredCurrency = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?EnvelopeProblem $problem = null,
    ) {}

    public static function parse(string $rawBody): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return new self(problem: EnvelopeProblem::MalformedJson);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return new self(problem: EnvelopeProblem::NotAnObject);
        }

        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        $eventType = self::stringOrNull($decoded['event_type'] ?? null);
        $transactionId = self::stringOrNull($data['payment_id'] ?? null);
        $invoiceReference = self::stringOrNull($data['order_id'] ?? null);
        $currency = self::stringOrNull($data['currency'] ?? $decoded['currency'] ?? null);
        $occurredAt = self::timestampOrNull($data['completed_at'] ?? null);

        // Every extracted field is carried even when a later one fails, so the
        // durable `provider_events` row is as informative as the body allowed.
        $partial = static fn (EnvelopeProblem $problem, ?int $amountMinor = null): self => new self(
            eventType: $eventType,
            providerTransactionId: $transactionId,
            invoiceReference: $invoiceReference,
            amountMinor: $amountMinor,
            declaredCurrency: $currency,
            occurredAt: $occurredAt,
            problem: $problem,
        );

        if ($eventType === null) {
            return $partial(EnvelopeProblem::MissingEventType);
        }

        if ($transactionId === null) {
            return $partial(EnvelopeProblem::MissingTransactionId);
        }

        if ($invoiceReference === null) {
            return $partial(EnvelopeProblem::MissingInvoiceReference);
        }

        if (! array_key_exists('amount', $data)) {
            return $partial(EnvelopeProblem::MissingAmount);
        }

        $decimal = self::exactDecimalString($data['amount']);

        if ($decimal === null) {
            return $partial(EnvelopeProblem::NonIntegerAmount);
        }

        try {
            $amountMinor = Money::fromDecimal($decimal);
        } catch (Throwable) {
            return $partial(EnvelopeProblem::AmountOutOfRange);
        }

        return new self(
            eventType: $eventType,
            providerTransactionId: $transactionId,
            invoiceReference: $invoiceReference,
            amountMinor: $amountMinor,
            declaredCurrency: $currency,
            occurredAt: $occurredAt,
        );
    }

    public function isWellFormed(): bool
    {
        return $this->problem === null;
    }

    public function type(): ?ProviderEventType
    {
        return $this->eventType === null ? null : ProviderEventType::tryFrom($this->eventType);
    }

    /**
     * The raw `data.amount` as a decimal string this module is willing to
     * convert exactly, or null. See the class doc block for the float rule.
     */
    private static function exactDecimalString(mixed $amount): ?string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }

        if (is_float($amount)) {
            // Exactly integral doubles convert losslessly; anything else is
            // refused rather than rounded.
            if (! is_finite($amount) || floor($amount) !== $amount || abs($amount) > 9.007199254740992e15) {
                return null;
            }

            return number_format($amount, 0, '.', '');
        }

        if (is_string($amount) && preg_match('/\A-?\d+(\.\d+)?\z/D', trim($amount)) === 1) {
            return trim($amount);
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    private static function timestampOrNull(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            // A provider timestamp this module cannot read is not a reason to
            // reject the event — it is not the replay-window source (that is
            // the signed `svix-timestamp`), only a recorded detail.
            return null;
        }
    }
}
