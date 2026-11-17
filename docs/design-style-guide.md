# Crockenhill Design Style Guide

One guide for everything the church puts on a screen or a page: crockenhill.org (public and
admin), the projector notices, printed pieces and social graphics.

Part A is the brand: how the church looks and sounds. It applies everywhere.
Part B is how the website is built in this Laravel 13 + Livewire 4 + Tailwind v4 project.

> **Status (2026-11-17).** This guide combines the website guide with the style built for the
> animated notices. Its brand rules are the target; the site does not match them yet. See
> [§B11 Moving the site to this guide](#b11-moving-the-site-to-this-guide) for the order of work and
> for which parts of `docs/plans/DESIGN-SYSTEM-REFRESH-2026-07-20.md` this replaces.
>
> **Until B11 step 1 lands, the B3 tokens and the Montserrat font do not exist in
> `resources/css/app.css`.** UI work before then uses the live `cbc-*` tokens and fonts in the
> `@theme` block, and should not add new uses of the gradient, pattern or rounded-card patterns
> this guide retires.

---

# Part A — The brand

## A1. In one line

Bold type, flat colour, square shapes and movement that never gets in the way of reading.

## A2. Writing

- **Write for someone who has never been to church.** Use plain nouns for what happens: "Songs",
  "Prayers", "Bible readings", "Sermon". Avoid insider phrases.
- **Lead with the fact.** Say what it is, when and where. A notice or a card should make sense at a
  glance: headline, then day and time, then one line of description.
- **Keep it short.** Headlines are two or three words. Descriptions are one sentence.
- **Warm, not chatty.** "Join us for tea, coffee, homemade cakes and scones."
- **Times** are written `10:30am`; ranges take an en dash: `11:00–12:00`. Days are written in full:
  "Thursdays", "Wednesdays in term time".
- **Sentence case** everywhere except small labels, which are uppercase.
- **The three statements** (Worshipping God, Strengthening believers, Proclaiming Jesus Christ to
  all) organise content into services, things for believers and things open to everyone.
- **Show the Bible at the centre rather than announcing it.** Use the open Bible and its ribbon as
  a motif, not slogans.
- **Readers skew older.** Prefer short lines, generous size and plain words over cleverness.

## A3. The wordmark

Three stacked lines with a full stop:

```
Crockenhill
Baptist
Church.
```

- Montserrat 800, letter-spacing −0.035em, line-height 0.98, left-aligned.
- White on `black`. Reverse (black on white) is acceptable in print.
- Never on one line, never without the full stop, never in a colour, never outlined, never with
  effects.
- Clear space: at least the height of the capital C on every side.
- On the web, set it as live text with these values. The supplied artwork is a JPEG with no vector
  version, so use it only where live text is impossible.
- The older "CBC" square logo (favicons, `public/images/Primary.png`, theme colour `#16324f`) is
  being retired. A replacement favicon is still to be designed. Until then, leave the old one in
  place and don't use it anywhere new.

## A4. Colour

### Where the palette comes from

The existing brand has three anchors: **teal** (the website), **navy** (the CBC logo and favicon)
and **crimson** with its muted rose. The palette keeps those exact values and extends them around
the colour wheel in the same deep, slightly muted register:

teal → **moss** → **gold** → **coral** → crimson → **damson** → navy → teal

Each hue is a ramp of the same kind:

- **800–900, deep:** grounds, with `cream` text.
- **500, bright:** accents, shapes, large text, and grounds with `ink` text.
- **100, tint:** quiet surfaces and badges, with the hue's 800 or 900 as text.

Existing values are kept exactly: `teal-900` `#0f4143`, `teal-800` `#145557`, `teal-700` `#1d686a`,
`teal-500` `#249a97`, `navy-900` `#16324f`, `navy-700` `#34495e`, `crimson-900` `#590d16`,
`crimson-800` `#6b0f1a`, `rose-300` `#c07c84`.

### The palette

| Hue | 900 / 800 (deep) | 700 | 500 (bright) | 300 | 100 (tint) |
| --- | --- | --- | --- | --- | --- |
| teal | `#0F4143` / `#145557` | `#1D686A` | `#249A97` | | `#DCEFEC` |
| navy | `#16324F` / — | `#34495E` | `#5E8FCB` | | `#E1E8F1` |
| damson | — / `#4A2145` | | `#BC6CA9` | | `#F2E4EE` |
| crimson | `#590D16` / `#6B0F1A` | | `#E5485A` | rose `#C07C84` | `#F6E2E3` |
| coral | — / `#7E2E16` | | `#E8704A` | | `#FBE5DC` |
| gold | — / `#6E4E0E` | | `#F2B33D` | | `#FBF0D3` |
| moss | — / `#2C5532` | | `#7AAE55` | | `#E5F0DC` |

Neutrals: `black` `#000000`, `ink` `#0C1A1C` (a teal-black), `stone-600` `#5B6766`,
`stone-300` `#C9CFCC`, `paper` `#F1EBE0`, `cream` `#F7F2E8`, `white` `#FFFFFF`.

### Grounds

Every piece uses one ground, one text colour and one accent, sometimes two. Choose the ground by
the kind of content and keep that choice consistent; never pick one at random.

| Ground | Text | Accent | Used for |
| --- | --- | --- | --- |
| `black` | `white` | `gold-500` | Wordmark, hero, welcome, section dividers |
| `teal-800` | `cream` | `gold-500` | The house ground: general information, what to expect, calls to action |
| `teal-900` | `cream` | `gold-500`, `teal-500` (large) | Header, footer, deep panels |
| `ink` | `cream` | `gold-500`, `teal-500`, `crimson-500` | Groups, studies, columns of detail |
| `navy-900` | `cream` | `gold-500`, `navy-500` (large) | Evening events |
| `crimson-800` | `cream` | `gold-500`, `rose-300` (large) | Special and seasonal services (Christmas, Easter) |
| `crimson-500` | `ink` | `cream` (shapes) | Notices that need attention |
| `coral-800` | `cream` | `gold-500` | Hospitality (Coffee Cup) |
| `damson-800` | `cream` | `gold-500`, `damson-500` (large) | Social events, refreshments |
| `teal-500` | `ink` | `gold-500`, `coral-500` (shapes) | Children and families |
| `moss-800` | `cream` | `gold-500` | Schedules and dates |
| `gold-500` | `ink` | `cream` (shapes) | Wayfinding, practical signs |
| `paper` / `white` | `ink` | `teal-700`, `crimson-800` | Long reading, editorial pages |
| any 100 tint | its 800 / 900 | — | Quiet web surfaces: callouts, badges, filter chips |

### Contrast

Measured WCAG ratios. **Small text (under 24px) needs 4.5:1; large text, rules, focus rings and
control borders need 3:1.**

- **Safe for small text:** cream on every 800/900 ground and on `ink` (6.8–16); white on black (21);
  `gold-500` on black (11.3), ink (9.6), teal-900 (6.1), teal-800 (4.6), navy-900 (7.0), navy-700
  (5.0), damson-800 (7.1), crimson-800 (6.6), coral-800 (4.9) and moss-800 (4.6); ink on every 500
  bright (4.6–9.6) and every tint (14+); each hue's 800 on its own tint (6.7–10.7); `teal-700` on
  white (6.5) or paper (5.5); `stone-600` on white (5.9) or paper (5.0).
- **Large text only (24px and up):** `gold-500` on teal-700 (3.5); cream on teal-500 (3.1) and
  crimson-500 (3.5); `teal-500` on teal-900 (3.8), `navy-500` on navy-900 (3.9), `damson-500` on
  damson-800 (3.7), `rose-300` on crimson-800 (3.8), `moss-500` on moss-800 (3.3); `crimson-500` on
  paper (3.3).
- **Shapes only, never text:** `teal-500` on teal-800 (2.9) and `coral-500` on coral-800 (3.0).
- **Never text:** `gold-500` on white or paper (1.9). `stone-300` is for decorative dividers only:
  at 1.6 on white and 1.3 on paper it is too faint for input borders.

### Rules

- `gold-500` is the one accent that appears everywhere. Use it for the single thing that should be
  noticed: a highlight block, a time, a marker, the main button on a dark ground.
- On `gold-500`, text is always `ink` or `black`, never cream or white.
- Flat colour only: no gradients, no drop shadows, no transparency on text.
- No more than three colours in one piece (the ground, the text and one accent), plus a second
  accent at most.
- For long reading use `paper` or `white` with `ink`. Keep the strong grounds for heroes, cards,
  bands and section breaks.

## A5. Type

One family: **Montserrat**, variable weights 100–900, roman and italic, self-hosted woff2. Contrast
comes from weight, never from a second typeface.

- **Headlines are heavy and tight:** 800–900, letter-spacing −0.03 to −0.045em, line-height
  0.92–1.05.
- **Light italic is the counterpoint:** 300 italic for the first word of a two-part headline
  ("*Next* **Sunday**", "*Concise* **Theology**").
- **Labels:** 700, uppercase, letter-spacing 0.22em, small.
- **Body text:** 500 with line-height 1.5–1.6. Montserrat is wide, so body text on the web is at
  least 18px. Use 600–700 for short descriptions on coloured grounds.
- **Times and key details:** 800, often in the accent colour; use `font-feature-settings: 'tnum' 1`
  for columns of times.
- Use `text-wrap: balance` on headings and `text-wrap: pretty` on short paragraphs.

| Style | Web size | Weight | Line-height | Tracking |
| --- | --- | --- | --- | --- |
| wordmark | 72px (min 44px on phones) | 800 | 0.98 | −0.035em |
| display | 96px (min 48px) | 900 | 0.92 | −0.045em |
| headline | 56px (min 36px) | 800 | 1.02 | −0.03em |
| headline-light | 56px (min 36px) | 300 italic | 1.02 | −0.02em |
| title | 32px (min 26px) | 800 | 1.1 | −0.02em |
| detail | 28px | 800 | 1.1 | −0.01em |
| lead | 22px | 600 | 1.35 | 0 |
| body | 18px | 500 | 1.6 | 0 |
| small | 15px | 500 | 1.45 | 0 |
| label | 14px | 700 uppercase | 1.2 | 0.22em |

Use `clamp()` between the phone minimum and the desktop size. Projector scale (1920px stage):
headline 130–360, key details 60–150, body 44–76, labels 34–40.

## A6. Shape and motif

- **Square corners.** Blocks, panels, cards, buttons and inputs are rectangles. The only round
  things are circles: dots, discs, markers, avatars.
- **Solid blocks** of colour that wipe in, sit behind text as a highlight, or stand as columns.
- **Rules:** 5px solid lines above a block of text or between rows.
- **The chevron field:** thin zigzag lines at low opacity drifting behind the wordmark. Use it as a
  quiet texture on `black` or `teal-900`. It replaces the low-poly teal pattern.
- **Dots** separate details: "Thursdays ● 11:00–12:00", with the dot in the accent colour.
- **Geometric pictures:** objects built from circles, rectangles and simple paths in two or three
  flat colours (the cup from above, the mugs, the clock, the setting sun, the open Bible with its
  ribbon). No clip-art, and no icons that mix outlines and fills.
- **Photographs** are welcome on the website (people, the building, events) but stay photographs:
  full-bleed or in a square-cornered frame. Text goes on a solid ground beside or overlapping the
  photo, never on the photo behind a gradient scrim.
- **No** rounded cards, card shadows, gradient buttons or gradient text.

## A7. Layout

- Generous margins: 120px at 1920 wide (about 6%). On the web, 24px on phones rising to 96–120px on
  wide screens.
- **Left-aligned by default.** Centre only a single short statement.
- One idea per screen or section. Let a headline be very large and leave space around it.
- Asymmetric splits work well: a tall colour panel on one side, text on the other.
- Long text: about 65 characters a line.

## A8. Motion

Motion is part of the identity, but it must never make text harder to read.

- **Text arrives by masked reveal:** it rises out of a clipped box (`translateY(125%)` to `0`), by
  line, word or character, staggered 0.03–0.14s.
- **Shapes draw, scale, drop or wipe** into place. Colour blocks grow from one edge (`scaleX` /
  `scaleY` from 0).
- **Easing:** `cubic-bezier(0.16, 1, 0.3, 1)` (expo out) for arrivals,
  `cubic-bezier(0.87, 0, 0.13, 1)` (expo in-out) for wipes and moves, a slight overshoot for playful
  objects, sine in-out for slow loops.
- **Durations:** 0.7–1.2s for reveals on the projector; under 0.6s on the web. Ambient loops run
  for 2–8s.
- **Once revealed, text stays still.** Only background shapes keep moving.
- **No** fades as the main idea, no flashing, no parallax on scroll.
- Respect `prefers-reduced-motion`: show the finished state with no movement.
- On the web, reveal on first view only and never delay content a visitor is waiting for.

## A9. Do not

- Put the wordmark on one line, drop its full stop or colour it.
- Number lists for decoration (01 / 02 / 03).
- Use gradients, shadows, rounded cards or more than three colours at once.
- Set small text in a large-only pair (A4 Contrast).
- Animate text that people are trying to read.
- Introduce a colour that is not in the palette. Need a new one? Add a step to an existing ramp
  here first.

---

# Part B — Building the website

## B1. Scope and goals

- Public pages carry the brand at full strength: big type, colour grounds, the wordmark.
- Admin pages use the same fonts, colours and square shapes, but stay neutral, dense and
  task-focused (B9).
- Reuse existing Blade components before adding one-off markup.
- Prefer the modern Livewire component patterns already in `resources/views/livewire/admin/**`.

## B2. Core UI architecture

### Shell pattern

Every page Blade view has the same shape: `@extends('layouts.main')` + `@section('content')`
wrapping a component-based shell. The shell pushes head metadata onto stacks read by
`layouts/main.blade.php` and exposes a `$slot` for body content:

| Shell | Tag | Use for |
|---|---|---|
| Public CMS pages | `<x-page.shell>` | Controller-rendered CMS pages via `pages/show.blade.php` and all public read-side views (sermons, meetings, calendar, songs, children's corner, free-bible) |
| Auth pages | `<x-auth.shell>` | Login, register, password-reset, verify-email |
| Admin pages | `<x-admin.shell>` | Controller-rendered admin pages (not Livewire full-page) |

**`<x-page.shell>` props:** `heading` (required), `metaDescription`, `description`,
`headingpicture`, `headingpictureMobile`, `headingpictureTablet`, `area`, `slug`, `links`,
`canonical`, `showToolbar` (default `true`; pass `:show-toolbar="false"` to suppress breadcrumbs and
edit buttons), `metaTags` (default `true`; pass `:meta-tags="false"` when the view supplies its own
richer `<x-meta-tags>` / schema / JSON-LD via `@push('meta_tags')`). Has a `$fullWidth` named slot
for content that breaks out of the content wrapper.

**`<x-auth.shell>` props:** `heading` (required), `description`. No toolbar, no related pages.

**`<x-admin.shell>` props:** `heading` (required), `title` (optional, defaults to heading). Renders
breadcrumbs and the toast container.

For **Livewire full-page admin components**, keep the `#[Layout('layouts.admin')]` attribute and
compose `<x-admin.page>`, `<x-admin.list-shell>` or `<x-admin.form-shell>` directly; no
`<x-admin.shell>` wrapper.

Shared admin components (all on `/dev/components`):

| Component | Props | Use for |
|---|---|---|
| `<x-admin.pipeline-steps>` | `steps`: list of `{label, state: done\|active\|blocked\|todo}` | Compact pipeline progress |
| `<x-admin.attention-strip>` | `chips`: list of `{label, count, href}` | One-line "does anything need me?" summary |
| `<x-admin.action-menu>` + `<x-admin.action-menu-item>` | menu: `label`, `icon`; item: `link`, `icon` | A single `+ Add` dropdown replacing rows of header buttons |

`layouts/main.blade.php` reads `@push` stacks for `title`, `meta_description`, `meta_tags` and
`canonical`, with `@section` accepted for the full-width landing pages (`full-width-pages/home`,
`church`, `community`, `christ`, `christmas`) and the error views, which intentionally do not use
`<x-page.shell>`.

### Base layout and shared shell components

- HTML root: `resources/views/layouts/main.blade.php`
- Admin layout: `resources/views/layouts/admin.blade.php`
- Header: `resources/views/components/layout/header.blade.php`
- Footer: `resources/views/components/layout/footer.blade.php`
- Page header switcher: `resources/views/components/page-header.blade.php`
- Width wrapper: `resources/views/components/content-wrapper.blade.php`

### Tailwind v4, CSS-first

- Entry stylesheet and tokens: `resources/css/app.css` (`@theme` block). There is no
  `tailwind.config.js` and no SCSS.
- Component partials: `resources/css/tailwind/components/*.css`.
- New UI is Tailwind utilities; extract to `@layer components` only when the same multi-class
  pattern repeats in four or more unrelated files.

## B3. Tokens in code

### Colour

Every palette colour from A4 is a `@theme` variable with the `cbc-` prefix, so it never collides
with Tailwind's own `teal`, `gold` and so on:

```css
@theme {
  --color-cbc-black: #000000;   --color-cbc-ink: #0C1A1C;
  --color-cbc-stone-600: #5B6766; --color-cbc-stone-300: #C9CFCC;
  --color-cbc-paper: #F1EBE0;   --color-cbc-cream: #F7F2E8;

  --color-cbc-teal-900: #0F4143; --color-cbc-teal-800: #145557;
  --color-cbc-teal-700: #1D686A; --color-cbc-teal-500: #249A97; --color-cbc-teal-100: #DCEFEC;
  --color-cbc-navy-900: #16324F; --color-cbc-navy-700: #34495E;
  --color-cbc-navy-500: #5E8FCB; --color-cbc-navy-100: #E1E8F1;
  --color-cbc-damson-800: #4A2145; --color-cbc-damson-500: #BC6CA9; --color-cbc-damson-100: #F2E4EE;
  --color-cbc-crimson-900: #590D16; --color-cbc-crimson-800: #6B0F1A;
  --color-cbc-crimson-500: #E5485A; --color-cbc-rose-300: #C07C84; --color-cbc-crimson-100: #F6E2E3;
  --color-cbc-coral-800: #7E2E16; --color-cbc-coral-500: #E8704A; --color-cbc-coral-100: #FBE5DC;
  --color-cbc-gold-800: #6E4E0E; --color-cbc-gold-500: #F2B33D; --color-cbc-gold-100: #FBF0D3;
  --color-cbc-moss-800: #2C5532; --color-cbc-moss-500: #7AAE55; --color-cbc-moss-100: #E5F0DC;
}
```

Old names map to new ones. Keep them as aliases until no template uses them, then delete:

| Old | New |
| --- | --- |
| `cbc-teal-light` | `cbc-teal-500` |
| `cbc-teal` | `cbc-teal-700` |
| `cbc-teal-dark` | `cbc-teal-800` |
| `cbc-teal-deeper`, `cbc-teal-darkest` | `cbc-teal-900` |
| `cbc-crimson` | `cbc-crimson-800` |
| `cbc-rose-muted` | `cbc-rose-300` |

**Admin neutrals:** admin screens keep Tailwind's `gray-*` scale for borders, table stripes and
secondary text. Public pages use `cbc-paper`, `cbc-cream`, `white`, `cbc-stone-*` and `cbc-ink`.

### Type

```css
@theme {
  --font-sans: "Montserrat", "Segoe UI", Arial, sans-serif;
}
```

Self-host `Montserrat-Variable.woff2` and `Montserrat-Italic-Variable.woff2` in `resources/fonts/`
with `font-weight: 100 900` and `font-display: swap`. Delete the Lato and Oswald faces and
`--font-display`. During migration, `--font-display` can point at the same stack so `font-display`
classes keep working.

### Radius, shadow, rules

- Radius: `rounded-none` everywhere; `rounded-full` only for circles.
- Shadow: none on public pages. Admin may use a single 1px border to separate surfaces, not a
  shadow.
- Rule: `border-t-[5px]` in the accent colour above a block of text or between rows.

### Spacing

Use Tailwind's scale. The brand steps map to it as: 8px `2`, 16px `4`, 24px `6`, 40px `10`, 64px
`16`, 96px `24`, 120px `30`. Prefer `gap-*` for sibling spacing.

## B4. Typography in templates

- Page title (public): headline style, left-aligned, `font-extrabold tracking-[-0.03em]
  leading-[1.02]` at `text-[clamp(2.25rem,5vw,3.5rem)]`.
- `<x-h2>`: title or headline style, left-aligned, with a 5px `gold-500` or hue-matched rule above.
  It replaces the current centred gradient text and rounded bar.
- `<x-h3>`: title style, `ink` on light grounds.
- Labels above headings: label style, accent colour.
- Body: `prose` with the typography plugin themed to Montserrat 500, 18px, line-height 1.6, `ink`
  text, `teal-700` links (underlined), headings 800.
- Admin page heading: title style.

## B5. Layout

- **One prose rail:** `<x-content-wrapper>` / `<x-text>` give `mx-auto max-w-2xl xl:max-w-3xl px-6
  prose`, left-aligned. Headings and calls to action are left-aligned with the text.
- Card grids: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4`, widening to
  `lg:max-w-5xl xl:max-w-7xl`.
- Admin forms: `grid grid-cols-1 lg:grid-cols-3`, main column `lg:col-span-2`.
- Filter bars: `flex flex-wrap gap-4`.
- Body background: `white` on public pages, `cbc-paper` for long-reading pages if a warmer page
  helps, `gray-50` in admin. There is no grey page behind white cards any more; cards are told
  apart by colour grounds or rules, not shadows.

## B6. Components

### Buttons

Use `<x-button>` (link or button) and `<x-form-button>` (form submit/action). Square, Montserrat
700–800, no shadow, no gradient, no texture.

| Variant | Look | Use |
| --- | --- | --- |
| `primary` | `teal-800` ground, `cream` text; hover `teal-900` | Main action on light grounds |
| `feature` | `gold-500` ground, `ink` text; hover `cream` ground, `ink` text | Main action on dark or coloured grounds (hero, bands, cards) |
| `secondary` | 2px outline in the current text colour, transparent ground; hover fills | Second action beside a primary or feature |
| `outline` | 1px `gray-300` border, white ground, `gray-700` text | Neutral admin action |
| `ghost` | text only, hover `gray-100` | Toolbar and inline actions |
| `danger` | `crimson-800` ground, `cream` text; hover `crimson-900` | Destructive |
| `warning` | `gold-500` ground, `ink` text | Caution |
| `success` | `moss-800` ground, `cream` text | Confirmation |

`featureOutline` is removed (it was identical to `secondary`). Focus ring on every variant: 3px
`gold-500` on dark grounds, 3px `teal-700` on light, with a 2px offset.

### Inputs and form controls

Use `<x-input>`, `<x-select>`, `<x-textarea>`, `<x-toggle>`. They include label, hint and error
states, loading indicators and clearable inputs.

- Square corners, 1px `gray-300` border (`cbc-stone-600` where the border must reach 3:1 on a
  public form), `teal-700` focus ring.
- Errors in `crimson-800` text with a 5px `crimson-500` rule on the left of the field, never colour
  alone.
- Livewire: `wire:model.live` where immediate feedback is intended.

### Cards

- **Public cards** (`<x-page-card>`, `<x-sermon-card>`, `<x-clickable-card>`,
  `<x-calendar-event-card>`, and the children's talk card): square, no shadow, no border radius. Either:
  - a **colour ground** from A4 (event cards: ground by kind of event), with label, heavy headline
    and "day ● time" in the accent; or
  - a **white or paper card with a 5px rule on top** in the section's colour, for listings where
    many cards sit together (sermons).
- Cards with a photo: photo in a square frame on top, text on the card's ground below. No dark
  gradient overlay.
- **`<x-card>`** (admin and utility surface): white, 1px `gray-200` border, square.
- Placeholder images: replace the low-poly teal artwork with a flat ground and, where helpful, a
  geometric picture (A6).

### Section colours (proposal)

The site has three areas. Giving each a ground lets cards, bands and page headers signal where you
are:

- Church (who we are, find us): `teal-800`
- Christ (sermons, the gospel): `crimson-800`
- Community (groups, events): `navy-700`

The home page and Christmas use `black` and `crimson-800` respectively.

### Hero

`black` ground, the wordmark as live text, one line beneath, the chevron field behind, one
`feature` button. Text arrives by masked reveal (A8).

### Header and footer

Flat `teal-900` or `black`, no texture. Nav labels Montserrat 700. Active item: a 5px `gold-500`
rule underneath. Footer links `cream`, with labels in `gold-500`.

### Service times

The schedule pattern: big times in `gold-500` in one column, labels beside them, 5px rules between
rows, on `teal-800` or `moss-800`.

### Tables (admin)

- Wrapper `overflow-x-auto`; table `min-w-full divide-y divide-gray-200`.
- Header `bg-gray-50`, label style (`text-xs uppercase tracking-[0.22em] font-bold`).
- Row hover `hover:bg-gray-50`. Badges use a 100 tint with its 800 text, square.

## B7. Interaction

### Navigation

- `wire:navigate` on internal links; external links are plain anchors.

### Motion

- Alpine transitions for the mobile menu, toasts and expand/collapse: 150–250ms, the expo-out
  curve, no bounce.
- Reveals follow A8. Respect `prefers-reduced-motion` everywhere.

### Feedback states

- Inline alerts and toasts: a 100 tint ground with its 800 text and a 5px rule in the hue's 500
  (moss for success, crimson for error, gold for warning, navy for information).
- `wire:loading` spinners on async actions; progress bars as solid `teal-700` fills, square.

## B8. Accessibility baseline

- Skip link in the main layout.
- `aria-label` on icon-only actions; `role="alert"` for critical messages; `x-cloak` on hidden
  Alpine elements.
- Visible focus on every control (B6).
- Keyboard operation for every interactive control.
- Every text/ground pair from A4's safe list; nothing under 4.5:1 below 24px.
- Never colour alone for meaning: pair it with a word, an icon or a rule.

## B9. Public and admin

**Public:** the full brand. Wordmark, colour grounds, big type, motion on arrival.

**Admin:** same font, palette and square shapes, nothing else. Neutral gray surfaces, `teal-800`
primary buttons, dense readable tables, compact filters, a consistent top row of title + primary
action + filters + table. No colour grounds, no reveals.

## B10. Why

- **One typeface.** The wordmark and the notices are Montserrat. A single family keeps the site,
  notices and print visibly one church, and weight alone gives enough hierarchy.
- **18px body.** Montserrat is wider and rounder than Lato. At 18px with 1.6 line-height it reads
  comfortably for older readers; smaller sizes lose that.
- **Flat colour instead of texture and gradients.** It projects well, prints well, matches the
  notices and is easier to keep consistent than a set of gradients.
- **A palette grown from the old one.** Teal, navy and crimson are the church's existing colours.
  The new hues sit between them, so the site gains range without looking like a different
  organisation.
- **Gold as the one accent.** One warm colour against deep teal, navy and crimson tells the eye
  where to look, and it is legible on every deep ground.
- **Left-aligned text.** Left alignment is easier to read for paragraphs and matches the notices.
  A heading centred over left-aligned text looks detached from it.
- **Shared button and form components.** Focus rings, loading states and variant colours only stay
  consistent when they live in one component.
- **`wire:navigate`.** It keeps the SPA feel and Alpine state across navigations; without it, pages
  flash white.
- **`prose`.** CMS content can contain any HTML; the typography plugin styles all of it from one
  place.

## B11. Moving the site to this guide

This guide supersedes parts of `docs/plans/DESIGN-SYSTEM-REFRESH-2026-07-20.md`:

| July plan item | Now |
| --- | --- |
| 1.1 Sermon title ordinals | Still needed, unchanged |
| 1.2 Lato bold / Oswald 600 font repairs | Dropped: both fonts are replaced by Montserrat |
| 2.1 gray → slate alias | Still useful for admin only |
| 2.2 Button cleanup | Replaced by B6 (same variant names, new looks) |
| 2.3 Card geometry (`rounded-xl`) | Replaced by B6: square cards |
| 2.4 Header nav-link extraction | Still needed, unchanged |
| 2.5 Token retirement | Replaced by B3's rename table |
| 3.1 One prose rail, paragraphs left | Kept; headings are now left too |
| 3.2 Hero bold Oswald + keep typewriter | Replaced by the black wordmark hero (decision below) |
| 4 Retint placeholder artwork | Dropped: low-poly art is replaced with flat grounds |
| 5 Docs truth-up | Done by this guide; the skill and gallery still need updating |
| "No new fonts or colours outside `cbc-*`" | Lifted: Montserrat and the A4 palette are the new set |

**Open decision:** in July the typewriter hero was kept. This guide's hero uses the wordmark with a
masked reveal. Confirm before the hero is changed.

Suggested order, one PR each, each with Playwright baselines regenerated and reviewed by eye:

1. Tokens and fonts: add the A4 palette and Montserrat to `@theme`, alias old names (B3). No
   visual change except type.
2. Buttons, inputs and focus rings (B6).
3. Header, footer and hero; remove `bg-cbc-pattern` and `.bg-gradient-teal`.
4. Headings, prose and section colours (B4, B5, B6).
5. Cards and placeholder images.
6. Admin pass: fonts, square corners, button colours only.
7. Update `.claude/skills/frontend-design/SKILL.md`, `.agents/skills/frontend-design/SKILL.md`
   and `/dev/components` to match; regenerate `docs/design-references/`; remove the old aliases.

## B12. New feature UI checklist

Before merging UI work:

1. Existing components reused where possible.
2. Internal links use `wire:navigate`.
3. Colours come from the A4 palette and its grounds table; no new hex values in templates.
4. Montserrat only; headings heavy and tight; body 18px.
5. Square corners, no shadows, no gradients.
6. Every text/ground pair is on the A4 safe list for its size.
7. Mobile layout checked (stacking, wrapping, hit areas).
8. Loading, empty, error and success states included.
9. Keyboard and focus behaviour checked.
10. Motion follows A8 and stops under reduced motion.

## B13. Starter patterns

### Admin page

```blade
<div class="space-y-6">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-3xl font-extrabold tracking-[-0.02em]">Title</h1>
      <p class="text-gray-600">Subtitle</p>
    </div>
    <x-button link="#" variant="primary" icon="plus" inline>Create</x-button>
  </div>

  <div class="flex flex-wrap gap-4">
    <x-input placeholder="Search…" wire:model.live.debounce="search" class="w-64" />
    <x-select placeholder="Filter" wire:model.live="filter" class="w-48" />
  </div>

  <x-card>
    <div class="overflow-x-auto"><!-- table --></div>
  </x-card>
</div>
```

### Public call to action band

```blade
<section class="bg-cbc-teal-800 text-cbc-cream">
  <div class="mx-auto max-w-5xl px-6 py-16 sm:px-12">
    <p class="text-sm font-bold uppercase tracking-[0.22em] text-cbc-gold-500">Sundays</p>
    <h2 class="mt-2 text-[clamp(2.25rem,5vw,3.5rem)] font-extrabold leading-[1.02] tracking-[-0.03em]">
      <span class="font-light italic">Come</span> and see
    </h2>
    <p class="mt-4 max-w-xl text-lg font-semibold">Morning service at 10:30am, evening service at 6:00pm.</p>
    <x-button link="/visit" variant="feature" size="lg" class="mt-8">Plan your visit</x-button>
  </div>
</section>
```

### Event card

```blade
<a href="{{ $url }}" wire:navigate class="block bg-cbc-coral-800 p-10 text-cbc-cream focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-cbc-gold-500">
  <p class="text-sm font-bold uppercase tracking-[0.22em] text-cbc-gold-500">Coffee Cup</p>
  <h3 class="mt-2 text-3xl font-extrabold tracking-[-0.02em]">Tea, coffee and cake</h3>
  <p class="mt-4 text-xl font-extrabold text-cbc-gold-500">Thursdays <span aria-hidden="true">●</span> 11:00–12:00</p>
</a>
```

## Visual references

`docs/design-references/` screenshots (gitignored) show the site before this guide. Regenerate them
after each migration PR so they show the site as it now stands:

```bash
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
OUT="docs/design-references"
mkdir -p "$OUT"

# Full-page desktop (1440px wide)
for url_slug in "/:home-desktop" "/christ/sermons:sermons-index-desktop" "/church:content-page-desktop" "/dev/components:component-gallery-desktop"; do
  url="${url_slug%%:*}"; name="${url_slug##*:}"
  "$CHROME" --headless=new --window-size=1440,5000 --screenshot="$OUT/$name.png" --hide-scrollbars "http://localhost$url" 2>/dev/null
done

# Mobile viewport (375px wide)
for url_slug in "/:home-mobile" "/christ/sermons:sermons-index-mobile"; do
  url="${url_slug%%:*}"; name="${url_slug##*:}"
  "$CHROME" --headless=new --window-size=375,5000 --screenshot="$OUT/$name.png" --hide-scrollbars "http://localhost$url" 2>/dev/null
done
```

The mobile loop is known to produce poor captures (finding D4 in
`docs/reviews/design-system-review-2026-07-20.md`); moving both loops to Playwright device
emulation is part of §B11 step 7.
