<?php

declare(strict_types=1);

namespace App\Livewire\Public\Support;

use App\Support\CompanyInfo;
use App\Support\ContactInfo;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/bantuan` — PUB-060 "Help/contact | channels, hours, emergency
 * disclaimer" (`docs/product/screen-inventory.md` §A).
 *
 * ---------------------------------------------------------------------------
 * Why this exists: a real defect, found 8 Aug 2026
 * ---------------------------------------------------------------------------
 * `<x-mk.header>` renders a persistent "Bantuan" action on EVERY page, both
 * mobile and desktop (its own `bantuanHref` prop defaults to `/bantuan`),
 * and seven further views link the same path — `layouts/app.blade.php`'s
 * footer, both FAQ views, both legal views, the coming-soon stub, and the
 * homepage. `routes/web.php` had no such route, so the one affordance
 * `docs/design/design-system.md` §6.10 makes mandatory on every screen
 * 404d from every screen. This component and its view are the fix; the
 * route registration itself is handled separately (see "Route" below).
 *
 * This is the same class of gap `App\Livewire\Public\Legal\PrivacyPolicy`
 * closed on 26 Jul 2026 for `/privasi` and `/syarat-ketentuan` — a link
 * shipped ahead of its destination — and it is closed the same way.
 *
 * Mandates this screen answers to:
 *   - `information-architecture.md` §1 lists `/bantuan` in the public route
 *     contract; §2 makes the persistent Bantuan/customer-service action
 *     mandatory in both the desktop and mobile header.
 *   - `product-brief.md` §5 point 10: "Semua halaman memiliki
 *     customer-service escape hatch."
 *   - `design-system.md` §6.10: the escape hatch "must state channels and
 *     operating hours ... and carry the emergency disclaimer on PUB-060.
 *     Never a chat-bubble-only affordance — it must work with JS disabled."
 *
 * ---------------------------------------------------------------------------
 * Deliberately static: no domain read, no form, no Livewire action
 * ---------------------------------------------------------------------------
 * `makam-livewire-page`'s "reading data" rule (every domain read goes
 * through a `*Query` class) is not sidestepped here — this page performs no
 * domain read at all, on purpose. It is the last-resort escape hatch: if
 * the database, the FAQ tables, or a provider are down, this is precisely
 * the page a stuck user is sent to by §6.5's "provider unavailable" copy
 * and by the FAQ's empty state. A page that itself needs a working query to
 * render its own phone number would fail exactly when it is needed most.
 * So every value below is a constant, and the view links to `/faq` as a
 * whole rather than to a category resolved through `FaqPublicQuery`.
 *
 * The same reasoning drives the absence of a contact FORM: there is no
 * inbox, ticket store, or notification channel configured in this
 * repository that such a form could honestly write to, and a form that
 * silently discards a grieving family's message is worse than no form.
 * §6.10's "must work with JS disabled" is therefore satisfied structurally
 * rather than by a fallback — there is no JS-dependent affordance on this
 * page to fall back FROM. Consequently none of §6's interactive states
 * (loading, validation error, provider unavailable) are reachable here;
 * that is an honest property of a static page, not an omission — see the
 * view's own doc comment.
 *
 * ---------------------------------------------------------------------------
 * Contact data is read, never restated
 * ---------------------------------------------------------------------------
 * Phone/WhatsApp/email/hours come from `App\Support\ContactInfo` and the
 * legal entity from `App\Support\CompanyInfo` — the two single sources this
 * codebase already established (AGENTS.md §Documentation: do not duplicate
 * canonical data). Read both classes' doc blocks before editing this file:
 * all six constants are deliberately-marked PLACEHOLDERS for the public dev
 * host, not a staffed support line. That honesty is carried into the visible
 * copy by `help-centre.blade.php`'s "belum aktif" notice, which is a
 * required part of this screen and not decoration — a fabricated-looking
 * hotline on a funeral platform is a promise to someone in crisis.
 *
 * `ContactInfo::whatsapp()` falls back to the same number as `phone()` when
 * no `support_whatsapp` setting is configured (see that class), so it is
 * presented as one number reachable two ways rather than restated as a
 * second channel. No `wa.me` deep link is generated: the
 * seeded FAQ article `bagaimana-menghubungi-customer-service` still says
 * additional channels "seperti WhatsApp" will be announced when they become
 * available, and minting a deep link into a WhatsApp Business account that
 * nothing in this repository configures would contradict it.
 *
 * No response time, SLA, ticket number, or 24/7 claim appears anywhere on
 * this screen. `businessHours()` is general customer-service coverage only;
 * `App\Platform\FeatureGate\Modes\UrgentMode` documents that Urgent/At-Need
 * acceptance is a SEPARATE claim this platform does not make automatically
 * (`G-OPS-01` is seeded closed), so the view states the hours as what they
 * are and does not extend them to Urgent handling.
 *
 * ---------------------------------------------------------------------------
 * Route (registered by the routes/web.php owner, not by this batch)
 * ---------------------------------------------------------------------------
 *   Route::get('/bantuan', HelpCentre::class)->name('bantuan.index');
 *
 * `bantuan.index` follows the path-derived `<segment>.index` convention the
 * existing public routes already use (`faq.index`, `marketplace.index`,
 * `perpanjangan.index`). `/bantuan` has no child routes in IA §1, so there
 * is nothing else under this prefix to collide with.
 *
 * Note for whoever wires it: `tests/Feature/Livewire/Public/Legal/
 * FooterLegalLinksRouteTest::test_bantuan_link_remains_an_honest_unbuilt_
 * forward_reference` asserts `$this->get('/bantuan')->assertNotFound()`.
 * That assertion encodes the OLD, now-fixed gap and will fail the moment
 * the route exists. It is outside this batch's file ownership and was
 * deliberately not edited here.
 */
final class HelpCentre extends Component
{
    /**
     * `tel:` target derived from `ContactInfo::phone()` rather than written
     * out again — that method stays the one source, and a future real
     * number needs no change here, only a `site_settings` row.
     *
     * The leading `+` is KEPT (unlike `home-page.blade.php`, which strips
     * it): `+62…` is an unambiguous international dial string, whereas the
     * bare digit run `62…` is interpreted by a handset as a domestic number
     * and dials the wrong thing. Divergence from the homepage's older line
     * is intentional; that line is not this batch's file to fix and is
     * flagged in this batch's report instead.
     */
    private static function telHref(): string
    {
        $digits = preg_replace('/[^0-9]/', '', ContactInfo::phone()) ?? '';

        return 'tel:+'.$digits;
    }

    public function render(): View
    {
        return view('livewire.public.support.help-centre', [
            'phone' => ContactInfo::phone(),
            'telHref' => self::telHref(),
            'email' => ContactInfo::email(),
            'businessHours' => ContactInfo::businessHours(),
            'companyName' => CompanyInfo::name(),
            'companyAddress' => CompanyInfo::address(),
        ])->layout('layouts.app', [
            'title' => 'Bantuan dan Kontak - Makam.co.id',
            // <x-mk.header>'s nav keys are only 'pemesanan' | 'layanan' |
            // 'perpanjangan' | 'faq' — Bantuan is a separate persistent
            // action with no active treatment, so null is the correct value
            // here, not an invented fifth key.
            'active' => null,
        ]);
    }
}
