# Plans index

Reconciled **2026-07-24**, amended **2026-07-31** (historic archive readiness remediation added;
R8/historic production readiness remains blocked) and **2026-08-02** (business-design amendment to
that plan: value case, review-load automation loop, current-era re-projection, public service
history ships first, no calendar-time gates), against the live code (not against commit
messages — every status below was checked by looking for the class, migration, config key or test
it claims). This directory holds only **active** plans; completed or superseded plans move to
`docs/archived-plans/` with an archival header explaining what superseded them. Open audit findings
(Mortician dead-code reports, Pathfinder link/SEO crawls) are consolidated in
`docs/issues/README.md`, not here.

## What changed since the 2026-07-20 reconciliation

- **The simplification spine is nearly finished.** R1–R11 all merged (2026-07-20/21). The heuristic
  service-structure cluster, the media visual stack and the song clusters are gone; transcription,
  audio preparation, song matching and the phase registry are each consolidated to one owner;
  1.7f shipped too, though it had been left unscheduled. **R12–R15 remain**, and R8's deletions
  remain entirely unexecuted (its *evidence* is what shipped).
- **The review-queue noise plan is complete and archived** (Workstreams A, B and C1–C6). Its
  follow-on discovery — phantom review state from superseded runs — was fixed in seven more commits.
- **The service workbench redesign largely landed** (steps A–D). Step E's Dusk and Playwright
  coverage is the outstanding piece.
- **Three gates released:** WP7 of the code-quality plan (needed R9–R11), the semantic-search and
  OBS-transcript plans (both needed 1.7a), and newcomer O19 (needed backlog 3.1).
- **Two new plans arrived** on 2026-07-24: children's-talk private storage, and historic archive
  import + promotion. The first was written to prevent a potential production data-loss bug; the
  following day's audit showed that bug had never had a victim.

## Amendments — 2026-07-25

- **`CHILDRENS-TALK-STORAGE-TO-SPACES` is complete and archived.** All work packages landed, the
  `private/` prefix is gone from the application entirely, and the interim `app-private` volume has
  been removed. **The data-loss bug never had a victim:** WP1's production audit found zero
  children's talks and zero private references, so WP0 and the observer removal were preventative
  and the migration run was cancelled as a no-op. Two operator items survive it — the
  `crockenhill_app-private` orphan volume on the host, and WP0's two-deploy acceptance check, now
  narrowed to `app-livestream` only. **This deletes the historic-archive plan's WP8 in full.**
- **A new plan came out of that audit, and is already complete and archived:**
  [SERMON-ASSET-DISK-MIGRATION-RECOVERY-2026-07-25.md](../archived-plans/SERMON-ASSET-DISK-MIGRATION-RECOVERY-2026-07-25.md).
  91 production sermon assets — transcripts and every thumbnail sub-kind, none of them children's
  talks — resolved to Spaces keys that did not exist, because the disk *config* was repointed without
  the *files* being moved. **The 56 stranded thumbnails were restored in production the same day**
  (748/839 present → 804/839, 0 stranded); the 35 destroyed transcripts are closed as accepted loss,
  because the only surviving corpus covers sermon ids 1–261 and the losses are 718–757 — an empty
  intersection, so §2.6's identity-verification problem never had a candidate set. **`audit:sermon-assets`
  now distinguishes `stranded on <disk>` from `missing`**, which is the durable outcome: the next disk
  repointing that orphans its objects is one command away from being visible.

## Amendment — 2026-07-29

- **R8 production convergence is now blocked on the correctness plan.**
  [R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md](R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md)
  replaces the lossy one-slot structure proposal, makes source assertions immutable and canonical
  projection order-independent, makes Manual review final, adds manifest-gated OpenLP and portable
  OoS evidence, and requires exact item-level local/production parity. Historic promotion now uses
  two linked bundles: Bundle A transports the complete durable media-processing result; Bundle B
  transports the reviewed canonical decision.
- **The historic-media plan was rewritten around that boundary.** Its artifact-retention work is
  already landed. Remaining work is isolated acquisition storage, explicit date/service identity,
  a normal-output contract and Bundle A. R8 owns combined-source review and production sequencing.
- `LOCAL-PROCESSING-PORTABILITY-2026-07-28.md` moved to `docs/archived-plans/`: its local-first
  goal survives, but the replacement transports normalized assertions and reviewed canonical
  revisions instead of copying the per-row parse cache.

## Do these first

1. ~~**Deploy `CHILDRENS-TALK-STORAGE-TO-SPACES` WP0.**~~ **Done 2026-07-25** — deployed, audited,
   and the whole plan is archived. See the amendments above for what it did and did not turn out
   to be.
2. ~~**`CODE-QUALITY-REMEDIATION` WP2.1 — the `#[Computed]` call-syntax fix.**~~ **Done 2026-07-24**
   (ten call sites, not nine). The 3× multiplication was reproduced as a failing test before the
   fix and now guards it (WP6.1). Two things fell out of it: a genuine pre-render staleness hazard
   in `dispatchMetadataUpdate()`, and gapped-key `preacherOptions()` in `ReviewsServiceSections`.

Everything else is a project, not a one-sitting fix.

## The spine

**[JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md)**
sequences the simplification work R1–R15 and now carries a per-item status column. **R1–R11 are
merged.** What is left:

| Item | State |
|---|---|
| R8 (spent one-shot deletions) | Evidence, runbook and production counts are done; **not one tool has been deleted** — every gate is BLOCKED or PARTIAL on data convergence. Now effectively an operator workstream (`docs/operations/r8-data-convergence-runbook.md`), and several rows are entangled with the historic-archive plan |
| R12 (bulk historic backfill) | **Ownership moved** to historic-media acquisition. Retention prerequisites are landed; remaining local processing produces Bundle A, while R8 owns canonical convergence and production application |
| R13 (5.3/5.4 re-measures) | Open. R1 recorded the soak's D6/5.3 observations as *not captured*, so this starts with a measurement window |
| R14 (test-suite fold-ins) | Open, and no longer blocked — all five flat suites still exist. Re-diff `AdminChurchServiceTest.php`: the workbench redesign modified it |
| R15 (archive the trackers) | Open; last |

**[JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md)**
is the parent decision record and historical context. All 20 removal decisions are signed off; six
Definition-of-done boxes are now ticked. The remainder plan is authoritative for status and order.

## Dependency map

```
READINESS WP0–WP6 ──▶ verified evidence/projector/review + portable Bundle A/B import seam
READINESS WP7 + G5 ──▶ complete local corpus + different-PK rehearsal + no-op rerun
READINESS G6–G8 ──▶ accepted production operation
READINESS G9 ──▶ exact closeout, public-service-history follow-up and cleanup release

CHILDRENS-TALK-STORAGE ──▶ HISTORIC retention baseline    [both landed]
SENTRY ──(release-tagged errors during the long import)──▶ Bundle A/B production apply

remainder R9–R11 (merged) ──▶ CODE-QUALITY WP7 (phpstan level 9)      [gate released]
                          └─▶ SERVICE-WORKBENCH (A–D landed; E open)  [gate released]

backlog 1.7a (merged) ──▶ SEMANTIC-SERMON-SEARCH (re-plan Phase 1)    [gate released]
                      └─▶ LIVESTREAM-TRANSCRIPT-REUSE (Part B re-scope) [gate released]

SONG-SCRIPTURE-AND-THEME ──(builds Phase 0 embeddings + shared `themes` table)──▶ SEMANTIC-SEARCH
HISTORIC Bundle A artifact audit ──(proves retained transcript corpus)──▶ SEMANTIC-SEARCH

SITE-SEARCH ──(occupies the `?q=` UI slot; semantic swaps the backend later)──▶ SEMANTIC-SEARCH

backlog 3.1 (merged) ──▶ NEWCOMER-UX O19                              [gate released]

DESIGN-SYSTEM-REFRESH ⇄ SERVICE-WORKBENCH step E  (Playwright baseline churn — see order note)
```

## Consolidated historic-import decision records

The following are no longer executable plans. Their invariants and useful prior art are consolidated
into the readiness-remediation plan below. They remain at their existing paths temporarily because
the R8 runbook and remainder plan cite them as decision records:

- [R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md](R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md)
- [HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md](HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)

Their former index slots 0–1 are intentionally not reassigned; this avoids another renumbering of
the unrelated active plans.

## Active plans, in recommended implementation order

| # | Plan | Status (verified 2026-07-29) | Why here |
|---|---|---|---|
| Gate | [HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md](HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md) | **WP0–WP4 merged 2026-08-04** (PRs 4–13; PR2/PR3 partial — B1/B2 crash fixes + reproducers outstanding; a gate-acceptance audit found further gaps in PRs 5/9/11/12 — see §17). "Merged" ≠ gate-passable. WP5–WP10 remain; PR1/WP8 skipped, independently pickable but required before G9 closeout (§15). Next: finish B1/B2, then PR14 (WP5). **Business-design amendment 2026-08-02** | Sole implementation plan for historic archive readiness: repairs evidence, projection, exceptional review, portable Bundle A/B, persistence, streaming, binding preflight and rehearsal before production mutation. The 2026-08-02 amendment adds the value case, makes review load a designed-down quantity with an automate-first census loop (§9.4), brings current-era re-projection into scope (§12.4), moves public service history to **ship first** against existing data, replaces every calendar-time gate with an evidence gate, and adds bulk-pass throughput and per-era accuracy sampling. Editorial/copyright/consent policy is deferred and recorded in §14.4. |
| 2 | [SENTRY-ERROR-TRACKING.md](SENTRY-ERROR-TRACKING.md) | Not started; no dependencies; **not installed** (no `sentry/sentry-laravel` in `composer.json`) | Its original motivation — land before the `SERVICE_STRUCTURE_MODE` flip — is spent; the flip happened 2026-07-19. Its **new** motivation is the long, unattended, irreversible-in-practice production bundle apply. Land it before readiness Gate G8, not after |
| 3 | [CODE-QUALITY-REMEDIATION-2026-07-19.md](CODE-QUALITY-REMEDIATION-2026-07-19.md) | **WP2.1 + WP6.1 done 2026-07-24**; rest not started; **WP7's gate is now released** | WP2.1 (the `#[Computed]` perf fix) and its WP6.1 query-duplication guard have landed — remaining WP2 items are 2.2–2.8, and WP6.2's structural test is still open. WP1 is now routine drift (its CVE premise was a stale local vendor tree — the lock has carried medialibrary 11.23.1 since 2026-07-03). WP2/WP3/WP6 any time; WP4 as maintainer answers arrive; WP5 rides R8; **WP7 (PHPStan level 9, ~800 errors, `phpstan.neon` still at `level: 8`) is unblocked now that R9–R11 have merged** — only Q4 sign-off stands in front of it |
| 4 | [SERVICE-WORKBENCH-REDESIGN-2026-07-23.md](SERVICE-WORKBENCH-REDESIGN-2026-07-23.md) | **Steps A–D implemented** (`98dd4cab5`, `473ba42c9`); step E outstanding | Remaining: Dusk coverage for edit-plan/review-row/technical-details/keyboard operation, a deterministic Playwright fixture at desktop and mobile widths, and deletion of the now-orphaned `partials/processing-run-header.blade.php` (no include, no PHP, no test references it). Sequence with plan 9: doing the design refresh first means generating the workbench's Playwright baselines once, against the final tokens |
| 5 | [NEWCOMER-UX-BACKLOG-2026-07-11.md](NEWCOMER-UX-BACKLOG-2026-07-11.md) | Approved; not started; **O19's gate released** | Highest visitor-facing value per hour in the directory, and Phase 0 is mostly production content rather than code. Start with O16/O20/O21 (production/data + copy), then O17 (address + map), then the newcomer path (N1/N2/N5). O19 can now be reassessed — backlog 3.1 landed 2026-07-16 and `RelatedPagePresenter` survived at `app/Presenters/`. O18/N3/N4 still need maintainer input |
| 6 | [SITE-SEARCH-2026-07-20.md](SITE-SEARCH-2026-07-20.md) | Approved; not started; no dependencies | Keyword (LIKE) search: Phase A adds a `?q=` box to the public sermon archive, Phase B a site-wide `/search` page + header entry. No AI, no new dependencies. Deliberately front-runs plan 10's UI slot — the semantic plan later swaps the ranking backend behind the same `q` param (contract recorded in both plans) |
| 7 | [SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md](SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md) | Approved; not started; no dependencies | Songs never touch the media pipeline, so this is independent of everything above. Scripture search + shared theme vocabulary + semantic lyric search on the members' song catalogue. **Builds plan 10's Phase 0 embedding foundations and the shared `themes` table** — neither exists yet — so running it first means plan 10 inherits both. Flag flips gated on two maintainer calibration reviews |
| 8 | [DESIGN-SYSTEM-REFRESH-2026-07-20.md](DESIGN-SYSTEM-REFRESH-2026-07-20.md) | Approved; not started; no dependencies | Five PRs: correctness fixes (sermon-title ordinals + backfill, font-face repairs), token/component consolidation, left-aligned prose + real display bold, placeholder-artwork retint, docs truth-up. Source review: `docs/reviews/design-system-review-2026-07-20.md`. The workbench redesign has already landed, so the "rebase over in-flight UI PRs" caution now points at plan 5's step E and plan 6's newcomer path. Typewriter hero stays (maintainer decision); the prod title backfill is maintainer-gated |
| 9 | [SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md](SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md) | Not started; **both backlog gates cleared**; re-scoped 2026-07-20 to retrieval-only | Items 2.3 and 1.7a both landed, so the header's "re-plan Phase 1 first" instruction is now the next action — written against `CreateSermonTranscriptFromService` + `ChurchServiceTranscript::sliceText()`, not the drafted `TranscriptionServiceInterface` extension. Best done after plan 8 (inherits Phase 0 + `themes`) and after historic Bundle A's artifact audit proves the retained transcript corpus survived promotion |
| 10 | [SONG-FAMILIARITY-RATING-2026-07-20.md](SONG-FAMILIARITY-RATING-2026-07-20.md) | Drafted; **awaiting maintainer sign-off on D1**; no dependencies | Traffic-light familiarity badge (green > 3×/2y, amber = sung within 5y, red = not sung in 5y) on the three admin song surfaces. Computed on read via the existing usage-subquery pattern — no migration, no stored counters. Picker work goes through `ChurchServiceFormData`. Admin-only; no badge on members' `BrowseSongs`. Small enough to slot anywhere once D1 is answered |
| 11 | [LIVESTREAM-TRANSCRIPT-REUSE-FROM-OBS-2026-06-20.md](LIVESTREAM-TRANSCRIPT-REUSE-FROM-OBS-2026-06-20.md) | Deferred; **Part B's re-scope trigger has fired** | 1.5 and 1.7a both landed, so Part B is now "one more `ServiceTranscriptionInterface` implementation" plus ingest and trust-gate plumbing. The cost case halved with the second Whisper pass, and a prod-local whisper.cpp sidecar (`TRANSCRIPTION_SERVICE_TYPE=local`) reaches the same saving for less work — compare before building. Part A (OBS live subtitles) is operational and can happen any time |
| 12 | [GOOGLE-ANALYTICS-ENHANCEMENT-2026-06-19.md](GOOGLE-ANALYTICS-ENHANCEMENT-2026-06-19.md) | GA1–GA4 shipped | Remaining GA6 is a **manual GA4-admin task for the maintainer** (register custom dimensions, mark conversions). GA5 is optional and needs a maintainer decision before anyone builds it |

## Waiting on the operator or maintainer (no agent can advance these)

| What | Where | Note |
|---|---|---|
| **Decide whether to delete the retained thumbnail sources on `public`** | archived sermon-asset disk-migration plan, §9.6 | ~61 files under production's `storage/app/public/sermons/thumbnails`. WP2 copied rather than moved, so these are its rollback — deleting them gives that up deliberately, and is not tidying. No deadline: they sit on a mounted volume and cost only disk. Reasonable trigger is having seen thumbnails render across the archive, not just the smoke-tested sermons |
| **Verify `app-livestream` survives two deploys** | archived children's-talk plan, WP0 | The last unverified piece of that plan. Upload a recording, deploy, confirm the source file is still there. `app-private` no longer needs proving — it was removed once WP1 showed it had never held anything |
| **Remove the orphaned `crockenhill_app-private` volume** | archived children's-talk plan; `docs/operations/production.md` | Dropping the mount does not delete the volume. After a post-removal deploy is up: `docker volume rm crockenhill_app-private`. Verified empty by WP1 |
| Historic/R8 data convergence — song catalogue sync, `play_date` import, media identity backfill, OpenLP/OoS evidence, Bundle A media results and Bundle B convergence | Historic archive readiness-remediation plan + remainder R8 + `docs/operations/r8-data-convergence-runbook.md` | Production mutation is blocked until readiness G8. The predecessor R8/historic plans are decision records, not executable sequences. Production has not been touched. |
| Run `CleanupReviewQueueNoiseCommand` against production | archived review-queue-noise plan, OD3 | The command shipped 2026-07-20 (dry-run-first, counts-only). No record it has been run in production |
| Answer D1 (song familiarity: "sung exactly once in 5 years") | plan 11 | Blocks the whole plan; the draft defaults it to amber |
| Answer Q3/Q4 (podcast `enabled` key; PHPStan ratchet sequencing) | plan 4 | Q4 is the last thing in front of WP7 |
| O32 production asset audit | `docs/issues/README.md` | Needs production access this checkout does not have |
| O16/O20/O21 production content fixes | plan 6, Phase 0 | Content/deployment actions, not code |
| GA6 GA4-admin configuration | plan 13 | Manual console work |

## Gated follow-up

- **Phase 9 code-quality review — COMPLETE 2026-07-19** (ran early; maintainer waived the
  structural-work gate). Findings:
  [../reviews/july-2026-simplification/code-quality-review-2026-07-19.md](../reviews/july-2026-simplification/code-quality-review-2026-07-19.md).
  Implementation is plan 4 above.

## Recently archived

| Plan | Archived | Why |
|---|---|---|
| `LOCAL-PROCESSING-PORTABILITY-2026-07-28.md` | 2026-07-29 | Superseded before implementation by the broader R8 convergence correctness plan. Its goal survives, but normalized source assertions and reviewed canonical revisions replace per-row parse-cache transfer. |
| `SERMON-ASSET-DISK-MIGRATION-RECOVERY-2026-07-25.md` | 2026-07-25 | Complete, and written/shipped/run inside one day. WP4 taught `audit:sermon-assets` to report `stranded on <disk>` distinct from `missing` — the durable outcome, since it reproduces the whole investigation in one command. WP2 (`media:restore-stranded-thumbnails`) restored all 56 stranded thumbnail references in production (50 distinct objects, 0 unrecoverable, 0 size mismatches), taking the audit from 748/839 present to **804/839, 0 stranded**; verified at the read path, not just the bucket, by five thumbnail URLs returning 200. WP1 needed no bespoke work — WP4 plus WP2's dry run *were* the measurement. **WP3 closed as accepted loss:** the 35 destroyed transcripts are sermons 718–757, the only surviving corpus covers ids 1–261, so §2.6's identity-verification problem never had a candidate set to work on. One operator decision recorded in its archival header (the retained `public` sources, which are WP2's rollback) |
| `CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md` | 2026-07-25 | Complete: WP0 (both volume mounts), WP1 (audits run against production), WP3a (observer hook), WP3b (the `private/` machinery), WP4 (section-publication candidates), and finally the removal of the `app-private` volume itself. WP2's migration run was **cancelled as a no-op** — WP1 found zero children's talks and zero private references in production, so the data-loss bug it was written for never had a victim. `grep -rn "private/" app` now returns comments only. Two operator items and one unrelated finding (the 91 missing assets, which became its own plan — also complete and archived, see the row above) are recorded in its archival header |
| `REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md` | 2026-07-24 | Complete: Workstream A (`c71ac1221`), Workstream B (`5843d40eb`), C1/C2/C4/C5/C6; C3 was superseded before work began. Residue recorded in its archival header — the production run of the cleanup command, the unmeasured before/after counts, and the follow-on phantom-review-state fixes (`684cacee4`..`36e32670f`) |
| `SERVICE-SCREENS-CONSOLIDATION-2026-07-19.md` | 2026-07-23 | All four phases present in the route/component structure; its Phase 1 view split superseded by the service-workbench redesign |
| `OOS-ARCHIVE-IMPORT-AND-PIPELINE-EVAL-2026-07-10.md` | 2026-07-20 | Complete 2026-07-11: harness + pipeline fixes shipped (PRs #1162/#1163/#1170), three eval runs done, gated create-only import executed. Unfixed eval findings recorded in its archival header for any future import work |

### 2026-07-05 reconciliation

| Plan | Why |
|---|---|
| `SIMPLIFICATION-PLAN.md` | All phases complete except 9/25, which became backlog items 2.3/2.4 |
| `JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md` | Review complete through Phase 8; only the Phase 9 brief remained live (now also complete) |
| `LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md` | Phases 1–5 shipped; Phase 6 superseded by backlog Workstream 1 (whose list corrects it in four places) |
| `LLM-SERVICE-SECTION-CLASSIFICATION-SPIKE-2026-06-19.md` | Superseded by the LLM-first plan |
| `SERMON-SECTION-EXTRACTION-REMAINING-FIXES-2026-06-21.md` | All items done or superseded |
| `LIVESTREAM-DAEMON-UPLOAD-2026-05-01.md` | Never started; designed around the heuristic analysis stack the backlog deleted. The 5 GB-upload problem is real — if it still hurts now that Workstream 1 is done, write a fresh (much simpler) plan |

## Conventions for new plans

- One plan per change; if a new plan overlaps an existing one, supersede explicitly (header on the
  loser pointing at the winner) rather than letting both stay "active".
- Every plan opens with a dated **status header**: started/not started, dependencies on backlog
  items, and what an agent must *not* do without maintainer input.
- When work lands, amend that header with what was verified in the code — not just a commit hash.
  A plan whose status is stale costs the next session a re-derivation.
- Work generated by audits (Mortician/Pathfinder) goes into `docs/issues/README.md` first, and is
  folded into a plan from there — per-issue report files do not accumulate.
