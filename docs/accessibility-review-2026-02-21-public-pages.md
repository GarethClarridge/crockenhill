# Accessibility Review Report (WCAG 2.2 AA)

**Date:** 2026-02-21  
**Project:** Crockenhill Laravel 12 public-facing website  
**Auditor mode:** Manual + automated + semi-automated code audit

## Scope

Key public-facing routes/templates reviewed:

- `/` (`resources/views/full-width-pages/home.blade.php`)
- `/christ`, `/church`, `/community`, `/christmas` (`resources/views/full-width-pages/*.blade.php`)
- Dynamic content pages (`resources/views/layouts/page.blade.php`, `resources/views/components/page-header.blade.php`)
- Sermon listing/detail pages (`resources/views/sermons/*.blade.php`, `resources/views/components/sermon-*.blade.php`)
- Meeting public pages (`resources/views/meetings/show.blade.php`, `resources/views/meetings/events.blade.php`)
- Shared public components (`resources/views/components/button.blade.php`, `resources/views/components/h2.blade.php`, `resources/views/components/page-card.blade.php`, `resources/views/components/hero-nav-link.blade.php`, `resources/views/components/layout/*.blade.php`)

## Test Methodology

### Manual testing

- Semantic structure review (headings, landmarks, relationships)
- Keyboard/focus behavior review from source for interactive elements
- Link purpose and navigation clarity checks
- Non-text content review (`img`, icon-only patterns)

### Automated checks (repo-local)

- Static scans with `rg` for animation/focus patterns (`opacity:0`, missing explicit focus classes, link styling)
- Template-level anchor/fragment integrity checks via script
- Heading-order scan across public templates

### Semi-automated checks

- Programmatic color-contrast calculations for implemented palette values and Tailwind colors used in interactive text/focus states
- Reproducible checks for common foreground/background combinations used in CTAs, headings, and focus indicators

> Note: UsableNet AQA and browser-driven runtime scans were not executable in this terminal-only environment. Findings below are evidence-based from source and computed checks.

## Issue Summary

- **High:** Gradient text used in key CTAs fails minimum contrast in lighter gradient stops.
- **High:** White CTA text on gradient backgrounds in page cards can drop below 4.5:1.
- **High:** Hero quick links are keyboard-focusable while visually hidden during intro animation.
- **Medium:** Shared button focus indicator (`focus:ring-green-500`) does not meet focus appearance contrast.
- **Medium:** Meeting details use an icon-only table structure, so row labels are not programmatically clear.
- **Medium:** Meeting gallery images are output without meaningful text alternatives.
- **Low:** Page cards expose two adjacent links to the same destination, creating redundant tab stops.
- **Low:** Public pages include link-purpose/structure quality issues (non-descriptive “here” link text and heading-level skips on event page).

## Detailed Analysis

### 1) Gradient CTA text contrast failures

- **Description:** CTA labels are rendered with `bg-clip-text text-transparent` gradient text, and the light stop (`#249a97`) fails 4.5:1 against light button backgrounds.
- **Evidence:**
  - `resources/views/full-width-pages/home.blade.php:100`
  - `resources/views/full-width-pages/home.blade.php:189`
  - `resources/views/components/button.blade.php:19`
- **WCAG 2.2 AA criteria violated:** `1.4.3 Contrast (Minimum)`
- **Severity:** High
- **Impact:** Users with low vision may not reliably read primary action labels.
- **Suggested remediation steps:**
  - Replace gradient-clipped CTA label text with a solid accessible color.
  - If gradient text must remain, darken all gradient stops so every rendered segment meets >= 4.5:1.
  - Add a forced-colors fallback (`@media (forced-colors: active)`) with non-transparent text.

### 2) White text on gradient CTA bars in page cards

- **Description:** Page-card CTA bars use white text over a multi-stop teal gradient; the light stop has insufficient contrast for normal-size text.
- **Evidence:**
  - `resources/views/components/page-card.blade.php:30`
- **Semi-automated check:** `#ffffff` on `#249a97` = **3.42:1** (fails 4.5:1)
- **WCAG 2.2 AA criteria violated:** `1.4.3 Contrast (Minimum)`
- **Severity:** High
- **Impact:** Card actions may become difficult to read, reducing navigation success.
- **Suggested remediation steps:**
  - Darken the lightest gradient stop or use dark text where necessary.
  - Add an overlay behind text if preserving current gradient is required.
  - Re-test all gradient stops, not only midpoint colors.

### 3) Hidden focusable links in home hero animation

- **Description:** Hero quick links start at `opacity: 0` and animate in after ~4s, but remain focusable before becoming visible.
- **Evidence:**
  - `resources/css/cbc/_home.scss:176`
  - `resources/css/cbc/_home.scss:181`
  - `resources/views/full-width-pages/home.blade.php:52`
  - `resources/views/components/hero-nav-link.blade.php:4`
- **WCAG 2.2 AA criteria violated:** `2.4.7 Focus Visible`, `2.4.11 Focus Appearance (Minimum)`
- **Severity:** High
- **Impact:** Keyboard users can tab to elements with no visible focus target, causing disorientation.
- **Suggested remediation steps:**
  - Do not hide keyboard-focusable elements with opacity-only delays.
  - Make links visible immediately, or remove from tab order until visible.
  - Add explicit `:focus-visible` styles and force visibility on focus (`:focus-within { opacity:1; transform:none; }`).

### 4) Shared button focus indicator contrast is insufficient

- **Description:** Base button style uses `focus:ring-green-500` on light backgrounds.
- **Evidence:**
  - `resources/views/components/button.blade.php:4`
- **Semi-automated check:** `#22c55e` on white = **2.28:1** (below 3:1 focus indicator threshold)
- **WCAG 2.2 AA criteria violated:** `2.4.11 Focus Appearance (Minimum)`
- **Severity:** Medium
- **Impact:** Keyboard users may not clearly perceive focus location.
- **Suggested remediation steps:**
  - Use a darker ring color that meets >= 3:1 against all likely backgrounds.
  - Keep a visible ring offset and adequate thickness across variants.

### 5) Meeting details use icon-only table headers

- **Description:** Meeting metadata is presented in a table where `<th>` cells contain only icons, not textual row headers.
- **Evidence:**
  - `resources/views/meetings/show.blade.php:13`
  - `resources/views/meetings/show.blade.php:17`
  - `resources/views/meetings/show.blade.php:27`
  - `resources/views/meetings/show.blade.php:40`
- **WCAG 2.2 AA criteria violated:** `1.3.1 Info and Relationships`
- **Severity:** Medium
- **Impact:** Screen-reader users may not get clear label/value relationships (e.g., time, location, contact fields).
- **Suggested remediation steps:**
  - Replace this table with a semantic description list (`<dl><dt>Label</dt><dd>Value</dd>`).
  - Keep icons decorative (`aria-hidden="true"`) and retain textual labels.

### 6) Meeting photos lack meaningful text alternatives

- **Description:** Public meeting gallery images are rendered with empty `alt` values and no decorative context.
- **Evidence:**
  - `resources/views/meetings/show.blade.php:78`
- **WCAG 2.2 AA criteria violated:** `1.1.1 Non-text Content`
- **Severity:** Medium
- **Impact:** Non-visual users lose potentially meaningful event/photo context.
- **Suggested remediation steps:**
  - Provide descriptive alt text when image content conveys information.
  - If truly decorative, move images to CSS/background context or explicitly document decorative intent in surrounding markup.

### 7) Redundant duplicate links in page cards create unnecessary tab stops

- **Description:** Each page card exposes two separate links to the same destination (image/title area + CTA row).
- **Evidence:**
  - `resources/views/components/page-card.blade.php:16`
  - `resources/views/components/page-card.blade.php:30`
- **WCAG 2.2 AA criteria violated:** `2.4.3 Focus Order` (usability impact)
- **Severity:** Low
- **Impact:** Keyboard and switch users navigate extra, repetitive focus stops.
- **Suggested remediation steps:**
  - Prefer a single primary link per card, or keep one focusable element and make other visuals non-focusable.
  - If two links are retained, ensure materially different purpose/context.

### 8) Link-purpose and heading-structure quality issues on public content pages

- **Description:**
  - Non-descriptive link text appears in Christ page copy (“which you can find online here”).
  - Some in-page links in Christ content target non-existent fragment IDs (`#top`, `#who`, `#why`), so assistive-tech and keyboard users do not land at the intended context.
  - Christmas page jumps from `h1` directly to repeated `h3` event titles, reducing heading hierarchy clarity.
- **Evidence:**
  - `resources/views/full-width-pages/christ.blade.php:78`
  - `resources/views/full-width-pages/christ.blade.php:98`
  - `resources/views/full-width-pages/christ.blade.php:137`
  - `resources/views/full-width-pages/christ.blade.php:198`
  - `resources/views/full-width-pages/christmas.blade.php:14`
  - `resources/views/full-width-pages/christmas.blade.php:25`
- **WCAG 2.2 AA criteria violated:** `2.4.4 Link Purpose (In Context)`, `1.3.1 Info and Relationships`
- **Severity:** Low
- **Impact:** Reduced scanability and weaker assistive-tech navigation cues.
- **Suggested remediation steps:**
  - Replace “here” with destination-specific link text (e.g., “Read Mark 1 on BibleGateway”).
  - Normalize heading hierarchy (e.g., section `h2` then event `h3` items).

## Priority Remediation Plan

1. Fix all contrast failures in shared components first (`x-button`, `x-h2`, `x-page-card`).
2. Resolve hidden-focus behavior in home hero animation.
3. Refactor meeting details to semantic label/value markup and add image alt strategy.
4. Clean up redundant card focus stops and low-severity content structure issues.

## Appendix: Reproducible Semi-automated Checks

- Contrast calculations used for key failures:
  - `#249a97` on `#f2f6fa` = **3.15:1** (fails normal text)
  - `#ffffff` on `#249a97` = **3.42:1** (fails normal text)
  - `#22c55e` on `#ffffff` = **2.28:1** (fails focus indicator contrast)
- Anchor integrity script found unresolved fragments in Christ page: `#top`, `#who`, `#why`.
- Heading-order script flagged `h1 -> h3` jump on Christmas page.
