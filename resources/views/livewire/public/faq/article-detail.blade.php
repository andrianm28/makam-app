{{--
    resources/views/livewire/public/faq/article-detail.blade.php

    App\Livewire\Public\Faq\FaqArticleDetail's view — `/faq/{articleSlug}`.
    requirements.md AC4: title, body, updated date, related articles,
    customer-service CTA. See that class's own doc block for the
    `updated_at` (not `published_at`) reasoning and the AC6-as-real-404
    reasoning (a draft/unpublished slug never reaches this view at all —
    `mount()` already `abort(404)`s before render()).

    §6.10 support: the customer-service CTA below is present on EVERY
    article render — tasks.md's own wording, "AC4 customer-service CTA on
    every article" — not conditional on any state.
--}}
@php
    use App\Livewire\Public\Faq\Support\FaqCategorySlug;
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <nav aria-label="Navigasi FAQ" class="mx-auto mb-6 max-w-prose text-sm text-neutral-600">
            <a href="{{ route('faq.index') }}" class="text-primary-600 underline underline-offset-2 hover:text-primary-700">FAQ</a>
            <span aria-hidden="true"> &rsaquo; </span>
            <a href="{{ route('faq.category', ['categorySlug' => FaqCategorySlug::forCategory($category)]) }}" class="text-primary-600 underline underline-offset-2 hover:text-primary-700">
                {{ $category->name }}
            </a>
        </nav>

        <article class="mx-auto max-w-prose">
            <header class="mb-6 space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">{{ $article->title }}</h1>

                {{-- Updated timestamp — muted meta, §1.4 / §7.1. Carbon's
                     per-instance ->locale('id') is used instead of changing
                     config('app.locale') (which defaults to 'en' and is a
                     global setting this presentation-only batch does not
                     touch) — scoped to this one formatting call only. --}}
                <p class="text-sm text-neutral-600">
                    Diperbarui {{ $article->updated_at?->locale('id')->translatedFormat('d F Y') }}
                </p>
            </header>

            {{-- Article body — --container-prose (max-w-prose, already
                 established elsewhere in this codebase), --text-base,
                 --mk-text-default (neutral-700). whitespace-pre-line
                 preserves the seeded body's paragraph breaks while
                 {{ }} still escapes it — body is plain text, never HTML,
                 in this schema (faq_articles.body is a `text` column, no
                 rich-text/markdown pipeline exists in this batch's scope). --}}
            <div class="whitespace-pre-line text-base text-neutral-700">{{ $article->body }}</div>

            {{-- Customer-service CTA — present on every article render,
                 unconditionally (tasks.md §6.10 note). `/bantuan` is not
                 yet built in this repository (routes/web.php has no such
                 route) — the same honest forward-reference
                 <x-mk.header>'s own `bantuanHref` default already makes;
                 not fabricated here. A mailto/phone destination was
                 considered and rejected: no real support email, phone, or
                 WhatsApp number is committed anywhere in this repo's docs
                 (the seed migration's own doc block notes
                 release-gates.md still lists "support contacts, hours..."
                 as an OPEN item) — linking to a documented-but-not-yet-built
                 route is more honest than inventing a contact address this
                 codebase does not actually have.

                 FIXED 26 Jul 2026 — first real CI run: hand-written, not
                 <x-mk.button>, for the same reason
                 resources/views/livewire/public/faq/index.blade.php's own
                 doc comment explains at length (every <x-mk.button> usage on
                 this page's sibling failed with "Undefined variable
                 $loading" when rendered through a real Livewire full-page
                 component — this repo's first-ever use of that combination
                 — including this one, which has no wire:loading attribute
                 at all). See sprint-plan.md finding N-14. --}}
            <div class="mt-8 border-t border-neutral-200 pt-6">
                <a
                    href="/bantuan"
                    class="inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-primary-600 bg-neutral-0 px-4 text-base font-medium text-primary-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-50 active:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                >
                    Hubungi Customer Service
                </a>
            </div>
        </article>

        {{-- Related articles — publishedRelatedArticles() only, see
             FaqArticleDetail::render(). --}}
        @if ($relatedArticles->isNotEmpty())
            <section class="mx-auto mt-12 max-w-content" aria-label="Artikel terkait">
                <h2 class="mb-4 text-2xl font-semibold tracking-tight text-neutral-900">Artikel terkait</h2>
                <ul class="grid gap-4 md:grid-cols-2">
                    @foreach ($relatedArticles as $related)
                        <li>
                            <x-mk.card as="a" interactive :href="route('faq.show', ['articleSlug' => $related->slug])" class="h-full touch-target">
                                <h3 class="text-lg font-semibold text-neutral-900">{{ $related->title }}</h3>
                                <p class="text-base text-neutral-600">{{ $related->summary }}</p>
                            </x-mk.card>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
