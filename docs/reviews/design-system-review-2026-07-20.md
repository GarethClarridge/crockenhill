# Design System Review — 2026-07-20

**Scope:** `docs/design-style-guide.md`, the `frontend-design` skill, the token layer
(`resources/css/app.css` + partials), the shared Blade component library, and the rendered
public site (desktop + mobile). Admin surfaces were reviewed at code level only.

**Method:** full read of the style guide, skill, token CSS, and every load-bearing shared
component; full-page headless-Chrome captures of `/`, `/christ/sermons`, `/church`,
`/community/sunday-mornings`, and `/dev/components` at 1440px and 375px; live-browser
verification (real Chrome, 375px frame) of a suspected mobile overflow; targeted greps to
quantify token and component usage.

**Outcome:** the maintainer approved a full-scope remediation (defects + consistency +
visual refresh). Implementation plan:
[../plans/DESIGN-SYSTEM-REFRESH-2026-07-20.md](../plans/DESIGN-SYSTEM-REFRESH-2026-07-20.md).

---

## 1. What is working well

Credit where due — this is a healthier design system than most bespoke Laravel sites:

- **Component coverage is genuinely good.** Buttons, inputs, selects, toggles, cards,
  empty states, badges, shells, admin list/form scaffolding — all exist, all get reused.
  No hand-rolled form markup survives in the views that were sampled.
- **The `/dev/components` gallery is a real asset.** It renders brand colours, the type
  ramp, every button variant, form controls, and cards in one place. Few projects this
  size have a living style reference at all.
- **Accessibility discipline is visible everywhere**: skip link, `aria-current` on nav,
  `aria-label` on icon actions, `x-cloak`, `focus-visible` rings, `inert` on the
  hidden desktop title during menu expansion, `prefers-reduced-motion` overrides for the
  hero animation, `x-trap.noscroll` on the mobile menu.
- **The legacy cleanup documented in guide §11 is essentially finished.** No indigo focus
  chains remain anywhere in `resources/views`; auth views and error pages now use the
  modern components; the old `pages/`/`meetings/` create/edit views are gone.
- **The shell pattern (§2) matches reality.** `<x-page.shell>` and the admin shells are
  used as documented, and the meta/schema plumbing through push stacks works as described.
- **No mobile horizontal overflow.** The gitignored mobile reference screenshots make the
  site look broken at 375px; a real-browser check shows the layout is fine (see D4 — the
  capture method is the defect, not the site).

## 2. Defects (visible or functional bugs)

### D1 — Public sermon titles read "Sunday 28Th June 2026"
`SermonCreationService.php:749` passes generated titles through `Str::title()`, which
uppercases ordinal suffixes: "28Th", "1St", "2Nd", "3Rd". These are **stored** in
`sermons.title`, so the sermons index, sermon pages, feeds, and meta tags all show them.
The archive currently displays dozens of these. Fix requires both a code change and a
data backfill. (The other `Str::title()` call sites — breadcrumbs, meeting slugs,
preacher names — operate on digit-free strings and are unaffected.)

### D2 — The Lato bold `@font-face` is invalid, so true bold never loads
`resources/css/app.css:84` declares `font-style: bold` — not a valid `font-style` value
(`normal | italic | oblique`). Browsers discard the invalid descriptor, so the face
registers with the default `font-style: normal` **and** default `font-weight: 400` (the
`font-weight` descriptor is missing too), colliding with the regular face registered just
above it. Depending on the browser's duplicate-face resolution, either bold text is
synthesised (fake bold) or `lato-bold.woff2` shadows the regular face for body text.
Either way, no correctly-registered 700-weight Lato exists.

### D3 — The home hero requests Oswald 800; only Oswald 400 is shipped
`.home-hero-title` (`resources/css/tailwind/components/home.css:13`) sets
`font-weight: 800`, but `resources/fonts/` contains only `oswald-regular.woff2`. The
browser synthesises the extra weight, which renders smeared/blurry at `text-6xl` — the
single most prominent piece of type on the site. A real bold/semibold Oswald file is
needed (or the weight request dropped).

### D4 — The style guide's mobile screenshot commands produce broken references
The guide's `--window-size=375,3500` headless-Chrome loop yields captures where the
layout viewport is ~500px (headless Chrome's minimum window width) but the image is
cropped at 375px — every mobile reference shows phantom right-edge clipping and a
missing hamburger button. Verified against a real 375px browser frame: the live site has
no overflow (`scrollWidth == clientWidth == 371`). Anyone consulting the references
would "fix" a bug that doesn't exist. The repo already runs Playwright for visual
regression; captures should go through it (device emulation) instead.

## 3. Documentation drift (guide/skill vs codebase)

### DR1 — The guide describes a build architecture that no longer exists
Guide §2 names `resources/css/app.scss`, `tailwind.config.js`, and
`resources/css/tailwind/components/_*.scss`. None exist: this is Tailwind v4 with
CSS-first configuration — tokens live in an `@theme` block in `resources/css/app.css`,
and the partials are plain `.css`. §3 says brand colours come "from `tailwind.config.js`".

### DR2 — The guide's button-variant vocabulary doesn't match the component
Guide §6 documents `primary` (correct), `secondary` "designed to sit inside a teal
gradient border" (the code and CTA components actually use `featureOutline` for that),
and never mentions `feature`, `warning`, or `success`, all of which exist. §13's CTA
snippet uses `variant="secondary"` where every real call site uses `featureOutline`.

### DR3 — The `frontend-design` skill's variant table is wrong
The skill documents a `default` variant (doesn't exist — the prop default *is*
`primary`) and claims `primary` is "green (admin save/create)" (it's the cbc-pattern
texture). An agent following the skill will select variants that don't exist or mean
something else.

### DR4 — §11's legacy-examples list points at files that are gone or fixed
`pages/create`, `pages/edit`, `meetings/create`, `meetings/edit` — deleted.
`livewire/auth/*` and the error views — already modernised (shared components, brand
focus rings). §2 also references `errors/4xx.blade.php` / `errors/5xx.blade.php`; the
actual files are `403/404/500/503.blade.php`.

## 4. Implementation inconsistencies

### C1 — `secondary` and `featureOutline` are byte-identical
`button.blade.php:27` and `:29` carry the same class string. Two names for one
appearance guarantees drift the day someone edits one of them.

### C2 — Hard-coded hex values shadow existing tokens
`button.blade.php` uses `text-[#145557]` (this is exactly `cbc-teal-dark`),
`#0e3a3c` (a gradient end-stop that exists nowhere in the palette), and `#590d16`
(the danger hover, an untokenised darker crimson). The `feature` variant also inlines a
gradient that duplicates `.bg-gradient-teal` with a *different* end colour — two
"brand" gradients that don't quite match.

### C3 — Two neutral ramps are mixed throughout
~650 `gray-*` usages vs ~50 `slate-*`, with the body background on `slate-200` and the
newer public components (page-card, empty-state) on slate while `x-card`, sermon-card
and all admin surfaces use gray. The hue mismatch is subtle but it is why some card
borders/backgrounds feel slightly "off" against the page. Tailwind v4 allows the gray
scale to be aliased to slate in `@theme`, unifying the rendered hue without touching
any call site.

### C4 — Card geometry differs between sibling components
`page-card`: `rounded-xl border-slate-200`. `sermon-card`: `rounded-lg
border-gray-300`. `x-card`: `rounded-lg border-gray-300`. The guide blesses "rounded-lg
or rounded-xl", which is how the divergence crept in — the standard should pick one per
context.

### C5 — The header repeats a ~40-utility class chain 15 times
Every desktop nav link, mobile section heading, and mobile page link in
`components/layout/header.blade.php` carries the same focus-ring/transition/active-state
chain inline. A small `nav-link` component would collapse ~120 lines and make the next
nav change a one-place edit.

### C6 — Near-duplicate and near-orphan colour tokens
`cbc-teal-darkest` (#134e4a) vs `cbc-teal-deeper` (#0f4143): visually near-identical,
two usages combined. `cbc-rose-muted`: two usages. Candidates for merge/retirement when
touched.

### C7 — Two competing prose rails
`<x-text>` centres copy at `max-w-lg xl:max-w-xl`; `<x-content-wrapper>` (the
documented "standard content rail") is `max-w-2xl xl:max-w-3xl`. The guide only
documents the latter. Body copy width therefore depends on which wrapper a page happens
to use.

## 5. Visual assessment (against "modern, simple, beautiful")

### V1 — The aqua placeholder artwork dilutes the brand (maintainer: retint)
The card-header fallback image (`public/images/headings/small/default.webp` and its
aqua low-poly siblings) is brighter and more cyan than the `cbc-teal` ramp. Because
page cards fall back to it whenever a page has no media-library image, whole rails
render as identical aqua rectangles (locally: nearly all of `/church`'s six cards) —
cards stop differentiating content, and the dominant colour on many pages isn't the
brand teal. Real photos do exist for some pages (e.g. `bible-study.webp`) and are used
where page media is attached; the problem is the fallback family.
**Decision: regenerate the placeholder family in the cbc-teal ramp, same filenames and
dimensions.**

### V2 — Typewriter hero (maintainer: keep)
The character-by-character hero animation (~4.6s to fully settle) reads as dated and
delays the page's message; it was flagged with a recommendation to calm it down.
**Decision: keep the typewriter — it's distinctive. Fix only the fake-bold rendering
beneath it (D3).**

### V3 — Centred multi-line body copy hurts readability (maintainer: change)
All paragraph copy is centred via `<x-text>`. Centred text has a ragged left edge, so
the eye loses its return point on every line — precisely wrong for the older
congregation the guide optimises for, and the narrow `max-w-lg` rail leaves the desktop
layout looking like a thin strip in empty slate. **Decision: left-align paragraph copy
on one unified rail; headings, CTAs, and card grids stay centred.**

### V4 — Overall
The bones are good: strong header/footer brand treatment, coherent card language,
restrained palette, real typographic hierarchy. The site's distance from
"modern, simple, beautiful" is mostly the accumulation of small things above — off-brand
placeholder art, faux-bold display type, hue-mismatched neutrals, centred prose — rather
than any structural problem.

## 6. Non-findings (checked, fine)

- No indigo/legacy focus patterns anywhere in `resources/views`.
- No mobile horizontal overflow (D4 explains the misleading screenshots).
- `wire:navigate` usage is consistent on internal links in sampled views.
- Reduced-motion handling exists for every animation found.
- The admin component set (`admin/*` shells, attention-strip, pipeline-steps,
  action-menu) matches its documentation in guide §2.

## 7. Maintainer decisions (2026-07-20)

| Question | Decision |
|---|---|
| Placeholder card artwork | Retint the existing poly texture family into the cbc-teal ramp |
| Typewriter hero | Keep (fix the font weight under it) |
| Body copy | Left-align paragraphs on one unified rail; headings/CTAs stay centred |
| Scope | Full: defects + consistency + visual refresh |
