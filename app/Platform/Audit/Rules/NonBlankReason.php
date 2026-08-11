<?php

declare(strict_types=1);

namespace App\Platform\Audit\Rules;

use App\Platform\Audit\Audit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a reason that `Audit::record()` would reject, at the HTTP
 * boundary, so the operator gets a 422 naming the field instead of a 500.
 *
 * `required` does not cover this, and neither does Laravel's `TrimStrings`
 * middleware. `TrimStrings` strips `Str::INVISIBLE_CHARACTERS` plus Unicode
 * `\s`, which does catch the obvious cases — a lone non-breaking space or
 * ideographic space arrives as `''` and `required` rejects it. But
 * `Audit::reasonIsBlank()` treats the whole of `\p{C}` as blank, and that
 * is strictly wider: a control character (U+0001) or a private-use
 * character (U+E000) survives `TrimStrings`, satisfies
 * `['required', 'string']`, reaches `Audit::record()`, and raises
 * `AuditReasonRequiredException` — a plain `RuntimeException` with no
 * `render()` and no `HttpExceptionInterface`, so Laravel renders it as a
 * 500 and the operator's typed input is lost.
 *
 * The audit outcome was already correct in that case (`Audit::wrap()` rolls
 * the transaction back, so nothing partial survives); only the reporting
 * was wrong. A validation failure should not look like a server fault, and
 * should not page whoever watches the error rate.
 *
 * Delegates to `Audit::reasonIsBlank()` rather than repeating the regex.
 * A copy here could drift from the authoritative check, and the two
 * disagreeing is worse than either being wrong alone: it would mean a
 * reason accepted at the boundary and rejected in the transaction, which
 * is the 500 this rule exists to remove.
 */
final class NonBlankReason implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (Audit::reasonIsBlank($value)) {
            $fail('The :attribute field must contain readable text.');
        }
    }
}
