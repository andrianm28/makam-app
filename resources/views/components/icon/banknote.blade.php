{{--
    resources/views/components/icon/banknote.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    design-system.md §3.7 assigns to order-lifecycle status `DIBAYAR`
    (intent `success`, "Money confirmed"). Reached through
    App\Support\Design\StatusIntent::icon(), never by a component
    switching on the enum itself (§3.7, §9.2 MUST #5).

    NAME SUBSTITUTION — read before renaming anything.
    Requested name: `banknote` (design-system.md §3.7 order-lifecycle
    table, transcribed verbatim into StatusIntent::MAP).
    Glyph actually used: Heroicons v2 outline **"BanknotesIcon"**
    (`24/outline/banknotes.svg`), i.e. the PLURAL name.
    Why: Heroicons v2 has no icon called `banknote`. Verified against
    heroicons 2.2.0 — `24/outline/banknote.svg` returns 404, while
    `24/outline/banknotes.svg` exists; the plural is the only stacked-cash
    glyph in the set and is an exact semantic match for "Money confirmed".
    (The singular `banknote` IS a real name in Lucide, the other set OQ-05
    names — so §3.7's icon column looks like a mix of two namespaces, not
    a Heroicons list. Reported as a P-1 finding, not fixed here.)
    The FILE keeps the requested name `banknote` deliberately: §3.7 and
    StatusIntent::MAP are the canonical spelling, and renaming either to
    match the icon library would be editing a normative design document
    from inside an implementation detail. If OQ-05 is ever resolved onto a
    set that does have a singular `banknote`, this file is where the
    indirection disappears.

    Provenance: the real, unmodified Heroicons v2 outline "BanknotesIcon"
    (heroicons 2.2.0, `24/outline/banknotes.svg` — MIT-licensed, 24x24
    viewBox, stroke-width 1.5), i.e. OQ-05's own documented assumed
    default ("Outline, 1.5 px" — design-system.md §9.1) and the same
    source the two pre-existing icon files were built from. Not a custom
    drawing, and not a hand-edited variant of another glyph.

    Batch P-1 scope, identical in kind to finding N-15 (sprint-plan.md)
    and icon/bars-3.blade.php's own doc block: this file exists because a
    real caller crashes without it (`InvalidArgumentException: Unable to
    locate a class or view for component [icon.banknote]`), NOT as a
    resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
</svg>
