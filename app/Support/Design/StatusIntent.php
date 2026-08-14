<?php

declare(strict_types=1);

namespace App\Support\Design;

use Illuminate\Support\Facades\Log;

/**
 * StatusIntent — resolves a domain status string to a presentation triple:
 * (intent, icon, Indonesian label).
 *
 * design-system.md §3.7 (normative): "Components must not switch on enum
 * strings. Resolve status -> intent in ONE place." This class is that one
 * place. Every `<x-mk.*>` Blade view and every Filament status column MUST
 * call through here instead of `match`-ing (or `if`-chaining) an enum
 * locally. §9.2 MUST #5 repeats the same rule as an enforceable MUST.
 *
 * Status VALUES ARE NOT DEFINED HERE. They are canonical in
 * docs/domain/order-lifecycle.md and docs/product/marketplace-catalog.md
 * (`AGENTS.md` §Documentation forbids duplicating canonical catalogue data
 * in multiple hand-maintained locations). This class only maps an
 * already-canonical status string onto an intent name, an icon identifier,
 * and a label — never a raw colour/hex value (§9.2 MUST-NOT #1); actual
 * colour resolution for the public site stays in the Blade component layer
 * via the existing `--mk-intent-*` tokens (see badge.blade.php, card.blade.php).
 *
 * ---------------------------------------------------------------------------
 * Family scoping — design decision
 * ---------------------------------------------------------------------------
 * A bare status string is not guaranteed unique across domains. The batch
 * brief for this class flagged order-lifecycle vs. vendor-processing as a
 * *theoretical* collision risk; checked against design-system.md §3.7 today,
 * the actual overlap is `DIPROSES`, `SELESAI`, and `DIBATALKAN`, and all
 * three resolve to the SAME intent+icon in both tables. So:
 *
 *   - A caller that knows its domain SHOULD still pass $family explicitly
 *     (see FAMILY_* constants) — it is cheap and removes all ambiguity.
 *   - If $family is omitted, every registered family is checked:
 *       - exactly one family defines the status            -> use it.
 *       - multiple families define it and all AGREE          -> use it
 *         (today's real case: DIPROSES / SELESAI / DIBATALKAN).
 *       - multiple families define it and DISAGREE            -> this is a
 *         genuine collision; resolving it silently would be a guess, so it
 *         falls back to `neutral` and logs a warning asking the caller to
 *         pass $family. No such disagreement exists in the two families
 *         populated today (see below) — this branch exists for the future.
 *
 * ---------------------------------------------------------------------------
 * Extending to another domain
 * ---------------------------------------------------------------------------
 * Three families are populated below: order lifecycle, vendor processing,
 * and marketplace payment — the tables design-system.md §3.7 normatively
 * defines (`PaymentState`'s table was added 14 Aug 2026 with the
 * marketplace-checkout lane). Grepping the other ~6 specs this class is
 * meant to serve
 * (booking-and-order-orchestration, funeral-marketplace-and-vendor-portal,
 * admin-operations, funeral-case-management, recurring-care-subscriptions,
 * pre-need-contracting, plot-inventory-and-reservation) turned up additional
 * status enums that design-system.md does NOT yet map to an intent/icon:
 *
 *   - funeral-case-management: NEW, TRIAGED, COORDINATING,
 *     READY_FOR_SERVICE, IN_SERVICE, COMPLETED, DECLINED, CANCELLED,
 *     TRANSFERRED (.kiro/specs/funeral-case-management/design.md §Statuses)
 *   - recurring-care-subscriptions: subscription DRAFT/ACTIVE/PAUSED/
 *     ENDED/CANCELLED and cycle SCHEDULED/INVOICED/PAID/WORK_SCHEDULED/
 *     COMPLETED/EXPIRED (.kiro/specs/recurring-care-subscriptions/design.md
 *     §Statuses)
 *   - pre-need-contracting: INTEREST, CONSULTING, PROPOSED, RESERVED,
 *     CONTRACT_PENDING, ACTIVE_PAYMENT, SETTLED, CERTIFIED, ACTIVATED,
 *     CANCELLED, DEFAULTED — the spec itself marks this list "final
 *     approval required before implementation", i.e. provisional.
 *
 * Assigning those an intent/icon is a design decision (which colour family,
 * which icon, whether e.g. `CANCELLED` reads `neutral` or `danger`) — the
 * same kind of call design-system.md §3.7 made explicitly and on the
 * record. Guessing it here, in code, with no design review, would be
 * exactly the kind of undocumented product/design decision `AGENTS.md` and
 * design-system.md §0.1 forbid an implementation detail from making
 * silently. So: NOT implemented. To extend, add a `FAMILY_*` constant and a
 * new entry in `MAP` below, sourced from a design-system.md update (a new
 * §3.7 table), not invented in this file.
 *
 * `plot-inventory-and-reservation`'s design.md does not define a status
 * enum in its current form (only table ownership) — nothing to map yet.
 *
 * `order-lifecycle.md` §5 also defines a THIRD, separate Pre-Need family
 * (`INTEREST_REGISTERED -> CONTACTED -> CLOSED`) that does not match
 * pre-need-contracting's own list above at all (different vocabulary
 * entirely: INTEREST_REGISTERED/CONTACTED/CLOSED vs. INTEREST/CONSULTING/
 * PROPOSED/...). That is a contradiction between two canonical documents,
 * not something this class can silently resolve by picking one — surfaced
 * as a finding in this batch's report, not fixed here.
 */
final class StatusIntent
{
    public const FAMILY_ORDER_LIFECYCLE = 'order_lifecycle';

    public const FAMILY_VENDOR_PROCESSING = 'vendor_processing';

    public const FAMILY_MARKETPLACE_PAYMENT = 'marketplace_payment';

    public const INTENT_NEUTRAL = 'neutral';

    public const INTENT_INFO = 'info';

    public const INTENT_PENDING = 'pending';

    public const INTENT_SUCCESS = 'success';

    public const INTENT_DANGER = 'danger';

    public const INTENT_URGENT = 'urgent';

    /**
     * Matches badge.blade.php's `$validIntents` list exactly (§3.6/§3.7) —
     * kept here as the canonical set so Blade-facing code can validate
     * against it instead of re-listing the six names itself.
     *
     * @var list<string>
     */
    public const INTENTS = [
        self::INTENT_NEUTRAL,
        self::INTENT_INFO,
        self::INTENT_PENDING,
        self::INTENT_SUCCESS,
        self::INTENT_DANGER,
        self::INTENT_URGENT,
    ];

    /**
     * Defensive fallback for an unrecognised status or a genuine cross-family
     * collision — matches the pattern already used by button.blade.php
     * (unknown $variant falls back to 'secondary') and card.blade.php
     * (unknown $intent falls back to null/no intent styling): never throw,
     * never crash a table render, degrade to the most neutral valid state.
     */
    private const FALLBACK_INTENT = self::INTENT_NEUTRAL;

    private const FALLBACK_ICON = 'question-mark-circle';

    /**
     * design-system.md §3.7 "Order lifecycle" and "Vendor processing"
     * tables, transcribed verbatim (intent + icon columns only — the
     * "Rationale" column is documentation, not data this class needs).
     * Status VALUES are canonical in docs/domain/order-lifecycle.md and
     * docs/product/marketplace-catalog.md; this array is the mapping the
     * design system defines on top of them, not a restatement of the state
     * machine itself.
     *
     * @var array<string, array<string, array{intent: string, icon: string}>>
     */
    private const MAP = [
        self::FAMILY_ORDER_LIFECYCLE => [
            'MASUK' => ['intent' => self::INTENT_NEUTRAL, 'icon' => 'inbox'],
            'DIVERIFIKASI' => ['intent' => self::INTENT_INFO, 'icon' => 'shield-check'],
            'MENUNGGU_KETERSEDIAAN' => ['intent' => self::INTENT_PENDING, 'icon' => 'clock'],
            'PENAWARAN_TERKIRIM' => ['intent' => self::INTENT_INFO, 'icon' => 'document-text'],
            'DISETUJUI_PEMESAN' => ['intent' => self::INTENT_INFO, 'icon' => 'check-circle'],
            'MENUNGGU_PEMBAYARAN' => ['intent' => self::INTENT_PENDING, 'icon' => 'clock'],
            // Manual payment-verification fallback. design-system.md §3.7 is
            // explicit and normative: "Never `success`." Do not "simplify"
            // this to success just because money has technically arrived —
            // it is unverified until an admin confirms it.
            'MENUNGGU_VERIFIKASI_PEMBAYARAN' => ['intent' => self::INTENT_PENDING, 'icon' => 'clock'],
            'DIBAYAR' => ['intent' => self::INTENT_SUCCESS, 'icon' => 'banknote'],
            'DIPROSES' => ['intent' => self::INTENT_INFO, 'icon' => 'cog'],
            // `DIBAYAR` != `SELESAI` — design-system.md §3.7 states this
            // explicitly: "Paid does not mean completed." Do not collapse
            // these two into one "done" intent.
            'SELESAI' => ['intent' => self::INTENT_SUCCESS, 'icon' => 'check-badge'],
            'DITOLAK' => ['intent' => self::INTENT_DANGER, 'icon' => 'x-circle'],
            'DIBATALKAN' => ['intent' => self::INTENT_NEUTRAL, 'icon' => 'slash'],
            'KEDALUWARSA' => ['intent' => self::INTENT_NEUTRAL, 'icon' => 'clock-x'],
        ],
        self::FAMILY_VENDOR_PROCESSING => [
            'MENUNGGU_VENDOR' => ['intent' => self::INTENT_PENDING, 'icon' => 'clock'],
            'DITERIMA_VENDOR' => ['intent' => self::INTENT_INFO, 'icon' => 'check-circle'],
            'DITOLAK_VENDOR' => ['intent' => self::INTENT_DANGER, 'icon' => 'x-circle'],
            'DIPROSES' => ['intent' => self::INTENT_INFO, 'icon' => 'cog'],
            'DIKIRIM_OR_DIJADWALKAN' => ['intent' => self::INTENT_INFO, 'icon' => 'truck'],
            'SELESAI' => ['intent' => self::INTENT_SUCCESS, 'icon' => 'check-badge'],
            'KOMPLAIN' => ['intent' => self::INTENT_DANGER, 'icon' => 'exclamation-triangle'],
            'DIBATALKAN' => ['intent' => self::INTENT_NEUTRAL, 'icon' => 'slash'],
        ],
        // `PaymentState` (marketplace checkout lane) — design-system.md §3.7
        // "Marketplace payment" table (added 14 Aug 2026 with the lane).
        // Deliberately a SEPARATE family from vendor processing: a paid
        // order is never fulfilment-complete (AC12), and the two render as
        // two distinct indicators on PUB-024.
        self::FAMILY_MARKETPLACE_PAYMENT => [
            'BELUM_DIBAYAR' => ['intent' => self::INTENT_PENDING, 'icon' => 'clock'],
            'MENUNGGU_VERIFIKASI' => ['intent' => self::INTENT_PENDING, 'icon' => 'clock'],
            'DIBAYAR' => ['intent' => self::INTENT_SUCCESS, 'icon' => 'banknote'],
            'GAGAL' => ['intent' => self::INTENT_DANGER, 'icon' => 'x-circle'],
            'DIKEMBALIKAN' => ['intent' => self::INTENT_NEUTRAL, 'icon' => 'clock-x'],
        ],
    ];

    /**
     * Filament colour() keys registered in AdminPanelProvider (design-system.md
     * §8.3): 'primary', 'success', 'warning', 'danger', 'info', 'gray'.
     * Filament's `->color()` closure expects one of THESE keys, not our
     * intent name verbatim — this is the bridge design-system.md §8.3 asks
     * for explicitly.
     *
     * @var array<string, string>
     */
    private const FILAMENT_COLOR_BY_INTENT = [
        self::INTENT_NEUTRAL => 'gray',
        self::INTENT_INFO => 'info',
        self::INTENT_PENDING => 'warning',
        self::INTENT_SUCCESS => 'success',
        self::INTENT_DANGER => 'danger',
        // 'urgent' has no distinct Filament colour of its own: tokens.css
        // §2.10 already aliases --mk-intent-urgent-* onto the warning hue
        // ("Urgent is an alias, not a new colour" — design-system.md §1.2),
        // so the Filament bridge makes the same choice rather than
        // registering a colour tokens.css does not define.
        self::INTENT_URGENT => 'warning',
    ];

    /**
     * Blade-facing resolver: `StatusIntent::intent($status)`.
     */
    public static function intent(string $status, ?string $family = null): string
    {
        return self::resolve($status, $family)['intent'];
    }

    /**
     * Blade-facing resolver: `StatusIntent::icon($status)`.
     * Returns an icon IDENTIFIER (e.g. 'check-badge'), matching the
     * `x-dynamic-component :component="'icon.' . $icon"` convention already
     * used by button.blade.php / badge.blade.php — never a rendered SVG or
     * a colour.
     */
    public static function icon(string $status, ?string $family = null): string
    {
        return self::resolve($status, $family)['icon'];
    }

    /**
     * Blade-facing resolver: `StatusIntent::label($status)`.
     *
     * Deliberately NOT a hand-maintained per-status copy table: the order
     * lifecycle and vendor processing enums are already Indonesian domain
     * terms (MASUK, DIBAYAR, SELESAI, ...), so a structural humanisation —
     * underscores to spaces, title case — produces a readable Indonesian
     * label without inventing new product copy this batch has no authority
     * to write. This also satisfies §3.6's "never abbreviate the canonical
     * status enum in the badge label": the full enum is always present,
     * just formatted for reading.
     *
     * Known rough edge: `DIKIRIM_OR_DIJADWALKAN` humanises to "Dikirim Or
     * Dijadwalkan" — the embedded English "OR" reads awkwardly next to
     * Indonesian words. Rewriting it to "Dikirim atau Dijadwalkan" would be
     * inventing copy (deciding "OR" means "atau" here is a product-content
     * call, not a formatting one), so it is left as the honest structural
     * transform of the canonical enum rather than silently improved.
     */
    public static function label(string $status, ?string $family = null): string
    {
        return self::humanize($status);
    }

    /**
     * Filament bridge: `StatusIntent::filamentColor($state)`.
     * Maps intent -> Filament colour-palette key (design-system.md §8.3):
     * `->color(fn (string $state): string => StatusIntent::filamentColor($state))`.
     */
    public static function filamentColor(string $status, ?string $family = null): string
    {
        $intent = self::intent($status, $family);

        return self::FILAMENT_COLOR_BY_INTENT[$intent]
            ?? self::FILAMENT_COLOR_BY_INTENT[self::FALLBACK_INTENT];
    }

    /**
     * All families this class currently has a populated mapping for.
     * Exposed for tests and for callers that want to validate a $family
     * argument before passing it in.
     *
     * @return list<string>
     */
    public static function knownFamilies(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * All statuses known for a given family. Returns an empty array for an
     * unregistered family rather than throwing — same defensive posture as
     * the rest of this class.
     *
     * @return list<string>
     */
    public static function knownStatuses(string $family): array
    {
        return array_keys(self::MAP[$family] ?? []);
    }

    /**
     * @return array{intent: string, icon: string}
     */
    private static function resolve(string $status, ?string $family): array
    {
        if ($family !== null) {
            $entry = self::MAP[$family][$status] ?? null;

            if ($entry !== null) {
                return $entry;
            }

            self::warnUnrecognised($status, $family);

            return ['intent' => self::FALLBACK_INTENT, 'icon' => self::FALLBACK_ICON];
        }

        $matches = [];
        foreach (self::MAP as $familyKey => $statuses) {
            if (array_key_exists($status, $statuses)) {
                $matches[$familyKey] = $statuses[$status];
            }
        }

        if ($matches === []) {
            self::warnUnrecognised($status, null);

            return ['intent' => self::FALLBACK_INTENT, 'icon' => self::FALLBACK_ICON];
        }

        $first = reset($matches);
        $allAgree = true;
        foreach ($matches as $entry) {
            if ($entry['intent'] !== $first['intent'] || $entry['icon'] !== $first['icon']) {
                $allAgree = false;
                break;
            }
        }

        if (! $allAgree) {
            Log::warning('StatusIntent: ambiguous status resolved differently across multiple families; pass $family explicitly.', [
                'status' => $status,
                'families' => array_keys($matches),
            ]);

            return ['intent' => self::FALLBACK_INTENT, 'icon' => self::FALLBACK_ICON];
        }

        return $first;
    }

    private static function warnUnrecognised(string $status, ?string $family): void
    {
        // Loggable, never fatal — an unmapped status must not crash a table
        // render (batch brief; matches button.blade.php / card.blade.php's
        // existing defensive pattern for an unknown variant/intent prop).
        Log::warning('StatusIntent: unrecognised status, falling back to neutral.', [
            'status' => $status,
            'family' => $family,
        ]);
    }

    private static function humanize(string $status): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $status)));
    }
}
