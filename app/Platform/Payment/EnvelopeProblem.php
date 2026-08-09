<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * Why a webhook body could not be read as `docs/contracts/payment-webhook.md`
 * §Required envelope describes.
 *
 * Each case is a field NAME or a shape, never a field VALUE: these strings end
 * up in `provider_events.rejection_detail` and in an audit `note`, and
 * `AGENTS.md` §Observability forbids restricted data in either.
 */
enum EnvelopeProblem: string
{
    case MalformedJson = 'malformed_json';
    case NotAnObject = 'not_a_json_object';
    case MissingEventType = 'missing_event_type';
    case MissingTransactionId = 'missing_data_payment_id';
    case MissingInvoiceReference = 'missing_data_order_id';
    case MissingAmount = 'missing_data_amount';

    case FieldTooLong = 'field_too_long';

    /**
     * The amount arrived as a JSON number with fractional content. Wave 0
     * ruling 0c admits no float on the money path, and a fractional JSON
     * number cannot be converted to integer minor units without trusting a
     * binary floating-point value — so it is refused rather than rounded.
     */
    case NonIntegerAmount = 'non_integer_amount';

    case AmountOutOfRange = 'amount_out_of_range';
}
