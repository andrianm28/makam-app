<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Exceptions;

use RuntimeException;

/**
 * The single refusal every read-side denial raises — AC9's "no existence
 * leak" made structural rather than left to caller discipline.
 *
 * There is deliberately only ONE factory and ONE fixed message. A document
 * the actor may not see, a document that does not exist at all, and a
 * document that exists and is related but has not reached `ACCEPTED` yet
 * (AC7) all raise this exact exception, with an identical message and code,
 * so nothing about the vault's contents can be inferred from a refusal.
 *
 * The message deliberately carries no document id, no filename, no state and
 * no purpose. Everything an operator needs to distinguish those cases is
 * written to `document_access_events` and `audit_events` instead, where it is
 * access-controlled — never handed back to the caller who was just refused.
 *
 * Task 7's private download route maps this to a 404 (never a 403, which
 * would itself confirm the resource exists).
 */
final class DocumentAccessDeniedException extends RuntimeException
{
    private const string MESSAGE = 'The requested document is not available.';

    public static function denied(): self
    {
        return new self(self::MESSAGE);
    }
}
