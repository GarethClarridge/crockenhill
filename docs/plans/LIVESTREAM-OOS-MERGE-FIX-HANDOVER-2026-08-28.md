# Handover — livestream/OpenLP merge duplication

Written 2026-08-28. The next session builds the merge fix; this records what is
already known so none of it has to be rediscovered.

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
