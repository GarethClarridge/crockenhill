# Handover — livestream/OpenLP merge duplication

Written 2026-08-28. The next session builds the merge fix; this records what is
already known so none of it has to be rediscovered.

> **STATUS 2026-08-28 — the fix is built, shipped and measured.** Commit
> `d6070899a` carries the section's `reading_reference` onto the projected item and
> teaches merge tier 5 to read it. Services 406, 617, 627 and 544 were re-projected.
> Four paid `structure:evaluate` draws followed. See **Outcome** at the foot of this
> document, which corrects one prediction below that turned out to be wrong.

## The defect in one paragraph

A livestream projection meeting an existing OpenLP order of service duplicates the
plan instead of merging into it. The merge machinery is not missing — it is being
starved of one field. `ChurchServiceItemSyncService::findStableMatch()` tries six
tiers before creating an item, and tier 5 (`hasAgreeingScriptureReference`) exists
precisely for readings whose two sources name the same passage differently. The
livestream projection writes a **descriptive** title ("Paul addresses the
Areopagus"), leaves `source_title` and `openlp_search_title` empty, and stores no
scripture reference on the item. Every tier therefore fails and the plan falls to
`kind => 'create'`.

**The reference was found and then discarded.** `service_sections` for the same run
holds it:

| section | item | section title | `reading_reference` | OpenLP item it should have matched |
|---|---|---|---|---|
| 612 | 6647 | Paul addresses the Areopagus | `Acts 17:22-31` | 3639 `Acts 17:22-31` |
| 613 | 6648 | Faith credited as righteousness | `Romans 4:4-5` | 3641 `Romans 4:4-5` |
| 614 | 6649 | Serving with God's gifts | `1 Peter 4:7-11` | 3643 `1 Peter 4:7-11` |

The detector was right every time. The projection dropped the field that proves it.

## The fix

Carry the section's `reading_reference` onto the item it projects — into
`source_title`, or a metadata scripture reference `hasAgreeingScriptureReference()`
reads — so tier 5 can do the job it was built for.

Do not "fix" this by making the projection emit reference-shaped titles. The
descriptive title is useful editorial output and is what the public archive shows;
the reference is provenance and belongs beside it, not instead of it.

**Behaviour is currently inconsistent, which is the clue to scope.** 2026-07-26's
item 6554 is titled "Bible reading: Joshua 8" — a reference in the title — and that
service has no duplicates. Only the descriptive-title path duplicates.

## State of the data

Four of six calibration services carry duplicates, from two mechanisms.

**Mechanism A — retry storm. CLEANED 2026-08-28.** Service 544 (2024-01-14) had four
processing logs (931, 934, 935 failed; 936 completed) from the masking bug fixed in
`84d44d71f`. Each run minted its own item set. Items **6630–6640 were deleted**:
byte-identical to 6641–6651, zero references from `service_sections`
(`church_service_item_id`, `matched_item_id`, `expected_item_id` all zero). Full
before-state in `storage/app/private/orphan-items-544-deleted-20260828.json`. The
service went 35 → 24 items and **0 duplicate positions**.

**Mechanism B — failed merge. NOT cleaned, needs the fix.** Services 406, 617 and 627
hold projection items alongside the OpenLP items they should have merged into.
These are *not* orphans: the projection item carries the timing and the section
link, the OpenLP item carries identification authority. Deleting either side loses
something, so they want the merge fix and a re-projection rather than a delete.

| service | date | items | duplicate positions |
|---|---|---|---|
| 406 | 2022-06-05 | 17 | 3 |
| 617 | 2024-12-22 | 31 | 7 |
| 627 | 2025-02-02 | 20 | 3 |
| 544 | 2024-01-14 | 24 | **0** (cleaned) |

One instance of Mechanism B was repaired by hand on 2026-08-28 as part of the
song-matching work: item 6561 duplicated 4251 (both "God of Glory", song 295);
section 519 was re-pointed at 4251 and 6561 deleted. That is why 627 shows 3 rather
than 4.

## Predicted, NOT verified

Deleting the 544 orphans should clear `duplicate_oos_item` and `unknown_oos_item`.
**It will NOT clear `out_of_order_oos_items`**, and the reasoning is worth keeping:
the service still holds two disjoint position ranges over one service, and among
`bibles` items alone —

```
3639  pos  7  Acts 17:22-31                    performed LATE  (38:02+)
3641  pos  9  Romans 4:4-5                     performed LATE
3643  pos 11  1 Peter 4:7-11                   performed LATE
6643  pos 16  Psalm 100                        performed EARLY (17:48)
6645  pos 18  A living hope through Christ
6647  pos 20  Paul addresses the Areopagus     = 3639
6648  pos 21  Faith credited as righteousness  = 3641
6649  pos 22  Serving with God's gifts         = 3643
```

— anchoring the early reading to position 16 and a later one to position 7 is a
same-raw-type inversion. **Any accurate detection trips it.** The hard failure is
the correct answer to a corrupt question, and only the merge fix removes it.

Verification needs a paid `structure:evaluate` run and was deliberately deferred.

> **VERIFIED 2026-08-28, and half of this was wrong.** The `duplicate_oos_item` /
> `unknown_oos_item` half is confirmed: neither code appears in any of the four
> post-fix arms. The `out_of_order_oos_items` half is confirmed as far as the
> orphan deletion goes, but the closing claim — *only the merge fix removes it* —
> is **falsified**. It persists on 2024-01-14 in all four post-fix arms, with the
> three duplicated readings merged away and the survivors anchoring in performance
> order. See **Outcome**.

## Consequences for IC5 item 6 calibration

The structure arms were scored against this contamination, so **every
`out_of_order_oos_items` measured on 2026-08-28 is suspect as evidence about any
model**. Combined with the demonstrated run-to-run non-determinism (same model,
same inputs, different structures on 5 of 6 services — arms 05 vs 07), the
Terra/medium "fail" recorded in `structure-arms-ruling-20260828.md` should be read
as **withdrawn, not upheld**. Its two grounds were:

1. a candidate-only hard failure on 2024-12-22, since **closed at source** by the
   song-suppression fix (Terra went 4 song sections + hard failure → 0 + none); and
2. a song-title regression on 2020-04-26, which Terra's post-fix draw scores 1.00,
   matching the incumbent — and which the incumbent itself reproduces at the same
   magnitude between its own two draws (2026-07-26: 0.75 → 0.50).

A genuine 6c ruling needs two post-fix draws per arm, against uncontaminated
services. That is four paid runs and should follow the merge fix, not precede it.

## Artifacts

| what | where |
|---|---|
| Truth manifest | `calibration-review-pack/structure-eval-20260827/manifest-with-truth-20260828.json` (sha256 `3baf79a4…`) |
| Structure arms 05–08 | `storage/app/private/arm-0{5,6,7,8}-structure-*.json` |
| Structure ruling (withdraw, do not delete) | `storage/app/private/structure-arms-ruling-20260828.md` |
| Sermon ruling (complete) | `storage/app/private/sermon-analysis-unblinded-ruling-20260828.md` |
| 544 orphan before-state | `storage/app/private/orphan-items-544-deleted-20260828.json` |
| Song-link repairs before-state | `storage/app/private/songlink-repair-before-20260828.json`, `section-title-repair-before-20260828.json` |

## Still open, unrelated to the merge fix

- Sermon arms 03 (Luna/none) and 04 (Luna/low draw 2) are **run but unscored**;
  6c needs blinded human scoring, including draw-to-draw stability against arm 02.
- Every service ends the sermon early (-0.8 to -25s, all six, both arms). A
  consistent signed bias, unexplained.
- `structure:evaluate` records no usage or cost, and scores only the primary sermon
  boundary — item 6b requires the former, and the operator's 2026-08-28 ruling that
  a pre-sermon prayer may be included or excluded needs the latter.
- Song-title misses cannot be diagnosed: `titleMetrics()` retains counts, never the
  detected strings.

---

# Outcome — 2026-08-28

## What was built

Commit `d6070899a`, two files plus four tests.

- `LivestreamSectionToServiceItemMapper::buildMetadata()` emits
  `metadata.scripture_reference` from the section's `reading_reference`.
- `ChurchServiceItemSyncService::hasAgreeingScriptureReference()` offers that field
  as a third candidate per side, alongside `title` and `source_title`.
  `ScriptureReferenceResolver::anyReferencesAgree()` already took lists, so the
  change is additive and the crossing-overlap rejection is untouched.

**Metadata, not `source_title`,** as the warning above asks. `source_title` means
"the line the originating source actually carried"; it is exposed by
`ChurchServiceItemResource`, edited in the admin form, and read by
`ChurchServiceSongLinker`. The detector carried a descriptive title *plus* a
separate reference field, and the item should say so. The descriptive title is
untouched.

The reference is read on **both** sides of the comparison, because either source
may arrive first. `mergeMetadataForSources()` then carries it onto the surviving
row, so it persists for later runs.

Gates: 7265 tests pass (86846 assertions), pint clean, PHPStan clean, Dusk 55 pass.
The merge test was confirmed to fail without the sync-service change.

## Re-projection

Ran `LivestreamChurchServiceProjectionService::project(refining: true)` per service
— *not* `service-tracking:reproject-current-era`, which is a different (pure)
projector with no per-service filter. Before-state:
`storage/app/private/reprojection-before-20260828.json`.

| service | date | items before | after | outcome |
|---|---|---|---|---|
| 544 | 2024-01-14 | 24 | **21** | 3 readings merged |
| 406 | 2022-06-05 | 12 | **11** | 1 reading merged |
| 617 | 2024-12-22 | 24 | 24 | no OpenLP readings exist to merge into |
| 627 | 2025-02-02 | 16 | 16 | reading already merged; song duplicate remains |

544 came out exactly as the evidence table predicted: 3639 / 3641 / 3643 survive
with their OpenLP identification authority and now carry the references, sections
612 / 613 / 614 point at them, and the projection interleaves (livestream 1–6,
OpenLP 7–19, livestream 20–21) instead of being appended wholesale. Zero orphaned
section links across all four services.

**617 and 627 not shrinking is correct.** 617 is the carol service — its OpenLP
plan holds no `bibles` items at all. 627's single reading had already merged; what
remains there is a *song* duplicate, `#4258 "This Earth Belongs To God #024b"`
against `#6563 "This Earth Belongs To God"`, which needs title normalisation, not a
scripture reference. If the "7" and "3" in the table above counted trashed rows,
both services were already clean.

**Note for anyone reading item ids in this document:** re-projection soft-deletes
and recreates projection items, so every livestream item id above has changed. The
ids in the evidence table (6647–6649) and in the 544 position listing no longer
exist. The OpenLP ids (3639, 3641, 3643, 2728, 4257) are stable.

## The manifest had to be refreshed first — and this nearly wasted the run

`StructureEvaluateCommand::resolveOosItems()` prefers a manifest entry's frozen
`oos_items` over the live database. `manifest-with-truth-20260828.json` froze the
**pre-merge** lists — 2024-01-14 still named 6647 / 6648 / 6649 beside 3639 / 3641
/ 3643. Running the arms against it would have re-measured the exact contamination
this fix removes, at full cost, and reported no change.

`manifest-with-truth-postmerge-20260828.json` (sha256 `c47357e4…`) replaces
`oos_items` from the live database for the four re-projected services and changes
nothing else. Transcripts are byte-identical (all six `transcript_sha256`
verified), so the detector's input is unchanged and only the validator's context
moves. The human truth is time- and text-keyed rather than item-id keyed, so it
carried over untouched — worth knowing, because it is what made the refresh safe.

Also worth knowing: `structure:evaluate --detector` defaults to the bound detector
on the grounds that "a bare run never costs money". This project binds `openai`.
**Every bare run bills here.**

## The 6c ruling

Four paid draws, arms 09–12, at commit `d6070899a`. Full ruling in
`storage/app/private/structure-arms-ruling-postmerge-20260828.md`; the 2026-08-28
ruling now carries a superseded header and is retained for provenance.

**Neither ground of the Terra/medium FAIL reproduces.** The candidate-only hard
failure on 2024-12-22 is gone in all four arms. The song-title regression on
2020-04-26 is gone — every arm scores 1.00 — and the candidate leads the aggregate
in both draws (75.0% / 87.5% against the incumbent's 68.8% / 68.8%). It also leads
on section types, reading references and latency.

**This does not adopt the candidate.** Item 6d's rule is economic and
`structure:evaluate` still records no usage or cost, so the 15%-cheaper test cannot
be evaluated. The safety gate is cleared; the economic gate is unmeasurable. The
incumbent is retained **by default, not on merit** — a different position from the
withdrawn ruling, and one that a cost-instrumented re-run could overturn.

**Draw-to-draw stability improved sharply.** The parent ruling saw same-model
divergence on 5 of 6 services; post-fix the incumbent's draws differ on one. Much
of what read as detector non-determinism was the validator reacting to a duplicated
item list.

## Still open

- **2024-01-14 still fails `out_of_order_oos_items` in all four arms.** The
  prediction that only the merge fix removes it is falsified. The readings now
  anchor in performance order, so the surviving inversion is something else — and
  the specific anchor pair is **not recoverable**, because `evaluateEntry()` keeps
  `hard_failure_codes` and discards the validator's message, which names both items
  and both positions. Retaining that message is the cheap fix and should precede
  any further paid run.
- **2025-02-02 regressed for the incumbent**, failing both post-fix draws where it
  previously failed one. Its unmerged song duplicate is the obvious suspect.
- Sermon arms 03 (Luna/none) and 04 (Luna/low draw 2) remain **run but unscored**.
- Every service still ends the sermon early, all six, all arms. Unexplained.
- `titleMetrics()` still retains counts and never the detected strings.

## Artifacts added

| what | where |
|---|---|
| Post-merge truth manifest | `…/structure-eval-20260827/manifest-with-truth-postmerge-20260828.json` (sha256 `c47357e4…`) |
| Structure arms 09–12 | `storage/app/private/arm-{09,10,11,12}-structure-*-postmerge*.json` |
| Post-merge ruling | `storage/app/private/structure-arms-ruling-postmerge-20260828.md` |
| Re-projection before-state | `storage/app/private/reprojection-before-20260828.json` |
| Live OoS item dump | `storage/app/private/live-oos-items-20260828.json` |

## Addendum — hard-failure messages now retained

The first "still open" item above is closed. `StructureEvaluateCommand::evaluateEntry()`
now writes `hard_failure_messages` alongside `hard_failure_codes` on every report
entry, sourced from `ValidationResult::$hardFailures` (which already carried
`code` + `message` per failure — nothing new had to be computed, just kept). A
future report can diagnose which two OoS items/positions trip
`out_of_order_oos_items` on 2024-01-14 without a fresh paid run. Covered by
`StructureEvaluateCommandTest::a_hard_failure_records_the_validators_message_alongside_its_code`.

This does not by itself explain the 2024-01-14 inversion — that still needs a
report run (bound detector, so it bills) to read the new field.

## Addendum — structure:evaluate now costs its own runs

Item 6d's economic adoption gate (candidate must cost 15%+ less than the incumbent) could not be
measured before this: `structure:evaluate` ran real paid calls but never recorded what they cost.

- `OpenAiUsageLogger::extractUsage()` factors the token-usage shape already used for the log line
  into something a caller can reuse, rather than only ever writing to the application log.
- `ServiceStructureEvaluationTelemetry` (new, singleton) captures the usage of the OpenAI call
  `OpenAiServiceStructureService::detect()` just made, addressed by the evaluator to the manifest
  entry that triggered it — the same call-and-consume shape `SermonAnalysisEvaluationTelemetry`
  established for `sermons:evaluate-analysis`.
- `structure:evaluate` gained `--price-snapshot=` (optional, same dated-JSON shape as the sermon
  evaluator's). Every report entry now carries `usage` and `cost_usd`; the aggregate carries total
  tokens, `total_cost_usd` and `mean_cost_usd`. A failed entry that still billed (a malformed
  response, say) keeps its usage rather than losing it to whichever entry runs next.
- Cost is aggregated across **every** entry, not just the ones that validated — a run's true spend
  includes its failures.

Two structure-arm reports (incumbent vs candidate) run with `--price-snapshot` now settle item 6d
directly from their `aggregate.usage.mean_cost_usd`, without hand-totalling usage log lines.
