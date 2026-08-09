# Design — Public FAQ

## Data

`faq_categories`, `faq_articles`, `faq_article_versions`.

**Correction, 09 Aug 2026 (retrofit-faq):** this list omits `faq_article_related_article`, the pivot table backing AC4's related-articles requirement — it is a genuinely shipped table with no mandate in this document. Justified instead against `docs/product/faq-catalog.md`'s "Content behavior" section (see `database/migrations/2026_07_26_170300_create_faq_article_related_article_table.php:11-14`). Recorded here for completeness, not built or changed by this retrofit.

## Search

Database full-text/simple indexed search is sufficient for MVP. Search is not allowed to expose drafts.

## Routes

- `/faq`
- `/faq/kategori/{categorySlug}`
- `/faq/{articleSlug}`

## Open decisions

**Added 09 Aug 2026 (retrofit-faq — Task 2 whole-module review).** This section did not exist before this date; an earlier draft of the retrofit's own plan doc mistakenly quoted AC7 text as if it already lived here, which it did not — this entry is the first time the decision below has been recorded anywhere.

**AC7 enforcement — accepted as editorial review, not a code guard.** AC7 requires FAQ content to reflect active payment/Urgent gates and never make an unsupported claim. No FAQ code path resolves a `FeatureGate`/`ModeResolver` gate, and no test references AC7 — confirmed by three independent reviewers via direct grep of `app/Domain/Faq/`, `app/Livewire/Public/Faq/`, and `app/Filament/Admin/Resources/FaqArticles/`. This is deliberate, not an oversight: AC7's subject is free-text prose in `faq_articles.body` and `summary`, and no resolver can determine whether a paragraph of Indonesian text makes an unsupported availability/price/SLA claim — a real-time content-vs-gate guard is not an available mechanism.

Decision: **(a)** — editorial review is the accepted enforcement mechanism for AC7, operable today with no new code, using the gate-change history that already exists:
- **Trigger:** any change to `App\Platform\FeatureGate\ModeResolver::paymentMode()` or `::urgentMode()` obliges a review of FAQ articles in the affected categories before the change is announced.
- **Cadence:** the gate-change trigger above, plus a periodic sweep of the full catalogue (23 articles as of this retrofit, per `FaqArticleSeedTest.php:28`).
- **Owner:** the owner of the canonical FAQ catalogue, `docs/product/faq-catalog.md`. **A specific name requires human assignment — not filled in by this retrofit.**
- **Escalation condition for revisiting this decision:** if the catalogue grows past hand-review scale, or gate modes begin changing often enough that a full-catalogue review per change stops happening in practice, reconsider the narrower alternative below.

**Rejected for now — narrower alternative (b):** record which gate each article's content depends on (a new column or pivot) and flag a review only when that gate's mode changes. Not adopted because it does not itself verify content (it routes to the same editorial review as (a), only more precisely targeted), the review trigger it would provide already exists via the gate-change history above, and it requires a migration against a table already deployed to `dev.makam.co.id` plus an admin form change — a feature, not a review-and-fix retrofit's bounded scope. Reconsider if the escalation condition above is met.
