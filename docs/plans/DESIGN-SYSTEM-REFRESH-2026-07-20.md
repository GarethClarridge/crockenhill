# Design System Refresh (2026-07-20) — defects, consistency, and visual polish

> **Status (2026-07-20): approved, not started.** Source review:
> [../reviews/design-system-review-2026-07-20.md](../reviews/design-system-review-2026-07-20.md)
> (finding IDs D1–D4, DR1–DR4, C1–C7, V1–V3 referenced below). All four maintainer
> decisions are recorded there §7 and are settled — do not re-litigate them.
>
> **Dependencies:** none on the July simplification backlog. Phase 2's neutral-ramp alias
> and Phase 3's prose change touch many rendered pages — coordinate with any in-flight UI
> PR by merging this **after** it, or rebasing it, to avoid Playwright baseline churn ×2.
>
> **What an agent must not do without maintainer input:**
> - Remove or restyle the home-hero typewriter animation (decision: **keep**). Only the
>   font-weight fix under it (Phase 3) is in scope.
> - Introduce new dependencies, new fonts beyond an additional self-hosted Oswald weight,
>   or colours outside the `cbc-*` palette.
> - Touch admin screens' *layout* — Phase 2's token alias shifts their neutral hue
>   slightly; that is the entire intended admin-facing change.
> - Change public page *structure* (shells, heros, card grids). This plan changes
>   tokens, type, alignment, and assets — not information architecture.
> - Run the Phase 1 title backfill in production without `--dry-run` output reviewed by
>   the maintainer first.

## Goal

Make the public site read as modern, simple, and beautiful **without changing what any
page is or does**: fix the four visible defects, collapse the consistency drift so every
surface draws from one set of tokens, apply the three approved visual decisions
(retinted placeholder art, left-aligned prose, real bold display type), and rewrite the
style guide + skill so they describe the codebase that actually exists.

## Non-goals

- No information-architecture or navigation changes (the newcomer UX backlog owns those).
- No dark mode.
- No new component types — this plan only corrects and consolidates existing ones.
- No admin redesign.
- No changes to `<x-page.shell>` meta/schema plumbing.

## Verification protocol (applies to every PR)

1. `vendor/bin/sail bin pint --dirty --format agent`
2. `vendor/bin/sail composer phpstan`
3. `vendor/bin/sail artisan test --compact --parallel` (tee the first run per AGENTS.md)
4. `vendor/bin/sail artisan dusk`
5. **PRs 2–4 (visual changes): regenerate Playwright visual-regression baselines** using
   the documented procedure (`DEBUGBAR_ENABLED=false` to avoid the 33px oscillation).
   Review the before/after diff images by eye before accepting — that diff *is* the
   review artefact for this plan.

---

## Phase 1 (PR 1) — Correctness fixes [mechanical]

### 1.1 Sermon title ordinal casing (D1)

**Code:** in `app/Services/Sermon/SermonCreationService.php` (~line 749), replace the
bare `Str::title($title)` with a helper (private method on the service) that
title-cases and then lowercases ordinal suffixes:

```php
$title = preg_replace('/\b(\d+)(St|Nd|Rd|Th)\b/', '$1' . '\L$2', Str::title($title));
```

(PHP `preg_replace` has no `\L`; implement with `preg_replace_callback` lowercasing
capture 2. Keep it a pure function.)

**Tests first** (failing-test-first per standing feedback): unit-test the helper with
`"sunday 28th june 2026" → "Sunday 28th June 2026"`, `"1st"`, `"2nd"`, `"3rd"`,
`"21st"`, and a non-ordinal digit case (`"Psalm 100"` must stay untouched — the pattern
requires the suffix letters, so it does).

**Backfill:** new artisan command `sermons:fix-title-ordinals` with `--dry-run`
(default) printing `id, old title → new title`, and `--force` to apply.
- Match via the same regex; update through Eloquent `save()` in chunks so model
  observers fire (mass `update()` bypasses cache invalidation — known trap).
- The sermons table is polymorphic (children's talks share it): fix **all**
  content types; the bug is content-type-independent.
- Feature-test the command (factory sermons with mangled titles; assert dry-run
  changes nothing, `--force` fixes and leaves clean titles alone).
- **Production run is maintainer-gated** (see status header). After `--force`, clear
  the public read-model/flexible caches the same way the scripture-filter remedy does.

### 1.2 Font-face repairs (D2, part of D3)

In `resources/css/app.css`:
- Lato bold face: `font-style: bold` → `font-style: normal; font-weight: 700;`.
- Add a self-hosted **Oswald 600 (semibold)** face: subset `oswald-semibold.woff2` into
  `resources/fonts/` (same latin subset + `font-display: swap` as the existing files;
  source from Google Fonts, woff2 only). 600 in Oswald is visually "bold enough" for
  display use and stays condensed; do **not** also add 700 unless the hero comparison
  (Phase 3.2) looks weak.
- No template changes in this PR; synthesised bold simply becomes real bold where 700
  is already requested (`<strong>`, `font-bold`, `font-semibold` on Lato).

Verification beyond the suite: load `/dev/components` and confirm in devtools' network
panel that `lato-bold.woff2` now loads for bold prose and that no face is double-served
for weight 400.

### 1.3 Screenshot reference commands (D4)

Replace the guide's mobile headless-Chrome loop with a Playwright capture script (the
dependency already exists for visual regression): a small `scripts/`-level or
`package.json`-scripted runner using device emulation (`viewport: 375×812`,
`deviceScaleFactor: 2`) writing to `docs/design-references/`. Desktop captures can stay
on headless Chrome or move to Playwright too — one tool is simpler; prefer Playwright
for both. This lands as part of the guide rewrite (Phase 5) if not done here; either PR
may carry it, but the broken commands must not survive both.

---

## Phase 2 (PR 2) — Token and component consolidation [mechanical, visual-diff-gated]

### 2.1 Unify the neutral ramp (C3)

In the `@theme` block of `app.css`, alias the gray scale to slate values
(`--color-gray-50: …slate-50 value;` … `--color-gray-950`), so all ~650 existing
`gray-*` call sites render slate hues and match the `slate-200` body background.
- Write the slate hex values explicitly (Tailwind v4 `@theme` variables should not
  self-reference other default-palette variables that may be tree-shaken).
- **Convention going forward** (document in Phase 5): write `gray-*` in templates;
  `slate-*` remains valid but new code uses gray. (Gray wins on 13:1 existing usage.)
- Expect a subtle site-wide hue shift in the Playwright diff — that is the change.
  Anything *structural* in the diff is a regression.

### 2.2 Button component cleanup (C1, C2)

In `resources/views/components/button.blade.php` (and `form-button.blade.php` if it
mirrors the same maps — check):
- Delete the `featureOutline` variant; keep `secondary` (the guide's documented name).
  Migrate the six `featureOutline` call sites (`public-cta`, `errors/403|404|500`,
  `full-width-pages/christ`, gallery) to `secondary`.
- Replace `text-[#145557]` with `text-cbc-teal-dark`.
- `feature` variant: replace the inline gradient with the shared `.bg-gradient-teal`
  utility so the site has exactly one brand gradient (`#0e3a3c` end-stop disappears;
  the visual delta is a slightly lighter gradient tail — acceptable, diff-gated).
- Add `--color-cbc-crimson-dark: #590d16;` to `@theme`; use it for the danger hover.

### 2.3 Card geometry (C4)

Standard: **public content cards are `rounded-xl` with `border-gray-200`**; `x-card`
(admin/utility surface) stays `rounded-lg`. Update `sermon-card`,
`childrens-talk-card`, `clickable-card`, `calendar-event-card` to match `page-card`.
Border colour unifies via 2.1's alias plus changing the stragglers on `gray-300` to
`gray-200` where they sit next to `page-card` (public rails only — admin tables keep
their current borders).

### 2.4 Header nav-link extraction (C5)

New private components under `components/layout/` (e.g. `nav-link.blade.php` with a
`variant` prop for `desktop`/`mobile-heading`/`mobile-item`) absorbing the repeated
focus/transition/active chains in `header.blade.php`. Pure refactor: the Playwright
header shots must be pixel-identical. Keep `aria-current`, `wire:navigate`, and the
active-state logic exactly as-is (pass `active` as a boolean prop).

### 2.5 Token retirement (C6)

- Merge `cbc-teal-darkest` into `cbc-teal-deeper` (2 usages), delete the token.
- Leave `cbc-rose-muted` (it has legitimate accent usages) but note it accent-only in
  the guide rewrite.

---

## Phase 3 (PR 3) — Typography and prose layout [design, decision V3]

### 3.1 One prose rail, left-aligned paragraphs

- Rework `<x-text>`: left-aligned `prose` on the standard rail —
  `mx-auto max-w-2xl xl:max-w-3xl px-6 prose` (drop `text-center`, drop the narrower
  `max-w-lg xl:max-w-xl`). Tailwind `prose` caps measure at ~65ch internally, so the
  rail and the type measure now agree with guide §10's readability rationale.
- Headings (`x-h1/h2/h3`), `public-cta`, card grids, and hero content **stay centred**.
- Audit the 12 `<x-text>` call sites: single-sentence lead-ins that visually relied on
  centring (e.g. the one-liners between home-page sections) may keep centring via an
  explicit `class="text-center"` override *only where a left-aligned single line looks
  unbalanced* — expected: home's short interstitials keep it, multi-paragraph copy
  (church/christ/community/content pages) goes left. Judge from the Playwright diffs.
- `pages/show.blade.php` / `<x-page.shell>` prose blocks: confirm they use the same
  rail; align if not.

### 3.2 Real display bold in the hero (D3; typewriter stays)

In `home.css`: `.home-hero-title { font-weight: 600; }` (real Oswald semibold from
Phase 1.2, replacing synthesised 800). Do not touch the animation, its timings, or the
reduced-motion overrides. Verify the typewriter still steps correctly (the width
animation is glyph-width-sensitive; semibold Oswald is slightly wider than faux-bold —
check the `steps()` reveal doesn't clip the final character at 375px and 1440px).

### 3.3 Dusk/visual checks

Beyond baseline regen: a Dusk smoke on `/` and one content page asserting the prose
container classes, so the rail can't silently fork again.

---

## Phase 4 (PR 4) — Placeholder artwork retint [design, decision V1]

### 4.1 Asset inventory

Enumerate the aqua low-poly placeholder family: `public/images/headings/{small,large,…}`
plus any other directories referencing the same texture (grep for usages of each file
before touching it; some files in `headings/` are real photos — `bible-study.webp`,
`find-us.webp` — and must not be modified).

### 4.2 Retint

Regenerate the placeholder files in the brand ramp — target: reads as
`cbc-teal-light → cbc-teal-dark` (the `.bg-gradient-teal` ramp), same filenames, same
dimensions, webp, similar file sizes. Preferred approach: one-off Intervention Image
(v4 API — `Image::read()`) script or ImageMagick hue/level mapping run locally and the
command recorded in the PR description; assets are committed, the script is not (no
new repo scripts for a one-off). Keep the poly texture's value range — white card-title
text sits on these images, so verify contrast (WCAG ≥ 4.5:1 against the *lightest*
region under text, matching the existing dark gradient overlay behaviour).

### 4.3 Verify

`/church`, `/`, `/community/sunday-mornings` locally (fallback-heavy pages), plus the
gallery. Media-library-backed real photos must be untouched. Baselines regen.

---

## Phase 5 (PR 5) — Documentation truth-up [mechanical; DR1–DR4]

Rewrite `docs/design-style-guide.md` so every claim matches post-Phase-1–4 reality:

- §2/§3: Tailwind v4 CSS-first config — tokens in `@theme` in `resources/css/app.css`;
  partials are `.css`; delete every `app.scss`/`tailwind.config.js` reference. Error
  views are `errors/{403,404,500,503}.blade.php`.
- §3: add `cbc-crimson-dark`; remove `cbc-teal-darkest`; document the gray→slate alias
  and the "write `gray-*`" convention.
- §6: correct the button table to the real variant set post-2.2
  (`primary, secondary, feature, outline, danger, warning, success, ghost`), with the
  gallery as the visual reference. Fix the §13 CTA snippet (it stays `secondary`,
  which is now truthful). Document card geometry: public cards `rounded-xl`, `x-card`
  `rounded-lg` — remove the either/or wording.
- §4/§5: document the single prose rail and the "paragraphs left, headings/CTAs
  centred" rule with the readability rationale; document Oswald 400+600 and Lato
  400/700+italic as the complete font inventory.
- Visual references: replace the screenshot commands with the Playwright runner (1.3);
  regenerate all reference screenshots.
- §11: replace the stale legacy list with whatever stragglers actually remain after
  Phases 1–4 (verify by grep, not memory); if none, say so and keep the section as the
  place future stragglers get listed.
- **`.claude/skills/frontend-design/SKILL.md`**: fix the variant table (no `default`;
  `primary` = pattern texture; `featureOutline` no longer exists), the CTA snippet, and
  add the neutral-ramp and prose-alignment conventions. The skill must never contradict
  the guide — where it summarises, link instead of restating numbers.
- **`/dev/components` gallery**: update for removed variants/tokens; add a prose-rail
  specimen (left-aligned paragraphs under a centred `x-h2`) so the Phase 3 rule has a
  living reference.
- Add this plan to `docs/plans/README.md` (done at plan creation) and archive it there
  when complete.

---

## Sequencing and review gates

| PR | Phase | Risk | Gate |
|---|---|---|---|
| 1 | Correctness | Low; backfill is prod-gated | Unit+feature tests; maintainer reviews `--dry-run` output before prod `--force` |
| 2 | Tokens/components | Medium (site-wide hue shift) | Playwright diff review — hue-only changes expected, structure identical |
| 3 | Prose + hero bold | Medium (most visible change) | Playwright diff + maintainer eyeball of `/`, `/church`, one sermon page |
| 4 | Artwork | Low | Contrast check + diff review |
| 5 | Docs | None | Guide/skill/gallery agree; screenshot refs regenerated |

PRs land in order (2 depends on 1's fonts only trivially; 3 depends on 1.2's Oswald
600; 5 depends on all). Nothing here self-merges (standing rule).
