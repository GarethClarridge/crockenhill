# Historic import — decision record, 3 September 2026

**Status:** D1, D2/D3, D4, D5 and D7 are implemented and applied; D6 remains
deferred and the §4 operator items remain open. See §6 for what landed and what
is still owed. Plan of record remains
[`HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md`](HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md);
this record supersedes it where the two disagree.

Working tree was clean when these decisions were taken (`9f3f61cc2`). No code
changed in the session that produced them; the implementation followed in the
next one.

---

## 1. Corrections to the existing record

Three things previously believed turned out to be wrong. Check these before
acting on any older note.

**#935 was not a broken or unreadable source.** Its structure projection returned
`other, other` with the notes *"fragmentary opening audio"*, *"unidentifiable
service audio"*, and that was read as a source defect. It was not: run **#936
processed the same recording 52 minutes later and completed** — twenty sections,
a real sermon (*Serving God by his grace*, 2593.8–3789.0 s), sermon #876, not
degraded. #935 was a **transient detector misread**.

**The no-sermon rate is 2 of 53, not 3.** Across every historic run that produced
a projected structure, only **#978** (`2024-02-11-evening`) and **#959**
(`2023-07-16-morning`) lack a sermon section. #935 is out for the reason above.
Of the two, #959 is already retired pending Phase 8, so **#978 is the only live
case**.

**Five of the six failed runs are stale attempts, not open questions.**

| run | identity | why it is stale |
|---|---|---|
| #931, #934, #935 | `2024-01-14-morning` | all superseded by **#936**, which completed |
| #937 | `2020-03-22-morning` | dead duplicate of **#938**, which completed 32 min later (same 1929 s, same 10.98 GiB) |
| #959 | `2023-07-16-morning` | retired by operator decision 2026-09-01, deferred to Phase 8 |

---

## 2. Decisions

### D1 — The detector may propose sermon-absence; the operator confirms it

**Decided: detector proposes, operator confirms.**

`OpenAiServiceStructureService`'s prompt already licenses this — *"A service has
exactly ONE primary sermon unless one is genuinely absent"* — and
`ServiceStructureMode` is `primary`, making the structure authoritative. But the
projection has no structured way to *say* a sermon is absent; on #978 the finding
landed in free-text `notes` (*"No conventional sermon, Bible reading or second
song is present in the transcript"*) where nothing reads it. Meanwhile
`SermonCandidateConfidenceService` decides from RMS speech segments alone and
never consults the structure, so the run fails on
`candidate_exceeds_maximum_duration` — a content fact discovered through the
wrong instrument.

**This overrules a claim recorded in the code.** `HistoricRunExclusion`'s
docblock states *"'this recording holds no sermon' cannot be detected at all — it
needs someone to watch the recording — so it is written here and nowhere else."*
That was true when written, before the LLM-first structure pipeline went
`primary`. #978 falsifies it. Update the docblock rather than leaving a
contradiction in the tree.

Confirmation is required rather than trusting the detector outright **because
#935 exists**: transient misreads happen, and an unconfirmed verdict must surface
as a review item rather than silently producing a sermon-less service.

Implies:
- A structured sermon-absence assertion in the projection schema
  (`OpenAiServiceStructureService`, `ServiceStructure`), carrying what stood in
  the sermon's place.
- `ExtractSermon` / `SermonCandidateConfidenceService` honour it: the run
  completes and keeps its sections instead of failing.
- The service is held unconfirmed until the operator approves the occasion.
- **Exclusion is the wrong instrument for #978** — `historic-import:exclude-run`
  is terminal and would discard work we want to keep. Its seven sections and
  church service 551 stay.

### D2 / D3 — A fixed `ServiceOccasion` enum, rendered publicly once confirmed

**Decided: fixed enum, shown on the public service page only after confirmation.**

Seed values: mission/guest presentation, carol service, baptism, communion,
church anniversary, gift day. Free text was rejected — no filtering, no
validation, and drift across spellings of the same occasion.

The public page `church/services/{date}/{service}` already exists, already lists
items, and already does not require a sermon. Gating the label on operator
confirmation keeps the standing rule intact: **no unconfirmed model output
reaches a public surface.** (This is why the service `summary` is stored but
never rendered there — see `resources/views/church/services/show.blade.php`.)

Also in scope, independent of the enum:
`ChurchServiceArchiveSeoPresenter::detailDescription()` hardcodes *"The order of
service, sermon, songs and readings for the…"*, which is simply wrong for a
service with no sermon. One conditional.

### D4 — Retire all five stale runs

**Decided: retire #931, #934, #935, #937 and #959** with operator notes, via
`historic-import:retire-run`.

They need truthful terminal dispositions for the Phase 8 exit gate regardless.
Between them they pin ~1.3 GiB. **Verify on implementation whether retirement
clears `Failed`** — `HistoricReviewSourceReclaimer::isEligibleRun()` excludes
`Failed` *before* checking obligations, so a run that stays failed keeps pinning
its source no matter what else is settled. #959 is superseded today and still
pins 0.13 GiB, which suggests superseding alone is not enough.

### D5 — Retarget `structure_missing_preached_reading` to a missing reference

**Decided: raise the review only when the sermon has no Scripture reference at
all.**

The flag is mis-targeted. It fires on "no `bible_reading` section within the
900 s pairing window before the sermon", raised by
`DetectServiceStructure::withSermonFlagged()` only after a **dedicated second
detector pass** specifically re-asking about the reading
(`readinglessSermonStart()`). `SermonAutoExtractionPolicy` already lists it in
`NON_DISQUALIFYING_REVIEW_FLAGS`, with the comment that it *"questions what
surrounds the sermon, not the sermon's own boundaries"* — the sermon extracts
correctly either way.

Yet it sets `needs_manual_review`, pinning ~2.2 GiB per run. **6 of the 8 flagged
sermons already carry a `sermon_reference`** taken from the preaching itself
(Philippians 1:3-8, Luke 20:41-44, Malachi 3:13-18, Acts 16:16-34,
Revelation 1:1-8, Titus 3:1-8). Only §645 (#940) and §742 (#952) have none, and
only those two give a reviewer anything to supply.

Deleting the flag outright was rejected: #940 and #952 would then publish with no
Scripture reference and nothing asking anyone to supply one.

Effect: class goes 8 → 2; **#958, #966 and #979 release outright.**

Rule this follows: *review earns its cost when the pipeline made a choice, not
when it noticed a fact* — same basis as the `sermon_boundary_conflicting_evidence`
deletion in `9f3f61cc2`.

### D7 — Instrument the staging-guard leak; do not chase it

**Decided: add logging, leave the static baseline absorbing it.**

Log depth and the live `historic_staging` disk root on every
activate/deactivate across `Queue::before`, `Queue::after`,
`Queue::exceptionOccurred` and `within()`'s own pair, so the next occurrence
names its own path. It strands nothing today (the static baseline in `bfba2895a`
makes the identity check immune) but fired **6 times in 3 runs in ~10 minutes on
2026-09-03**, so it is frequent enough that evidence will arrive quickly.

Escalate to a real repro only if it ever lands a run's durable output under a
batch root it should not be under.

---

## 3. Deferred

**D6 — when pass 2 dispatches, and at what size.** Explicitly not decided.
Inputs, measured 2026-09-03:

- **19 slots free now.** `380 GiB free − 290 GiB Phase 8 output − 48.0 GiB
  pinned = 42 GiB ÷ 2.2 GiB per unreviewed run`. The plan's older ~42 figure
  predates op4 retaining source.
- **Only 18 of 41 obligated runs still hold any bytes.** The other 23 ran under
  operations 2 and 3, before retention landed; reviewing them frees nothing.
- **The payoff is wildly uneven.** #970 (1 decision) 6.66 GiB, #982 (1) 6.51,
  #974 (1) 4.92 — **three decisions release 18.1 GiB, 38% of everything
  pinned**. Six runs / 15 decisions release 84%.
- `HistoricStagingHeadroom` models only transient bytes and has no term for
  cumulative durable output, so it **fails closed mid-pass rather than warning**.

Worked queue, ordered by GiB released per decision:
<https://claude.ai/code/artifact/9ec4d473-a34a-4996-a178-a9bddcfcc55b>
(source: `storage/scratch/pass2-review-queue.html`).

---

## 4. Operator items — only a person can settle these

- **Confirm #978's occasion** once D1/D2 land: `2024-02-11-evening`, a London
  City Mission "Operation Forgiveness" presentation evening.
- **Work the review queue.** 41 obligated runs / 74 decisions. Irreducible
  classes: `childrens_talk_speaker_review` (12, mandatory by design),
  `unmatched_song_section` and `song_title_marker_mismatch` (identification).
- **#959** (`2023-07-16-morning`, 405 s against a 64.8-min morning median) is on
  the open-decisions list from 2026-09-01 as a children's talk. D4 retires the
  run; whether the *identity* is reprocessed or excluded at Phase 8 is still open.

## 5. Still open, not decided

- **#937's `rms_generation` failure** — *"File size exceeds maximum allowed
  size"* on a 10.98 GiB source. D4 retires the run as a duplicate, but the cap
  itself is unexamined and will recur on the next oversized source.

---

## 6. What was implemented, 2026-09-03

Every decided item landed. D6 was not touched, and §4's operator items are still
a person's to settle.

### D1 — sermon-absence assertion

`sermon_absence` is a nullable object in the projection schema, carried as
`App\Data\ServiceSermonAbsence` (occasion + explanation) on `ServiceStructure`
and persisted with the rest of the structure. `ServiceStructure::fromSections()`
drops any assertion sitting beside a detected sermon section — the sections are
the detector's own timed reading and they win — and
`MediaProcessingLog::assertedSermonAbsence()` reads back through the same
reconciliation so a stray assertion can never stop a real extraction.

`ExtractSermon` stands down before resolving a plan when the assertion is
present, so `SermonCandidateConfidenceService` is never consulted about a
question RMS duration cannot answer. The run then takes
`ProcessingRunOrchestrator::concludeWithoutSermon()`, which dispatches the
custody tail that still applies — `PromoteHistoricAssets` (a sermon-less service
still has song videos and byte accounting) then `CleanupTemporaryFiles`, which
completes the run.

The service is held unconfirmed by a fifth review-source retention reason,
`sermon_absence_unconfirmed`, so the source survives for someone to watch.
`HistoricRunExclusion`'s docblock now separates *this service held no sermon*
(detectable, and not an exclusion) from *this recording holds no sermon* (still
only establishable by a person, and still what that class records).

### D2/D3 — `ServiceOccasion`

A fixed enum with the six seed values, stored on `church_services.occasion` with
`occasion_confirmed_at` beside it. The projector writes the proposal and never
touches a confirmation; `ChurchService::confirmedOccasion()` is what public
readers call, and the service page renders the label only through it.
`services:confirm-occasion` is the operator's instrument — dry-run by default,
`--occasion=none` is a real answer, and confirming releases the retention
obligation. `detailDescription()` now takes whether the page actually shows a
sermon rather than asserting one unconditionally.

Both columns travel in the historic normal-output contract (version 5 → 6): the
confirmation is a decision about what a public page may say, which is what the
service manifest carries.

### D4 — the five stale runs

Retired, with notes: #931, #934, #935 (superseded by #936), #937 (dead duplicate
of #938) and #959 (already retired 2026-09-02, unchanged).

The verification the decision asked for found **two** blockers, not one.
`HistoricReviewSourceReclaimer::isEligibleRun()` tested `Failed` before
obligations, so a retired run could never be released by settling anything else;
`Failed` is now allowed for a retired run, which is terminal by operator
decision. And `reviewSourceRetentionReasons()` read a retired run's *withdrawn*
sections as live obligations — it now returns none for a retired run, with
`scopeWithUnresolvedReviewObligation()` matching.

A third finding: `apply()` skipped any run with `superseded_at` set, so #931,
#934 and #935 — superseded by the projector, not by an operator — could never be
given a disposition at all. The retirement *record* is now what makes a run
retired; the supersession timestamp it already had is preserved.

### D5 — `structure_missing_preached_reading`

`SectionReviewFlagPolicy` demotes the flag when the sermon carries a
`sermon_reference`, so detection and reconciliation agree by construction.
`services:recompute-section-review-flags`, scoped to the six affected services,
took the class from 8 to 2 exactly as predicted: §645 (#940) and §742 (#952)
keep it, and **#958, #966 and #979 released outright**.

### D7 — staging-guard instrumentation

Every activate/deactivate records the depth it moved to, which of the four call
sites moved it, the job, and the live `historic_staging` root. A root still
carrying a batch directory at depth zero is the leak itself, so
`HistoricStagingGuard::leakedActivationEvidence()` detects it and the registry
logs that case as a warning carrying both paths; balanced transitions stay at
debug.

### Released bytes

2.63 GiB became reclaimable across four runs — #935 (1.19) and #959 (0.13) from
D4, #958 (0.63) and #979 (0.68) from D5. The scheduled
`media:cleanup-temp-files` sweep releases it; nothing was deleted by hand.

### Still owed

- **#978 cannot be confirmed yet.** Its stored structure predates the schema and
  carries no assertion, and the run is still failed at `manual_review_required`.
  It has to be reprocessed under the new pipeline before the occasion is a
  question anyone can answer — an LLM and FFmpeg run, so an operator decision.
- **26 further stale review rows** are sitting in
  `services:recompute-section-review-flags`'s unscoped dry run (12 inferred song
  matches above the write-back threshold, 12 songs with a stale
  `needs_manual_review`, 2 others). They are pre-existing drift unrelated to D5
  and were deliberately left alone; the command is idempotent and safe to run
  unscoped whenever they are looked at.
