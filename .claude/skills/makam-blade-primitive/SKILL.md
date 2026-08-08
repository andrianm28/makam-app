---
name: makam-blade-primitive
description: Write or change an x-mk.* Blade primitive or an icon component in this repository — the single-@php-block structure, the one attributes-merge rule, why class names must never be interpolated, and the doc-comment trap that silently corrupts Blade compilation. Use when editing anything under resources/views/components/, adding a primitive, adding an icon, or extending a primitive's props.
---

# Blade primitives

`resources/views/components/mk/**` are **single-writer shared files**. Every page renders them, so a change is a change to every screen. They are on the never-fan-out list — one owner at a time, and never inside a concurrent batch. See `makam-agent-batch`.

Current set: `alert · badge · button · card · field · gate-closed-banner · gate-closed-page · header · logo · modal · spinner · stepper · table`.

## Structure

Read `button.blade.php` first — it is the convention reference. Every primitive follows the same shape:

1. A leading `{{-- --}}` doc comment: what the component is, the design-system § it implements, and every deliberate decision.
2. One `@props([...])` block with every prop and its default.
3. **One** `@php` block that composes the classes, usually via a static lookup table keyed by variant/intent/size.
4. Markup with **exactly one** `{{ $attributes->merge(['class' => $classes]) }}` on the root element.

Conventions that are easy to miss:

- **`neutral-0`, never Tailwind's `white`.** The palette is token-driven; `white` is outside it.
- **Never interpolate a class name.** `"bg-{$intent}-100"` is invisible to Tailwind's JIT source scanner, so the utility is never generated and the element silently renders unstyled. Always write fully-literal class strings in a PHP array and select from it.
- Put shared defaults like `role` / `aria-label` **inside** the `$attributes->merge()` defaults rather than as literal attributes next to it, so a caller can override without emitting a duplicate attribute.
- Zero hardcoded design values. `ci/verify-docs.sh` GATE 2/3/11/12 will reject hex literals, Tailwind arbitrary values, raw `z-index`, and unreplaced focus suppression.

## The doc-comment trap — finding N-14

**Never write the literal text `@php` or `@endphp` as prose inside a `{{-- --}}` comment.**

`BladeCompiler::compileString()` calls `storeUncompiledBlocks()` — which extracts `@php … @endphp` into opaque placeholders — **before** `compileComments()` strips `{{-- --}}`. Its regex is a plain non-greedy text scan with no awareness of comment boundaries. So a prose `@php` inside a leading doc comment matches first, and non-greedily swallows everything up to the real `@endphp`: the comment's own closing `--}}`, the real `@props([...])`, and the real `@php` block, all into one inert placeholder.

Two consequences, both observed in CI:

- `@props` never compiles, so every declared prop is genuinely undefined at render — this is the real cause of the historic `Undefined variable $loading`.
- The unterminated `{{--` lets later prose mentions of `<x-mk.button>` survive into `compileComponentTags()` as real unclosed component tags, producing `ParseError: syntax error, unexpected token "]"`.

Seven primitives were affected. The fix was rewording the prose ("one `@php` block" → "one PHP block"). Note this also applies to `//` comments *inside* a real `@php` block, since `compileComments()` only ever touches `{{-- --}}` syntax.

Sweep before you finish:

```bash
grep -rn '@php\|@endphp' resources/views/**/*.blade.php | grep -v '^\s*@php\|^\s*@endphp'
```

## Props are contracts

Several primitives carry product contracts in their defaults. `header.blade.php` hardcodes the four menu labels; `stepper.blade.php` defaults to the nine booking step labels. `AGENTS.md`: *"Never rename, reorder, or hide a product label, route, menu item, or booking step."*

When a second journey needs different content, add an **optional prop whose default is the existing contract** — so omitting it is byte-identical and the contract cannot be broken by accident — and say in the doc block that the prop is for a different journey, never for re-labelling the original. That is how `stepper`'s `labels` prop was added for the six-step renewal flow, and the default booking render was proved byte-identical before and after.

## Icons

`resources/views/components/icon/<name>.blade.php`, resolved via `<x-dynamic-component :component="'icon.' . $name">`. A missing file throws `InvalidArgumentException` **at render**, not at compile — so it passes every local check and fails in the browser. That is finding **N-15**, and it has broken CI more than once.

Rules:

- Real, unmodified **Heroicons v2 outline** glyphs — 24×24 viewBox, `stroke-width="1.5"`, colour via `currentColor`. This is design-system OQ-05's documented assumed default; OQ-05 itself is still open, so adding an icon does not decide it.
- **Never draw or improvise path data.**
- When the name a caller uses is not a real Heroicons name (`alert-circle`, `slash`, `clock-x`), map it to the closest genuine glyph and record the substitution in that file's own doc comment.
- Before finishing any UI work, check for gaps:

```bash
comm -23 <(grep -rhoE "icon\.[a-z0-9-]+" resources/views/ | sed 's/icon\.//' | sort -u) \
         <(ls resources/views/components/icon/ | sed 's/\.blade\.php//' | sort)
```

Empty output means every referenced icon exists. Do not rely on `StatusIntent`'s list alone — primitives reference icons directly too.

## Verifying a change

`php -l` does not validate Blade. Compile through the real installed compiler in the sibling project and `php -l` the **output**, then render both the default and the new case and diff the default against `git show HEAD:<file>` to prove no regression. `makam-verify` has the harness and the rule that nothing may be written into that repository.
