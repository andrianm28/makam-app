{{--
    resources/views/design-system-smoke-test.blade.php

    NOT a page. Not routed anywhere — grep confirms no `view('design-system-
    smoke-test')` or route reference exists before relying on that, but by
    convention nothing in routes/web.php or any controller ever names it.

    Exists only so Tailwind's JIT @source scanner sees every utility
    design-system.md §8.2 documents as literal text at least once. Without
    this, a utility whose @theme token is correctly wired but has zero real
    usage yet (true for several at this point: no page needs max-w-form,
    max-w-prose, max-w-content, or the xs: breakpoint until Sprint 4's
    wizard/FAQ work lands) never actually generates any CSS — "the token is
    defined" is not the same claim as "the utility compiles," and sprint
    task S2-T1 requires proving the latter, not just the former.

    ci.yml's frontend job builds this file in and greps the compiled output
    for each class below; that grep is the real verification, this file is
    only what makes it possible. Deliberately not a dotfile (avoids any
    ambiguity over whether @source's glob matcher skips dotfiles — untested
    either way, so this file just doesn't raise the question).

    Created 25 Jul 2026, closing S2-T1: design-system.md's own header had
    asserted every §8.2 utility as compiling but never verified it, since no
    Tailwind build had run against this file until today.
--}}
<div class="max-w-form max-w-prose max-w-content duration-fast z-modal z-header touch-target h-11 h-13 xs:block border-neutral-450 ease-standard text-base font-display">
</div>
