/*
 * Shared public-entry behaviour for the Makam.co.id front-end.
 *
 * Deliberately dependency-free vanilla JS: this entry is loaded by every
 * public page via `@vite(['resources/css/app.css', 'resources/js/app.js'])`
 * (layouts/app.blade.php), and the pinned front-end has no Alpine build
 * installed. Adding a dependency for a single toggle would need an ADR and
 * an installed-Alpine verification (see the KNOWN GAP note in
 * resources/views/components/mk/header.blade.php). These handlers are the
 * "behaviour layer" that file's markup was already prepared for.
 *
 * The only current behaviour is the mobile hamburger: it toggles the
 * `hidden` class on the panel its `aria-controls` points at and keeps
 * `aria-expanded` in sync, so the documented KNOWN GAP (aria-expanded never
 * flips, panel stays hidden) is closed without changing any visible markup
 * or label.
 */
document.addEventListener('DOMContentLoaded', () => {
    for (const button of document.querySelectorAll('header [aria-controls]')) {
        const panel = document.getElementById(button.getAttribute('aria-controls') ?? '');
        if (!panel) {
            continue;
        }

        button.addEventListener('click', (event) => {
            event.preventDefault();

            const isExpanded = button.getAttribute('aria-expanded') === 'true';

            button.setAttribute('aria-expanded', String(!isExpanded));
            panel.classList.toggle('hidden', isExpanded);
        });
    }
});
