<?php

declare(strict_types=1);

namespace App\Platform\Payment\Providers;

/**
 * What `SumoPodWebhookSignature::verify()` concluded.
 *
 * Deliberately provider-shaped, not storage-shaped: this enum knows nothing
 * about `ProviderEventStatus`. `WebhookValidator` owns the mapping from an
 * outcome to a recorded rejection status, so swapping the provider later
 * changes this file and not the contract's failure-state vocabulary.
 *
 * The one mapping worth stating up front: `TimestampOutsideWindow` becomes
 * `REJECTED_REPLAY`, and every other failure becomes `REJECTED_SIGNATURE`.
 * That split is only meaningful because the signature is verified BEFORE the
 * timestamp — a `TimestampOutsideWindow` is therefore always an authentic
 * delivery arriving too late, i.e. an actual replay, never a guess.
 */
enum SignatureOutcome
{
    /** Authentic — see `SignatureVerification::$mechanism` for which path. */
    case Verified;

    /** No signing secret is configured. Fail closed. */
    case NotConfigured;

    /** No Svix headers, and the shared-token mechanism is absent or disabled. */
    case MechanismUnavailable;

    /** Svix headers present but incomplete — an id/timestamp/signature is missing. */
    case MalformedSignatureHeader;

    /** Credential present and well-formed, but it does not match. */
    case SignatureMismatch;

    /** `svix-timestamp` is not an integer number of seconds. */
    case TimestampMalformed;

    /** Authentic, but outside the configured replay window (AC6). */
    case TimestampOutsideWindow;
}
