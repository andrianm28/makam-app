# 01: Homepage hero image swap

**What to build:** A visitor landing on the homepage sees the hero lead
with a warm, human photograph instead of a facility/location photograph.
The right photo already exists in the repository
(`public/images/home/family-warmth.jpg`, a real licensed photo already
documented in-repo as "warm family reassurance, not a location/facility
image") — it just isn't the hero's image today. This ticket promotes it
to the hero's primary image and demotes the current facility photo
(`public/images/hero/cemetery-garden-daylight.jpg`) out of the hero.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The homepage's `<x-mk.hero>` renders `family-warmth.jpg` (or an
      equivalent real, licensed family-lifestyle photo already in the
      repo) as its primary image, not the facility photo.
- [ ] The facility photo is not silently deleted — it is either kept in a
      clearly-identified secondary spot on the homepage (if still useful
      there) or removed with the removal recorded in the commit message.
- [ ] No other homepage section, heading, copy, CTA, or behavior changes
      as a side effect of this swap.
- [ ] The hero's existing behavior when `image` is missing, and its
      existing scrim/contrast treatment (ADR-0045), are unaffected — this
      is an image swap, not a redesign of the hero component itself.
- [ ] `HomePageRouteTest` gains a real assertion for which image asset
      renders in the hero (not just that *an* image renders).
- [ ] `tests/Feature/View/Components/MkHeroTest.php` still passes; gains
      an assertion only if the component's own prop contract changes.
- [ ] `tests/browser/e2e-home.spec.ts` and
      `tests/browser/e2e-a11y-interaction.spec.ts` (both cover `/`) still
      pass — confirm no contrast/regression from the new image behind the
      existing scrim.
- [ ] `bash ci/verify-docs.sh` passes.
