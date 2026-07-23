# Plans index

Reconciled 2026-07-20. This directory holds only **active** plans; completed or superseded plans
move to `docs/archived-plans/` with an archival header explaining what superseded them. Open
audit findings (Mortician dead-code reports, Pathfinder link/SEO crawls) are consolidated in
`docs/issues/README.md`, not here.

## The spine

**[JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md)**
is the execution spine for the remaining simplification work. It re-verifies every still-open
backlog item against the live code, corrects the backlog's stale statuses (4.1–4.3 and Workstream
6 already landed), and sequences the remainder R1–R15. **Read its R1–R15 order before picking up
any remaining item.**

**[JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md)**
is the parent decision record and historical implementation context. All 20 removal decisions are
signed off; its item descriptions and production-check gates remain useful, but the remainder plan
is authoritative for current status and execution order.

## Standalone plans, in implementation order

| Order | Plan | Status | When to do it |
|---|---|---|---|
| 1 | [SENTRY-ERROR-TRACKING.md](SENTRY-ERROR-TRACKING.md) | Not started; no dependencies | Any time — but ideally **before** the backlog's Workstream 1 flips `SERVICE_STRUCTURE_MODE` to primary (item 1.4), so the big pipeline change lands under release-tagged error tracking |
| 2 | [GOOGLE-ANALYTICS-ENHANCEMENT-2026-06-19.md](GOOGLE-ANALYTICS-ENHANCEMENT-2026-06-19.md) | GA1–GA4 shipped | Remaining work is GA6, a **manual GA4-admin task for the maintainer** (register custom dimensions, mark conversions). GA5 is optional and needs a maintainer decision before anyone builds it |
| 3 | [SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md](SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md) | Not started; gated; **re-scoped 2026-07-20 to retrieval-only** (semantic search + theme browsing + related sermons — the Q&A surface is removed) | After backlog items 2.3 (storage collapse) and 1.7a (one Whisper pass). Re-plan its Phase 1 first — 1.7a supersedes the drafted transcription changes (see the plan's status header) |
| 4 | [LIVESTREAM-TRANSCRIPT-REUSE-FROM-OBS-2026-06-20.md](LIVESTREAM-TRANSCRIPT-REUSE-FROM-OBS-2026-06-20.md) | Deferred; Part B stale as drafted | After backlog 1.5/1.7a; re-scope Part B as a `ServiceTranscriptionInterface` adapter (see the plan's status header). Part A (OBS live subtitles) is operational and can happen any time |
| 5 | [NEWCOMER-UX-BACKLOG-2026-07-11.md](NEWCOMER-UX-BACKLOG-2026-07-11.md) | Approved; not started | Start with the production/data and copy fixes (O16/O20/O21), then O17 and the newcomer path. O18/N3/N4 need maintainer input; O19 waits for backlog item 3.1 |
| 6 | [REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md](REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md) | Findings verified; not started | Workstreams A/B (queue predicates + data cleanup) ideally **before** the backlog 1.4 soak's Stage 3 review — the soak reviews services through the inbox this plan un-floods. OD1–OD3 need maintainer input; UI workstream C any time (C3 was delivered by the completed service-screen consolidation). Must not touch backlog 1.5 deletion-list classes |
| 7 | [CODE-QUALITY-REMEDIATION-2026-07-19.md](CODE-QUALITY-REMEDIATION-2026-07-19.md) | Ready to start; WP1 downgraded 2026-07-20 | Implements the Phase 9 review findings. WP1's "urgent" premise was a stale local vendor tree — the lock has carried the CVE-patched medialibrary 11.23.1 since 2026-07-03, so prod was never exposed; what remains of WP1 is a routine bump to latest (see the plan's WP1 correction note). WP2/WP3/WP6 any time; WP4 items as maintainer answers arrive; WP5 executes via the remainder plan's R8; WP7 (phpstan level-9 ratchet) **only after** remainder R9–R11 merge |
| 8 | [SITE-SEARCH-2026-07-20.md](SITE-SEARCH-2026-07-20.md) | Approved; not started; no backlog dependencies | Any time. Keyword (LIKE) search: Phase A adds a `?q=` search box to the public sermon archive, Phase B adds a site-wide `/search` page + header entry. Deliberately front-runs plan 3's Phase 3 UI slot — the semantic plan later swaps the ranking backend behind the same `q` param (contract recorded in both plans). No AI, no new dependencies |
| 9 | [SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md](SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md) | Approved; not started; no backlog dependencies | Any time — songs never touch the media pipeline. Scripture search + shared theme vocabulary + semantic lyric search on the members song catalogue. **Builds plan 3's Phase 0 embedding foundations and the shared `themes` table** (contract recorded in both plans), so if plan 3 starts later it inherits both. Flag flips gated on two maintainer calibration reviews |
| 10 | [DESIGN-SYSTEM-REFRESH-2026-07-20.md](DESIGN-SYSTEM-REFRESH-2026-07-20.md) | Approved; not started; no backlog dependencies | Any time, but merge after (or rebase over) any in-flight UI PR to avoid double Playwright-baseline churn. Five PRs: correctness fixes (sermon-title ordinals + backfill, font-face repairs), token/component consolidation, left-aligned prose + real display bold, placeholder-artwork retint, docs truth-up. Source review: `docs/reviews/design-system-review-2026-07-20.md`. Typewriter hero stays (maintainer decision); prod title backfill is maintainer-gated |
| 11 | [SONG-FAMILIARITY-RATING-2026-07-20.md](SONG-FAMILIARITY-RATING-2026-07-20.md) | Drafted; awaiting maintainer sign-off (one open decision, D1); no backlog dependencies | Any time. Traffic-light familiarity badge (green > 3×/2y, amber = sung within 5y, red = not sung in 5y) on the three admin song surfaces: catalogue list (+ filter), song detail, and the service-plan song picker. Computed on read via the existing usage-subquery pattern — no migration, no stored counters. Picker work goes through `ChurchServiceFormData` so it survives the completed service-screen consolidation. Admin-only: no badge on members' `BrowseSongs` |
| 12 | [SERVICE-WORKBENCH-REDESIGN-2026-07-23.md](SERVICE-WORKBENCH-REDESIGN-2026-07-23.md) | Ready to implement; maintainer direction recorded; gated on remainder R9 | Replaces the split plan/recording workbench with one chronological service record, one truthful status/next action, visible transcript evidence, neutral plan-coverage semantics, and collapsed technical diagnostics. Implement after R9 removes the heuristic alignment partials; write new tests only in namespaced suites ahead of R14 |

Items 1 and 2 are independent of everything and of each other. Items 3 and 4 both wait for the
backlog's Workstream 1/2 to reshape the ground they build on — starting them earlier means
building against code that is scheduled for deletion.

## Gated follow-up

- **Phase 9 code-quality review — COMPLETE 2026-07-19** (ran early; maintainer waived the
  structural-work gate). Findings:
  [../reviews/july-2026-simplification/code-quality-review-2026-07-19.md](../reviews/july-2026-simplification/code-quality-review-2026-07-19.md).
  Implementation is plan 8 above.

## Recently archived

| Plan | Archived | Why |
|---|---|---|
| `SERVICE-SCREENS-CONSOLIDATION-2026-07-19.md` | 2026-07-23 | All four phases are present in the route/component structure; its Phase 1 view split is superseded by the service-workbench redesign plan |
| `OOS-ARCHIVE-IMPORT-AND-PIPELINE-EVAL-2026-07-10.md` | 2026-07-20 | Complete 2026-07-11: harness + pipeline fixes shipped (PRs #1162/#1163/#1170), three eval runs done, gated create-only import executed. Unfixed eval findings recorded in its archival header for any future import work |

### 2026-07-05 reconciliation

| Plan | Why |
|---|---|
| `SIMPLIFICATION-PLAN.md` | All phases complete except 9/25, which became backlog items 2.3/2.4 |
| `JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md` | Review complete through Phase 8; only the Phase 9 brief remains live (see above) |
| `LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md` | Phases 1–5 shipped; Phase 6 superseded by backlog Workstream 1 (whose list corrects it in four places) |
| `LLM-SERVICE-SECTION-CLASSIFICATION-SPIKE-2026-06-19.md` | Superseded by the LLM-first plan |
| `SERMON-SECTION-EXTRACTION-REMAINING-FIXES-2026-06-21.md` | All items done or superseded |
| `LIVESTREAM-DAEMON-UPLOAD-2026-05-01.md` | Never started; designed around the heuristic analysis stack the backlog deletes. The 5 GB-upload problem is real — if it still hurts post-Workstream 1, write a fresh (much simpler) plan |

## Conventions for new plans

- One plan per change; if a new plan overlaps an existing one, supersede explicitly (header on the
  loser pointing at the winner) rather than letting both stay "active".
- Every plan opens with a dated **status header**: started/not started, dependencies on backlog
  items, and what an agent must *not* do without maintainer input.
- Work generated by audits (Mortician/Pathfinder) goes into `docs/issues/README.md` first, and is
  folded into a plan from there — per-issue report files do not accumulate.
