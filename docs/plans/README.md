# Plans index

Last comprehensively reconciled **2026-08-14**, when the historic programme's three prior
authorities were superseded by the single
[incremental convergence plan](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) under
maintainer decisions REV-D1–REV-D4 and archived as evidence records. This directory contains
executable active plans only; completed or superseded decision records live in
`docs/archived-plans/`.

Updated **2026-08-19** to add the permanent OoS email-parser redesign after the prompt
simplification screen confirmed that whole-document prompt tuning trades semantic instability for
bookkeeping failures. Historic IC3 retains corpus truth and import policy; the new plan owns the
shared weekly/historic parser implementation.

## How to use this index

- The historic import has **one** plan. Its §2 decision record, §3 safety model and §4 finding
  dispositions are binding; the archived predecessors hold finding/decision evidence only.
  Wherever an older document says "G9" or "G9/WP10", read the new plan's **historic
  closeout / IC8**.
- The numbered product order below optimises for independently usable functionality. Maintenance,
  historic readiness and operator tasks can run in parallel where their own gates allow.
- A plan's “next slice” is the smallest useful deliverable, not permission to execute later gated
  phases.
- The code and tests remain authoritative. Re-check every dated file/line inventory before coding.

## Ownership boundaries (do not duplicate these elsewhere)

| Concern | Sole owner | Consumers / boundary |
|---|---|---|
| Historic source acquisition, Email/OpenLP/video manifests, Bundle A/B, hymn evidence, production rounds, releases and retirement | [Historic incremental convergence](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) | Other plans may consume admitted sermons/songs later; they do not add import steps or gates |
| Permanent weekly/historic OoS email normalisation, semantic annotation, deterministic compilation, targeted repair and parser evaluation | [OoS email parser redesign](ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md) | Historic IC3 supplies authoritative ground truth and consumes the parser; it still owns evidence tiers, finalisation policy and every historic round/release |
| Generic exception capture, release tags and Sentry privacy/noise policy | `SENTRY-ERROR-TRACKING.md` | Optional independent layer; historic D7 accepted rotating logs instead. It reports the architectural plan's final terminal-failure owner rather than defining state transitions |
| Generic storage durability, queue timing, rotating logs, schema-compatible rollback, runtime versions/startup and permanent processing/UI ownership | `ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md` | Historic plans may impose stricter operation evidence; July/code-quality/design/search retain the narrower ownership listed here |
| July deferred workflow decisions and duplicate-suite cleanup | Simplification closeout plan | No historic one-shot or bulk-backfill work remains in that plan |
| Public metadata keyword search and `/search` | `SITE-SEARCH` | Semantic sermon search later swaps only the sermon-archive ranking branch; site-wide search remains deterministic metadata search |
| Shared `EmbeddingServiceInterface`, `VectorMath`, embedding config and `themes` table | `SONG-SCRIPTURE-AND-THEME-SEARCH` | Semantic sermon search consumes these contracts and adds only sermon chunks/pivots |
| Timestamped sermon indexing and semantic sermon ranking | `SEMANTIC-SERMON-SEARCH` | It consumes durable current/historic artifacts but does not alter acquisition or promotion |
| Future live OBS sidecars | `LIVESTREAM-TRANSCRIPT-REUSE` | Never a historic-import shortcut; invalid/missing sidecars fall back to the normal full-service transcriber |
| Brand tokens, shared component variants and broad visual baselines | `DESIGN-SYSTEM-REFRESH` | Feature plans own their information architecture, copy and behaviour; rebase onto final tokens |

## Recommended product delivery order

These slices put working visitor/operator value ahead of infrastructure-only phases.

1. **Quick operator/content wins:** complete GA6 in the GA4 console; execute newcomer O16/O20/O21
   production content/data fixes; delete the workbench orphan partial and add its Dusk behaviour
   coverage.
2. **Design correctness only:** design refresh PR1a (title ordinal bug) and PR1b (font faces). They
   fix defects without imposing the broad token/baseline churn of later design phases.
3. **Newcomer arrival and path:** O17, then N1/N2/N5. This is the core first-visit journey and is
   valuable without new infrastructure. Coordinate its one header edit with search; neither feature
   owns a header refactor.
4. **Public keyword search:** Site Search Delivery 1, then the complete `/search` page plus its
   header entry. Each release is usable without embeddings or a new dependency.
5. **Small planning aids:** song familiarity list/detail, then its picker; then the song plan's exact
   scripture-reference search and curated theme browsing, both before semantic infrastructure.
6. **Visual consolidation:** design refresh Phases 2-5 after the preceding public header/page work,
   then create/approve the service-workbench Playwright baselines once against final tokens.
7. **Shared semantic layer:** the song plan owns the generic embedding foundation; song semantic
   search and sermon semantic indexing build on it independently. Site Search Delivery 1 must exist
   before the sermon archive switches its `q` ranking branch.
8. **Optional/deferred:** OBS sidecar Phase 0 evaluation; GA5 Measurement Protocol; trust assets,
   weekly content and other decision-gated newcomer work.

Search/semantic backfills may launch against the current admitted corpus. Historic completion is
not a launch dependency: as import rounds land (and finally at historic closeout), rerun
stale/idempotent backfills and calibration so newly admitted sermons, songs and service evidence
join the same features.

## Parallel programme and maintenance lanes

```text
HISTORIC IC1-IC3 email/ground truth ──> IC5 video ──> IC6 hymn ──> IC7 rounds ──> releases ──> IC8
            │                                                          │
            └── IC4 current-era back-fill (any time)                   └── first round needs AM3 D1

OOS PARSER D0-D5 deterministic build ──> D6 paid evaluation ──> D7 approved shared cutover
                 └── consumes IC3 truth; does not own or block historic rounds by omission

ARCHITECTURE AM1-AM5/AM11 safety ────────────────────────────────────────────> independent value
ARCHITECTURE AM8-AM10 permanent processing ──> waits for historic closeout unless a gate requires it

SIMPLIFICATION R13 measurement ───────────────┐
SIMPLIFICATION R14 test fold-ins ─────────────┴──> R15 archive

CODE QUALITY WP2/WP6 ─> WP3 / independent WP4 slices ─> PHPStan level-9 sessions/flip
```

None of these lanes blocks the public product sequence except where a plan explicitly says so.

## Active plans

| Order | Plan | Verified status | Next independently useful slice |
|---|---|---|---|
| H0 | [Historic incremental convergence](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) | **Plan of record since 2026-08-14** (REV-D1–D4). All predecessor code landed; **IC1 and IC2 implemented 2026-08-15** (`e5a81d191`, `aeeed8332`, follow-up `a4be644fd`); IC3 is the current package; production mutation only as §7 rounds. The model-only nano/Luna evaluation closed without a verdict and is not an IC3 gate | Finish IC3's HIR-D8 corroboration; then a semantic Email RG-A staging round, whose measured residue replaces the superseded legacy-v12 estimates; IC4 back-fill is drive-free any time |
| H0a | [OoS email parser redesign](ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md) | Deliveries 0–7 complete; the semantic parser is the sole weekly/historic path since 2026-08-20. The 2026-08-21 review slice (§9.16) corrected gate 5 to score the REV-D2 evidence tier, and §9.17 closed the 8 `content_scope` misfilings it exposed: the term was undefined, so a shared structural-frame rule now derives scope, and the recompiled v6 artifact scores **pass on all ten gates** with 0 misfiled evidence admissions | Archive the plan |
| M0 | [Architectural maintainability delivery](ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md) | Not started; immediate safety lane and post-G9 permanent-core lane are explicitly separated | Record D1-D4; AM2 timing and AM3 log-rotation tests can start independently |
| H1 | [Sentry error tracking](SENTRY-ERROR-TRACKING.md) | Optional; not installed; dependency approval required | If approved, install/configure errors-only capture; sequence caught terminal processing reporting after architecture AM8 |
| M1 | [Code-quality remediation](CODE-QUALITY-REMEDIATION-2026-07-19.md) | WP2.1/WP6.1 done; other items open; level 8 | WP2's small fail-closed/config/signature fixes plus the computed-call structural guard |
| M2 | [Simplification closeout](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md) | Only R13/R14/R15 remain; R8/R12 delegated | Start R13 measurement and fold the five flat suites in parallel |
| P1 | [Google Analytics enhancement](GOOGLE-ANALYTICS-ENHANCEMENT-2026-06-19.md) | GA1-GA4 shipped; GA6 manual; GA5 optional | Register dimensions/key events in GA4; decide GA5 separately |
| P2 | [Service workbench redesign](SERVICE-WORKBENCH-REDESIGN-2026-07-23.md) | A-D shipped; E coverage incomplete | Delete orphan partial and add Dusk behaviour coverage now; visual fixture after design refresh |
| P3 | [Design system refresh](DESIGN-SYSTEM-REFRESH-2026-07-20.md) | Not started | Split correctness PR1 into ordinal and font slices; defer broad visual phases until current public feature work lands |
| P4 | [Newcomer UX](NEWCOMER-UX-BACKLOG-2026-07-11.md) | Not started; O19 gate cleared | O16/O20/O21 operator fixes, then O17; none waits for the newcomer page |
| P5 | [Site search](SITE-SEARCH-2026-07-20.md) | Not started | Sermon metadata keyword search (Delivery 1), then a complete linked `/search` release |
| P6 | [Song familiarity](SONG-FAMILIARITY-RATING-2026-07-20.md) | Not started; non-blocking default is Occasional | Implement list/detail as one usable release; picker badge can follow independently |
| P7 | [Song scripture/theme/semantic search](SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md) | Not started | Exact tagged scripture search first, then themes; embeddings are a later shared foundation |
| P8 | [Semantic sermon search](SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md) | Not started; old backlog gates cleared | Define/index the already-durable timestamped eligible corpus; do not wait for full archive re-transcription |
| P9 | [OBS transcript reuse](LIVESTREAM-TRANSCRIPT-REUSE-FROM-OBS-2026-06-20.md) | Deferred pending real sidecar evidence | Operational live-caption trial + Phase 0 comparison; app plumbing only after go/no-go |

## Decisions and operator inputs

| Decision/input | Blocks only |
|---|---|
| Approve adding the current compatible `sentry/sentry-laravel` release | Sentry SDK/install slice (not release tagging) |
| Architecture D1 source-recording archive/retention policy | Architecture AM1 only |
| Architecture D2 normal rollback policy | Architecture AM4b only |
| Architecture D3 supported PHP/Node/Playwright matrix | Architecture AM12 only |
| Architecture D4 live Shadow-mode value | Architecture AM10 only |
| Code quality Q3 (`podcast.enabled`) and Q4 (level-9 config flip); approve removal of `spatie/laravel-data` | Respective code-quality slices only |
| Provide/approve song scripture import columns, theme vocabulary and calibration reports | Corresponding song data/flag flips |
| Newcomer photographs/consent, weekly editor, Christianity Explored decision | N3/N4/O18 only |
| GA4 console access and key-event choice | GA6 |
| Real OBS recordings + LocalVocal sidecars and preferred format | OBS Phase 0/1 |
| Historic operator inputs: ~14 email adjudications, video worksheet, current-era source recovery, era releases, §8.4 policy | Only the historic programme; see the incremental convergence plan §10 |

## Recently archived or superseded

- [OoS parser Luna non-inferiority evaluation](../archived-plans/OOS-PARSER-MODEL-EVALUATION-2026-08-17.md)
  — closed 2026-08-18 without a model verdict. Both `effort=none` arms materially disagreed with
  themselves on the same deterministic 30-source sample (nano 24/30; Luna 19/30), so the planned
  non-inferiority inference was refused. Nano remains configured only as the status quo; Luna was
  neither adopted nor rejected; the 536 discordant sources are not adjudicated.
  **Round six then ran the effort arms: `nano/low` is 77.0% at `n = 100` against `none`'s 80.0%, and
  routing stability got *worse*.** Reasoning effort is not the lever; do not run higher efforts or
  another model-only evaluation. The permanent response is now owned by the
  [OoS email parser redesign](ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md): narrow semantic
  annotation plus deterministic compilation, measured against IC3 truth rather than another
  whole-document prompt/model comparison.
- [Historic archive final import readiness](../archived-plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md),
  [historic archive readiness remediation](../archived-plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md)
  and [historic import safety remediation](../archived-plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md)
  — archived 2026-08-14, superseded by the incremental convergence plan (REV-D1–D4); they remain
  the F/B/HIR finding and FR-D/HIR-D decision evidence record.
- [July 2026 simplification backlog](../archived-plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md)
  — archived 2026-08-12; completed parent decision record. The closeout plan is self-contained.
- [R8 data convergence correctness](../archived-plans/R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md)
  and [historic archive import/promotion](../archived-plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
  — prior art only; their work-package sequences were superseded by the archived 2026-07/08
  historic plans and now by the incremental convergence plan.
- [Local processing portability](../archived-plans/LOCAL-PROCESSING-PORTABILITY-2026-07-28.md)
  — superseded by portable source assertions and Bundle A/B contracts.

## Conventions for active plans

- Open with a dated status, verified against code, and state what cannot proceed without a human.
- Give every PR/slice an observable outcome; infrastructure phases name their first consumer.
- Prefer an independently usable vertical slice. When a dependency is real, name one owner and
  make consumers reference it rather than copying its specification.
- Move completed/superseded plans to `docs/archived-plans/`; do not retain parallel executable
  sequences as “context”. Git history is the detailed archive.
- Work generated by audits lands in `docs/issues/README.md` first and is folded into one active
  owner from there.
