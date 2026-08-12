# Newcomer UX plan — production review 2026-07-11

> **Comprehensive reconciliation (2026-08-12): approved and not started, with one scope
> correction.** The homepage already states the 10:30am/6:00pm service times and has a “What to
> expect on Sunday mornings” CTA. Phase 2 must reuse or reposition that journey, not add duplicate
> service copy or a second CTA. O16/O20/O21 remain production-data/content checks; O17 and the
> newcomer-labelled route remain open in code. `RelatedPagePresenter` still exists, so O19 is now
> an independent small slice rather than a backlog-gated final phase.
>
> Site Search owns its search icon; this plan owns the single “New here?” information-architecture
> entry. The design refresh owns any shared header extraction/tokens. Land header changes
> sequentially or rebase them—none is a reason to combine search, newcomer content and design
> tokens into one PR.

This plan owns verified issues O16–O21 and opportunities N1–N5 in `docs/issues/README.md`.
O16, O20, O21, O17, O19 and the N1/N2 newcomer path can start independently. O18, N3 and N4
remain blocked on the maintainer inputs listed below.

Do not invent course dates, publish identifiable photographs/video without consent, promise weekly
content without an owner, add a second Bible-request form, rename the existing Christ / Church /
Community areas, or duplicate the homepage's existing Sunday-time/what-to-expect path.

## Outcome

**Who benefits:** primarily someone unfamiliar with Christianity and nervous about attending;
secondarily a committed Christian new to the area.

**What observably improves:** a mobile visitor can find the Sunday time, what to expect, children's
provision, and directions from an explicit newcomer path; every invitation has a working next step;
and the flagship Sunday information agrees across page copy and structured details.

The three journeys, in order, are:

1. attend a Sunday service;
2. attend another event;
3. start learning about Jesus.

## Delivery

### Phase 0 — restore broken and contradictory paths

1. **O16: restore `/christ/free-bible` in production.** Confirm the `pages` row before changing
   code. The view, Livewire form, mail flow, tests, and seeder row already exist. Restore/correct the
   production row through the normal content/deployment process and smoke-test rendering and mail
   delivery. Do not build a replacement form.
2. **O20: correct the Sunday-morning opening sentence.** Update production content and any genuine
   source-of-truth fixture/import. Preserve the existing reassurance about dress, language,
   YouTube, and children.
3. **O21: make Sunday details agree with the page.** Populate the canonical 10:30am start time in
   production and maintained seed data, verify the details card renders it, and coordinate the
   location treatment with O17. Do not invest in recurrence fields removed by backlog item 3.5.

These are separate operational/content changes and may ship as small PRs or maintainer actions.
Close each tracker issue only after production verification.

### Phase 1 — make arrival actionable

Deliver **O17**: place the full address and `BR8 8JS` near the start of Find Us and provide a
prominent external map/directions link. Use existing public CTA/component patterns and keep the
strong parking, public-transport, accessibility, and lift copy.

An exterior building photograph belongs to Phase 3 because it needs an approved asset; it does not
block the address and directions fix.

### Phase 2 — create the newcomer path

Deliver **N1, N2, and N5 as one coordinated journey**, not three copy-only PRs:

- add one top-level **New here?** entry without renaming the three existing areas;
- create its destination from existing facts: Sunday times, what happens, children's Outback
  provision, parking/directions, and who to look for on arrival;
- audit the existing homepage service-time paragraphs and what-to-expect CTA; move or surface the
  existing journey earlier only if the first-viewport test shows it is necessary—do not add a
  second version;
- surface a short factual children/families reassurance on the homepage;
- retain the three mission statements while adding visitor-question language only where it makes
  scanning clearer.

Reuse the Sunday-morning wording rather than maintaining two divergent accounts. Internal links
use `wire:navigate`; public primary actions use the existing teal CTA treatment. Verify at 390px
and desktop widths, including menu open/close, keyboard focus, reduced motion, and the first
viewport with the cookie banner present.

### Phase 3 — add trust assets when supplied

Deliver the approved subset of **N3** only after the maintainer supplies assets and confirms
consent: an exterior building photograph, current leader photographs, and selected replacements
for `default.webp` on people-focused activity cards. A welcome video is optional and should be
embedded only after hosting, consent, captions, and performance are decided.

Do not replace every placeholder mechanically. Prioritise images that help a visitor recognise a
person, entrance, or activity.

### Phase 4 — give changing invitations an owner

- **O18:** after the maintainer decides the future of Christianity Explored, add either a standing
  register-interest path or replace the sign-up promise with the agreed one-to-one offer. Reuse an
  existing contact mechanism and never publish a speculative date.
- **N4:** build a small manual "This week" block only if a named editor accepts freshness
  responsibility. Display dated information or an explicit freshness timestamp. Do not automate
  until the manual block proves useful.

### Independent slice — repair related-page relevance (O19)

Deliver **O19** against the settled `RelatedPagePresenter` seam. Define deterministic selection
rules that exclude legal/policy noise from ordinary visitor journeys, avoid stale
seasonal recommendations, suppress repeated title/description copy, and use human card labels.
Replace random selection only on the ordinary visitor surfaces where it causes the defect; do not
silently change admin/member or deliberately varied surfaces. This slice is independent of the
newcomer route and may ship after the production/content repairs.

## Maintainer inputs

1. Confirm consent and provide current photographs/video for Phase 3.
2. Name an editor for N4, or explicitly drop the weekly block.
3. Decide whether Christianity Explored will run again; if not, provide the standing alternative
   for O18.

These decisions do not block Phases 0–2.

## Suggested PR sequence

1. O16 production-data restoration and verification (operational; code only if the row is not the
   cause).
2. O20 + O21 copy/data consistency, with regression coverage for the rendered 10:30am detail.
3. O17 address + directions link.
4. O19 deterministic related-page relevance.
5. N1 + N2 + N5 newcomer page, navigation entry, and reuse of the homepage path.
6. Approved N3 assets.
7. O18 and/or N4 after their maintainer decisions.

Keep PRs 1–4 independent so urgent fixes do not wait for the coordinated newcomer/header change.

## Verification and definition of done

Each code change follows the repository's red-green bug workflow and quality gates. Use PHPUnit for
rendering/data behaviour, Dusk for navigation and interaction, and Playwright only when committing
intentional pixel-level baselines. Before merging UI work, verify mobile and desktop layout,
`wire:navigate`, keyboard/focus behaviour, and loading/empty/error/success states where applicable.

The plan is complete when:

- O16–O21 are closed or explicitly dropped with a recorded maintainer decision;
- a newcomer-labelled route exposes Sunday time, expectations, children, and directions;
- homepage and Sunday details agree on the service time;
- every invitation retained on the site has a working, maintained next step;
- decision-gated items are either delivered with named ownership/assets or explicitly dropped;
- production is rechecked after each phase, not only local fixtures.
