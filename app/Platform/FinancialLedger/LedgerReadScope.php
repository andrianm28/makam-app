<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

/**
 * The server-derived answer to "which badan usaha may this actor read, and
 * under which role" — the return value of `Contracts\LedgerReadAuthorizer`.
 *
 * `$entityRefs` is the QUERY-LEVEL SCOPE `AGENTS.md` §Authorization makes
 * mandatory ("Policies and query-level scope are mandatory. Scope by …
 * business entity"), not a display hint. Every ledger read mounted behind an
 * authorizer filters on it, so there is no code path that produces a report
 * spanning a badan usaha the actor holds no grant for.
 *
 * It is guaranteed non-empty: an authorizer that finds no grant refuses with
 * `Exceptions\LedgerReadNotAuthorisedException` rather than returning an empty
 * list, precisely so a caller that forgets to check cannot silently degrade
 * "no permitted entities" into "no filter, therefore all entities" — the
 * `$entityRef ?? 'all'` shape this class exists to make unexpressible.
 */
final readonly class LedgerReadScope
{
    /**
     * @param  string  $role  The role that granted the read, for the audit row
     *                        — server-derived, never caller-supplied.
     * @param  list<string>  $entityRefs  Non-empty, sorted, duplicate-free.
     */
    public function __construct(
        public string $role,
        public array $entityRefs,
    ) {}
}
