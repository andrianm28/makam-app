<?php

declare(strict_types=1);

namespace App\Platform\Correlation;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Stringable;

/**
 * Immutable value object wrapping a validated, non-empty correlation id —
 * `platform-audit` design.md line 36: "`correlation_id` originates at the
 * request boundary and is propagated into outbox events, queue jobs,
 * provider calls, and notifications, so a single identifier reconstructs a
 * full flow."
 *
 * ---------------------------------------------------------------------------
 * Why every id is validated, not just the ones that came from a header
 * ---------------------------------------------------------------------------
 * `SAFE_CHARACTERS_PATTERN` is a conservative allowlist
 * (`^[A-Za-z0-9_-]{1,128}$`, with the `D` modifier — see below) rather than
 * a denylist of "bad" characters. This id ends up in three risky sinks
 * later: an `audit_events.correlation_id` column another spec's queries
 * read back, application logs, and an `X-Correlation-Id` response header
 * echoed to whatever called us. An attacker-controlled inbound
 * `X-Correlation-Id` header is the one realistic way an arbitrary string
 * reaches this class (`AssignCorrelationId` reads it directly off the
 * request) — accepting it unvalidated would let a caller inject newlines
 * (log/header injection), SQL-hostile characters, or arbitrary markup into
 * every one of those sinks. Validating unconditionally in `fromString()`
 * (rather than "only validate header input, trust everything else") means
 * there is exactly one path through this class that can produce an invalid
 * id: none — matching this codebase's `GateState`-style preference for
 * making the invalid state structurally unrepresentable rather than
 * relying on every caller to remember to check.
 *
 * The `D` modifier matters and is not decorative: PCRE's `$` anchor, by
 * default, matches either at the true end of the subject OR immediately
 * before a single trailing "\n" — so `/^[A-Za-z0-9_-]{1,128}$/` alone would
 * incorrectly accept `"valid-id\n"` (a trailing-newline log/header
 * injection payload that LOOKS like a clean match). `D` (PCRE_DOLLAR_ENDONLY)
 * forces `$` to match only the true end of the string, closing that gap.
 *
 * ---------------------------------------------------------------------------
 * Single factory, not a `GateState`-style set of named constructors
 * ---------------------------------------------------------------------------
 * `GateState` uses multiple named factories because each one encodes a
 * DIFFERENT resolved state (a real gate row vs. "unknown" vs.
 * "misconfigured") that its own consumers need to branch on. A
 * `CorrelationId` has no such distinct states — it is either a validated id
 * or it does not exist — so a single `fromString()` (plus `generate()` for
 * "make a fresh one") is the whole shape. The "is this from an incoming
 * header or freshly generated" distinction the batch brief raised as a
 * possibility belongs to the CALLER (`AssignCorrelationId` decides which
 * factory to invoke and why), not to this value object — this class does
 * not need to remember its own provenance after construction.
 */
final readonly class CorrelationId implements Stringable
{
    public const string SAFE_CHARACTERS_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

    private function __construct(
        public string $value,
    ) {}

    /**
     * A fresh, always-valid correlation id (UUID v4). Used whenever there
     * is no acceptable inbound id to reuse — no header at all, or a header
     * that failed `isValid()`.
     */
    public static function generate(): self
    {
        return new self((string) Str::uuid());
    }

    /**
     * Build from a string already known (or believed) to be safe — e.g. a
     * request header value the caller has already checked with `isValid()`,
     * or a value captured earlier in-process by `CarriesCorrelationId`.
     * Still re-validates unconditionally (see class-level note); throws
     * rather than silently coercing, because every caller in this codebase
     * is expected to call `isValid()` first and branch to `generate()`
     * itself — reaching this exception means that contract was violated.
     *
     * @throws InvalidArgumentException if `$value` fails `isValid()`.
     */
    public static function fromString(string $value): self
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException(
                'CorrelationId value failed the safe-character allowlist.'
            );
        }

        return new self($value);
    }

    /**
     * Pure predicate, no exceptions — lets a caller (middleware, trait)
     * decide what to do with an unsafe value (e.g. fall back to
     * `generate()`) without wrapping `fromString()` in a try/catch.
     */
    public static function isValid(string $value): bool
    {
        return $value !== '' && preg_match(self::SAFE_CHARACTERS_PATTERN, $value) === 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
