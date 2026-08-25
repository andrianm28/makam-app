<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Exceptions;

use DomainException;

/**
 * The domain translation of a `certificates_issuer_type_reference_unique`
 * violation — AC7's "document-number uniqueness per issuer and type" as
 * a database guarantee rather than an application convention.
 *
 * ---------------------------------------------------------------------------
 * Why this exists rather than letting the `QueryException` propagate
 * ---------------------------------------------------------------------------
 * Exactly the two reasons `OrderAlreadyPaidException` documents, and the
 * second is the load-bearing one: a caller cannot distinguish "this
 * document number already exists for this issuer and type" (an honest
 * refusal) from any other write failure when the violation arrives as a
 * driver-specific `QueryException` — it surfaces as an opaque 500
 * instead of a meaningful domain outcome.
 *
 * And `Illuminate\Database\QueryException::formatMessage()` appends the
 * full INSERT statement with its BINDINGS INTERPOLATED. Those bindings
 * include the certificate reference, the subject identifiers, and the
 * issuer reference; an uncaught throw is logged verbatim, putting
 * caller-supplied content into the log — which `AGENTS.md` §Observability
 * forbids. Because of that, the originating `QueryException` is
 * deliberately NOT attached as this exception's `$previous`: the
 * framework's exception handler logs the whole chain, so chaining it
 * would reintroduce exactly the interpolated bindings this translation
 * exists to keep out of the log. The references below are identifiers,
 * never content.
 */
final class CertificateReferenceCollisionException extends DomainException
{
    public static function forIssuerAndType(string $issuerRef, string $type): self
    {
        return new self(
            "A certificate reference collision was detected for issuer [{$issuerRef}] and type [{$type}]; a document number must be unique per issuer and type (AC7)."
        );
    }
}
