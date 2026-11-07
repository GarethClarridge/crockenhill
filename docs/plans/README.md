# Plans index

Last comprehensively reconciled **2026-08-24**. The historic programme's three prior
authorities were superseded by the single
[incremental convergence plan](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) under
maintainer decisions REV-D1–REV-D4 and archived as evidence records. This directory contains
executable active plans only; completed or superseded decision records live in
`docs/archived-plans/`.

The OoS email-parser redesign is archived and the shared semantic parser is the sole weekly/
historic implementation. The historic programme and video plan are current worklists; their completed execution
journals are archived evidence, not backlog. **Media-plan cleanup, 2026-11-06:** current work and
acceptance were reconciled from recorded evidence; no fresh corpus or production census was run.

## Deploy-time actions

Changes merged in code that still need applying to production by hand live in
[`PROD-ACTIONS-PENDING.md`](PROD-ACTIONS-PENDING.md). Check it after any deploy that
touched `.env` or production data. It is a running list, not a complete audit.

## How to use this index

**Historic work (reconciled 2026-11-06):** start with the
[video worklist](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md) for the next local
round, or the [programme §0.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#04-getting-production-complete-the-map-2026-10-16)
for production convergence. The six documents have distinct responsibilities:

| Document | Owns |
|---|---|
| This index | Navigation and ownership, no execution checklist |
| [Programme](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) | Cross-source convergence, production census/apply, release policy and IC8 |
| [Video worklist](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md) | Current processing work, dependencies and acceptance status |
| [Round runbook](HISTORIC-ROUND-RUNBOOK.md) | Local round execution, isolation, watching and recovery |
| [Rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md) | Binding operator rulings and supersession |
| [Output audit](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md) | Quality checks, samples, agent-first review and acceptance ledger |

The specialist [song measurement protocol](HISTORIC-SONG-ACCEPTANCE-MEASUREMENT-2026-10-11.md)
continues to own its units and sampling contract. Complete pre-consolidation snapshots are in
`../archived-plans/` with dated headers and links back to their active owners. Historical session
notes, old counts and superseded “next” instructions are evidence, never a second backlog.

**Keep this structure:** update current status in place; record each procedure once and link to it;
link completed work to durable evidence; archive detailed execution journals. When retiring an
old checkbox, cite its closure or carry it as explicitly unverified—do not silently drop it.
Do not append another dated handoff below an obsolete one.

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
| Permanent weekly/historic OoS email normalisation, semantic annotation, deterministic compilation, targeted repair and parser evaluation | Implemented; [archived OoS email parser redesign](../archived-plans/ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md) records its acceptance | Historic IC3 supplies authoritative ground truth and consumes the parser; it still owns evidence tiers, finalisation policy and every historic round/release |
| Generic exception capture, release tags and Sentry privacy/noise policy | `SENTRY-ERROR-TRACKING.md` | Optional independent layer; historic D7 accepted rotating logs instead. It reports the architectural plan's final terminal-failure owner rather than defining state transitions |
| Generic storage durability, queue timing, rotating logs, schema-compatible rollback, runtime versions/startup and permanent processing/UI ownership | `ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md` | Historic plans may impose stricter operation evidence; July/code-quality/design/search retain the narrower ownership listed here |
| July deferred workflow decisions and duplicate-suite cleanup | Simplification closeout plan | No historic one-shot or bulk-backfill work remains in that plan |
| Public metadata keyword search and `/search` | `SITE-SEARCH` | Semantic sermon search later swaps only the sermon-archive ranking branch; site-wide search remains deterministic metadata search |
| Shared `EmbeddingServiceInterface`, `VectorMath`, embedding config and `themes` table | `SONG-SCRIPTURE-AND-THEME-SEARCH` | Semantic sermon search consumes these contracts and adds only sermon chunks/pivots |
| Song title cleanup, genuine alternative titles and naming ownership across OpenLP sync | [Song title curation](SONG-TITLE-CURATION-AND-OPENLP-SYNC-2026-09-22.md) | Search and historic matching consume effective names; historic relinking/publication remain with the historic plans |
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
HISTORIC Email+OpenLP RG-A ──> thin round/video fixes ──> video ──> hymn ──> rounds/releases ──> IC8
            │
            └── current-era source back-fill after production remeasurement

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

**Historic video:** the [focused video plan](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md)
owns execution and acceptance; the [rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md) holds
every ruling in force; the [round runbook](HISTORIC-ROUND-RUNBOOK.md) is the procedure.
The [ensemble follow-up](HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md) carries evaluation ownership;
[talks](TALKS-AND-SHORT-TALK-DETECTION-2026-09-18.md) retains unverified visual/production work.
[Music and silence](MUSIC-AND-SILENCE-DETECTION-2026-09-25.md) remains a design reference;
[song acceptance](HISTORIC-SONG-ACCEPTANCE-MEASUREMENT-2026-10-11.md) owns its measurement protocol.
Nothing here authorises a dispatch, a provider call or a release.

**Outcome review, agreed 2026-11-06:** [Claude handoff and implementation follow-up](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md#11-outcome-review--handoff-for-claude-operator-agreed-2026-11-06).
Weekly stays paused until historic completion. Minimise manual review; judge complete talk/song
outputs, generalisation and operator effort. The register records supersession; the existing
video, audit, round and architecture plans own the remaining work.

**Pre-round review automation (planned 2026-11-06):** [video §4.1d](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md#41d-pre-round-review-automation-build-plan-2026-11-06)
owns the measured queue baseline and RA1–RA6 build sequence: evidence routing, stale-text refresh,
song boundaries/identity, repetition recovery, consequential structure checks and reusable answers.
Talk-type confirmation and other reserved decisions remain explicit; no round or data apply is started.

The [post-round audit work package](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md)
records discovery stopped at 45 services on 11-05, supplemental review stopped after 100 services
on 11-06, and a completed 2,127-file decode. Old October and November unfinished review batches
are not resume instructions. Current-output acceptance, semantic audio and delivery remain open;
changed hashes invalidate dependent checks.

| Order | Plan | Verified status | Next independently useful slice |
|---|---|---|---|
| H0 | [Historic incremental convergence](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) | Programme authority; media worklists reconciled 11-06. November round closed, later fixes/acceptance still pending; weekly remains paused. | Video §4.1a–d: apply K19 (built 11-06), K20 introduction miss, review-automation RA1–RA6, measured routes and current-output verification; audit §7 before production convergence and signed release. |
| M0 | [Architectural maintainability delivery](ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md) | Not started; immediate safety lane and post-G9 permanent-core lane are explicitly separated | Record D1-D4; AM2 timing and AM3 log-rotation tests can start independently |
| H1 | [Sentry error tracking](SENTRY-ERROR-TRACKING.md) | Optional; not installed; dependency approval required | If approved, install/configure errors-only capture; sequence caught terminal processing reporting after architecture AM8 |
| M1 | [Code-quality remediation](CODE-QUALITY-REMEDIATION-2026-07-19.md) | WP2.1/WP6.1 done; other items open; level 8 | WP2's small fail-closed/config/signature fixes plus the computed-call structural guard |
| M2 | [Simplification closeout](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md) | Only R13/R14/R15 remain; R8/R12 delegated | Start R13 measurement and fold the five flat suites in parallel |
| P1 | [Google Analytics enhancement](GOOGLE-ANALYTICS-ENHANCEMENT-2026-06-19.md) | GA1-GA4 shipped; GA6 manual; GA5 optional | Register dimensions/key events in GA4; decide GA5 separately |
| P1a | [Talks acceptance and production follow-up](TALKS-AND-SHORT-TALK-DETECTION-2026-09-18.md) | PR1–PR4 recorded landed 09-23/24; separate PR3 evaluation/PR5 transferred to historic work. Implementation history archived 11-06. | Verify remaining visual baselines and production actions; reconcile current talk-type/identity candidates and historic detection acceptance. Do not rebuild the feature from its old plan. |
| P2 | [Service workbench redesign](SERVICE-WORKBENCH-REDESIGN-2026-07-23.md) | A-D shipped; E coverage incomplete | Delete orphan partial and add Dusk behaviour coverage now; visual fixture after design refresh |
| P3 | [Design system refresh](DESIGN-SYSTEM-REFRESH-2026-07-20.md) | Not started | Split correctness PR1 into ordinal and font slices; defer broad visual phases until current public feature work lands |
| P4 | [Newcomer UX](NEWCOMER-UX-BACKLOG-2026-07-11.md) | Not started; O19 gate cleared | O16/O20/O21 operator fixes, then O17; none waits for the newcomer page |
| P5 | [Site search](SITE-SEARCH-2026-07-20.md) | Not started | Sermon metadata keyword search (Delivery 1), then a complete linked `/search` release |
| P6 | [Song familiarity](SONG-FAMILIARITY-RATING-2026-07-20.md) | Not started; non-blocking default is Occasional | Implement list/detail as one usable release; picker badge can follow independently |
| P6a | [Song title curation and OpenLP sync](SONG-TITLE-CURATION-AND-OPENLP-SYNC-2026-09-22.md) | Planned 2026-09-22; direction revised to one-off local correction and manual SQLite export back to authoritative OpenLP; no data changed | ST1 inventory, ST2 number/identity protection, local cleanup/research, then ST5 verified export and round trip |
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
| Fresh operational OpenLP database and manual installation of the verified corrected SQLite copy | Song title ST5 handoff; inventory and local rehearsal can proceed |
| Newcomer photographs/consent, weekly editor, Christianity Explored decision | N3/N4/O18 only |
| GA4 console access and key-event choice | GA6 |
| Real OBS recordings + LocalVocal sidecars and preferred format | OBS Phase 0/1 |
| Historic operator inputs: review consequential unresolved media questions; recover current-era sources after remeasurement; decide irreducible residue after the post-video census; approve exact era releases and verify the already-decided §8.4 access policy on production | Only the historic programme; see the incremental convergence plan §0 and §10 |

## Recently archived or superseded

- **Media-plan evidence cleanup, 2026-11-06:** full pre-cleanup snapshots preserve
  [video execution](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-11-06.md),
  [audit discovery/checkpoints](../archived-plans/HISTORIC-POST-ROUND-AUDIT-EVIDENCE-2026-11-06.md),
  [ensemble implementation](../archived-plans/HISTORIC-ENSEMBLE-IMPLEMENTATION-RECORD-2026-11-06.md),
  [talks implementation](../archived-plans/TALKS-IMPLEMENTATION-RECORD-2026-11-06.md) and
  [ruling narratives](../archived-plans/HISTORIC-RULINGS-REGISTER-SNAPSHOT-2026-11-06.md).
  Current files retain the agreed decisions, unresolved work and evidence owners; archival does
  not certify completion. The Claude handoff remains in video §1.1.
- [OoS email parser redesign](../archived-plans/ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md)
  — archived 2026-08-21 after Delivery 7 acceptance passed, the semantic parser became the sole
  weekly/historic path, the legacy whole-document path was deleted, and every remaining evaluation
  surface received explicit IC8 retirement ownership. Gate 5 now measures both REV-D2 unattended
  tiers and passes on the recompiled v6 artifact; Email RG-A is unblocked.
- [OoS parser Luna non-inferiority evaluation](../archived-plans/OOS-PARSER-MODEL-EVALUATION-2026-08-17.md)
  — closed 2026-08-18 without a model verdict. Both `effort=none` arms materially disagreed with
  themselves on the same deterministic 30-source sample (nano 24/30; Luna 19/30), so the planned
  non-inferiority inference was refused. Nano remains configured only as the status quo; Luna was
  neither adopted nor rejected; the 536 discordant sources are not adjudicated.
  **Round six then ran the effort arms: `nano/low` is 77.0% at `n = 100` against `none`'s 80.0%, and
  routing stability got *worse*.** Reasoning effort is not the lever; do not run higher efforts or
  another model-only evaluation. The permanent response is now owned by the
  [OoS email parser redesign](../archived-plans/ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md): narrow semantic
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
