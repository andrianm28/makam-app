<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry;

/**
 * The result of one public grave search, shaped so the three empty states
 * `.kiro/specs/renewal-and-grave-registry/tasks.md` requires cannot be
 * collapsed into one message by the view that renders it.
 *
 * That collapse is the documented defect this spec calls out — "a family
 * searching for a grave record and finding nothing must not conclude the
 * grave does not exist" — so the distinction is enforced in the DATA, not
 * left to a Blade `@if` chain to get right. A view cannot render
 * "tidak ditemukan" for a restricted match, because `openResults` being
 * empty and `restrictedCount` being non-zero are two independently readable
 * facts here, not one `isEmpty()`.
 *
 * The third state — `G-DATA-01` closed entirely (AC16) — is deliberately
 * NOT representable by this class. It is not a search result; it is the
 * search never having run. `GraveRegistryPublicQuery::search()` is not
 * called at all in that case, and the screen renders design-system.md
 * §6.4's explanatory gate-closed page instead. Modelling it as a fourth
 * outcome status here would invite exactly the collapse this class exists
 * to prevent.
 *
 * @phpstan-type Projections list<GraveRecordProjection>
 */
final readonly class GraveSearchOutcome
{
    /**
     * @param  list<GraveRecordProjection>  $openResults  fully-readable rows (AC14 `open`)
     * @param  list<GraveRecordProjection>  $restrictedResults  rows the configured
     *                                                          access mode withholds
     *                                                          fields from (`limited`
     *                                                          or `closed`) — matched
     *                                                          and counted, never
     *                                                          dropped
     */
    public function __construct(
        public array $openResults,
        public array $restrictedResults,
    ) {}

    public static function empty(): self
    {
        return new self(openResults: [], restrictedResults: []);
    }

    /**
     * Total rows the search matched, regardless of what may be shown of
     * them. This is the number that makes the no-result state honest: it is
     * zero if and only if nothing matched.
     */
    public function matchCount(): int
    {
        return count($this->openResults) + count($this->restrictedResults);
    }

    public function restrictedCount(): int
    {
        return count($this->restrictedResults);
    }

    /**
     * §6.2 *no-result* — the search ran and matched nothing at all. The
     * only one of the three states that may say the registry has no such
     * record, and even then it must say the registry may be incomplete
     * (AC5's honest manual-entry / customer-service path).
     */
    public function isNoResult(): bool
    {
        return $this->matchCount() === 0;
    }

    /**
     * §6.2 *privacy-limited* — at least one record matched but at least one
     * matched record withholds fields. Distinct from `isNoResult()` and
     * NEVER worded as "not found": the record demonstrably exists.
     *
     * Can be true at the same time as `hasOpenResults()` — a search may
     * match three records of which one is restricted. In that case the page
     * renders the readable rows AND says plainly that a further match
     * exists it cannot show, rather than quietly under-reporting the count.
     */
    public function isPrivacyLimited(): bool
    {
        return $this->restrictedResults !== [];
    }

    public function hasOpenResults(): bool
    {
        return $this->openResults !== [];
    }
}
