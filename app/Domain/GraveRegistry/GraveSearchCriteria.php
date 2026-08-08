<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry;

/**
 * The three search inputs requirements.md AC3 names — "fuzzy search by
 * deceased name, block, and death date" — scoped to the one cemetery the
 * renewal journey's steps 1 and 2 (AC1) have already chosen.
 *
 * These are exactly the query parameters `docs/contracts/openapi.yaml`
 * already fixes for `GET /grave-records/search` (`name`, `block`,
 * `death_date`), checked against that contract rather than invented here.
 * The contract does not carry a cemetery parameter; this batch scopes by
 * cemetery anyway because the public journey cannot reach the search step
 * without having chosen one, and an unscoped registry-wide name search is a
 * materially different privacy surface than the one AC14 was written for.
 * Flagged rather than silently widened — if the HTTP API is later built to
 * the contract as literally written, that difference is a decision someone
 * must make on purpose.
 *
 * Values are trimmed on construction and never normalized here — name
 * normalization belongs to `GraveNameNormalizer` and is applied by
 * `GraveRegistryPublicQuery` at the point of comparison, so both sides of
 * the trigram comparison provably use one function.
 */
final readonly class GraveSearchCriteria
{
    private function __construct(
        public string $cemeteryId,
        public string $name,
        public string $block,
        public string $deathDate,
    ) {}

    public static function make(
        string $cemeteryId,
        ?string $name = null,
        ?string $block = null,
        ?string $deathDate = null,
    ): self {
        return new self(
            cemeteryId: trim($cemeteryId),
            name: trim($name ?? ''),
            block: trim($block ?? ''),
            deathDate: trim($deathDate ?? ''),
        );
    }

    /**
     * `false` when every one of the three inputs is blank. A search with no
     * terms would match the whole cemetery's registry, which is a bulk
     * disclosure rather than a search — `GraveRegistryPublicQuery::search()`
     * refuses to run it.
     */
    public function hasAnyTerm(): bool
    {
        return $this->name !== '' || $this->block !== '' || $this->deathDate !== '';
    }
}
