<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The ONE place this codebase's placeholder legal-entity details (company
 * name, registered address) are defined — used by the footer's small
 * company-info line and by the two legal pages (`App\Livewire\Public\
 * Legal\PrivacyPolicy`, `TermsOfService`). Same single-source discipline
 * `App\Support\ContactInfo` already establishes in this codebase for
 * placeholder contact details (AGENTS.md §Documentation: "Do not duplicate
 * canonical catalog data in multiple hand-maintained documents or code
 * locations") — do NOT hardcode `NAME`/`ADDRESS` a second time anywhere
 * else; read from this class instead.
 *
 * ---------------------------------------------------------------------------
 * These are DUMMY values, not a real registered company — read before changing
 * ---------------------------------------------------------------------------
 * No real legal entity, registered address, or company name has ever been
 * defined anywhere in this repository. The user has explicitly authorized
 * filling this gap with clearly fictional placeholder data for full public
 * display on the dev environment (dev.makam.co.id is a real, public,
 * non-production site by design — synthetic data is the correct content
 * type there).
 *
 * `NAME` uses the literal word "Contoh" ("Example") — the same honesty
 * marker word `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`
 * already established for this codebase's placeholder addresses — so
 * nothing here could be mistaken for a real registered PT. `ADDRESS` reuses
 * that exact migration's "Jl. Contoh ..." placeholder-street convention,
 * with a street name and sub-area not used by any of that migration's ten
 * seeded cemetery addresses, so this is never confusable with any of those
 * fixture rows.
 *
 * A future real-business-data batch replaces these two values with real
 * ones (and adds the real legal review the "Terakhir diperbarui" line on
 * both legal pages says is still pending) in this one file; nothing else
 * needs to change.
 */
final class CompanyInfo
{
    public const string NAME = 'PT Contoh Makam Digital Indonesia';

    public const string ADDRESS = 'Jl. Contoh Cendana No. 88, Kuningan, Jakarta Selatan';
}
