---
name: makam-livewire-page
description: Build or modify a public Livewire page in this repository — layout attachment, reading through a domain Query class, surviving a failed secondary panel, 404 discipline for unpublished records, and the route/test shape that goes with it. Use when creating any page under app/Livewire/Public/, replacing a coming-soon stub, adding a public route, or wiring a public screen to domain data.
---

# Public Livewire pages

This pattern is **not written down anywhere in `docs/`** — it lives only as doc comments copied between components. `FaqIndex` and `HomePage` are the reference implementations; read one before writing a new page.

## The component

Plain `Livewire\Component`. Attach the layout **per render**, inside `render()`:

```php
return view('livewire.public.<name>', [...])->layout('layouts.app', [
    'title'  => '…',
    'active' => 'pemesanan'|'layanan'|'perpanjangan'|'faq'|null,
]);
```

Not the `#[Layout]` class attribute — per-render lets `title` vary with state (`FaqIndex` changes it by search/category). `layouts/app.blade.php` reads both defensively (`$title ?? …`), so a missing key degrades rather than fatals.

`active` must match `<x-mk.header>`'s nav keys exactly, or the header highlights nothing.

## Reading data

Never call an Eloquent model from a component or a Blade view. Every domain exposes one read entry point — `FaqPublicQuery`, `MarketplaceCatalogQuery` — and it composes the model's own visibility scope (`published()`, `active()`). Bypassing it is how a draft leaks: the guarantee lives in the scope, not in the call site.

If the domain has no `*Query` class yet, add one rather than querying the model directly.

## A secondary panel must not take the page down

design-system §6.3/§6.5. Both reference components do this:

```php
$panel = new Collection;
$panelUnavailable = false;

try {
    $panel = SomeQuery::something();
} catch (Throwable $e) {
    report($e);
    $panelUnavailable = true;
}
```

The view then says so honestly. Primary content — the four menus, the category browse — must still render. Use a component property when the view needs to bind to it, a local `$…Unavailable` variable when it does not.

## 404 discipline

`abort(404)` for **both** "no such record" and "record exists but is not published". The two must be indistinguishable from outside — a different message or status leaks the record's existence. This is the same rule as `public-faq` AC6.

## `mount()` vs `render()`

One-time side effects (analytics impressions) go in `mount()` — it runs once per GET, while `render()` can re-run inside a single Livewire request lifecycle. `HomePage::mount()` records menu impressions for exactly this reason.

## Buttons — verified 8 Aug 2026

Use `<x-mk.button>`. design-system §9.2 MUST #2: *"Use the `<x-mk.*>` primitives in §3. Extend them rather than forking."*

Existing views hand-write button markup, copying `button.blade.php`'s class recipe. That was correct at the time — finding **N-14**'s `Undefined variable $loading`. **That bug is fixed and the fix is now verified**: `<x-mk.button>` was rendered through a real Livewire component on Laravel 13.22.0 + Livewire 4.3.3, and all three variants work — `<button>`, `<a href>`, and the `loading` state with its spinner and `disabled`. `<x-mk.badge>` too.

So: **new pages use the primitive.** The existing hand-written call sites are left alone — migrating them is optional cleanup, not this work's scope, and N-14 says the same.

## Routes

Plain `Route::get('/path', Component::class)->name('…')` — a full-page Livewire component routes like a controller. Path parameters arrive in `mount(string $param)`.

`routes/web.php` carries a doc-comment table of the four MVP entry points and their STUB-vs-implemented status. When a page replaces a coming-soon stub, **replace** the stub's route registration and update that table — do not register alongside it. Each stub component's own doc block says it is expected to be replaced wholesale.

## Tests

`tests/Feature/Livewire/Public/**`, `final class`, `declare(strict_types=1)`, `RefreshDatabase`, methods named `test_snake_case_full_sentence`.

```php
protected function setUp(): void
{
    parent::setUp();
    $this->withoutVite();
}
```

Required for any test that makes a **real HTTP request** rendering a layout with `@vite(...)` — CI's `php` job has no frontend build, and without it you get "Vite manifest not found". `Livewire::test()`-only tests do not need it.

- Real HTTP (`$this->get('/path')`) for route/status/rendered-HTML assertions.
- `Livewire::test(Component::class)->set(…)->call(…)` for internal state, validation, idempotency.
- Assert against **real seeded data** from migrations and real domain Actions — no factories, no fabricated fixtures. `UserFactory` exists only for `actingAs()`.
- Prove the provider-unavailable branch by dropping the real table inside the test transaction, the way the FAQ tests do — not by mocking the query class.
- Ordering assertions scope to the body (`substr($body, strpos($body, '<body'))`) so `<title>` does not produce false matches.

See `makam-testing` for the wider testing rules, `makam-design-system` for what the view owes, and `makam-verify` for how a page is actually proven.

## Doc block

Open every component and view with the route(s) it serves and the spec + sprint task that owns it, and cite AC numbers inline next to the code implementing them. When you reverse an earlier decision, append the new reasoning with a date — do not delete the old (see `makam-domain-module` for why this repo does that).
