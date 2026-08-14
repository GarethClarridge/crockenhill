# Historic import F64/F65 parser follow-up

**Recorded:** 2026-08-14  
**Status:** Baseline complete; Slices A and B implemented in code; archive-v12 corpus measurement complete; parsing improvement plan queued for a later session (see "Parsing improvement plan, queued 2026-08-14" below) — governance cleared by HIR-D7. **Revised 2026-08-14 after a critical review of the queued plan** — item 1's justification was withdrawn, its payoff resized, and a new item 0 added; see "Revision 2026-08-14" below.  
**Purpose:** Handoff for a later session to reduce the Email-lane review backlog without weakening the historic-import gates.

## Executive summary

The fresh F64/F65 staging run processed all 534 approved Email sources. It left 373 sources
`held_for_review`; 352 of those passed the source-identity gate but did not produce a plan that
was safe for unattended canonical import.

The 352 are not one failure class:

- 307 carried a confidence below the automatic-import threshold of 0.90.
- 91 carried `service_beyond_manifest`; 73 of those also carried low confidence and 18 did not.
- 27 had no report-level parse flag, but were held by plan-level validation or consensus state
  that the current source-level report does not expose.
- 48 were partial-content sources. They are evidence, not asserted complete orders, and should
  not be treated as ordinary full-order parser failures.

The highest-value parser work is remaining extraction instability, not blindly lowering the
confidence threshold. The run required a corrective second call for 506 of 534 sources (1,040
extraction attempts in total), and plan-level analysis found 138 review-required plans whose
attempts disagreed despite no detected content difference. The self-reported confidence score is
also weakly calibrated: exactness was only 78.7% in the 0.90–1.00 band.

**Read with the 2026-08-14 revision below.** Wherever this report says "full-plan exactness" or
"exact plan correctness" it means `exact_correct`, which checks date, service slot and non-emptiness
and never inspects an item. It is a sound measure of identity resolution and an unsound one for
extraction quality; the queued plan's item 0 exists to supply the missing item-level measure.

F64 itself passed its corpus proof: the fresh run produced zero phantom source-line validation
failures. F65 passed its intended design change: bookkeeping-only findings remain held for review
but are reachable by an operator rather than being structurally unapprovable.

## Evidence and scope

### Authoritative run

| Item | Value |
|---|---|
| Report | `storage/scratch/f64-f65-restage-20260814.json` |
| Report SHA-256 | `b698f3a56e5251e68ba3c800f240c55d3f51b26c0a0567678bbd4c4da4b2aa7c` |
| Parser | `archive-v11` |
| Fresh parse | Yes; raw extraction cache was bypassed and curation was reapplied |
| Approved / selected sources | 534 / 534 |
| Full / partial sources | 465 / 69 |
| Total extraction attempts | 1,040 |
| Sources requiring a corrective second attempt | 506 / 534 (94.8%) |
| Import errors | 0 |
| Working database | Untouched; import ran against isolated `crockenhill_rehearsal` |

The run was an import-mode staging run, so a held source remained pending and did not become a
canonical service. The command's non-success closeout is expected while held sources remain; it is
not evidence of a processing crash.

### Units of measurement

The report has three different units, which must not be mixed:

- **Source entry:** one approved archive email/file. The 373 figure is this unit.
- **Service plan:** one extracted morning/evening plan. A source can produce more than one.
- **Service identity:** one date plus service slot. Multiple sources can refer to one identity.

At source level, the 373 held entries consisted of 21 hard-gated entries and 352 entries with no
source-level gate reason. The current staging result produced 196 service rows, covering 159
approved staged identities; 362 of the 521 approved unique identities remained missing.

## The 352 gate-eligible held sources

`gate_eligible` here means that the manifest did not reject the source identity. It does **not**
mean that the extracted plan was auto-importable.

### Mutually exclusive source-level shapes

| Report-level shape | Sources | Interpretation |
|---|---:|---|
| `low_confidence` only | 234 | The source had no extra-service flag, but its primary result was below 0.90 |
| `low_confidence` + `service_beyond_manifest` | 73 | Low-confidence result plus an additional detected service |
| `service_beyond_manifest` only | 18 | High-confidence primary result, with an additional service beyond the manifest entry |
| No report-level parse flags | 27 | Held by plan-level disposition, validation, or consensus state not represented in the source flags |
| **Total** | **352** | |

The primary confidence bands for the 352 were:

| Primary confidence | Sources |
|---|---:|
| 0.00–0.49 | 18 |
| 0.50–0.74 | 127 |
| 0.75–0.89 | 162 |
| 0.90–1.00 | 45 |

The 45 sources at or above 0.90 are important. They show that confidence alone is not the full
hold explanation: 18 have the extra-service signal and 27 have no report-level parse flag. The
next parser/reporting change should expose their per-plan dispositions and validation reasons
before anyone changes thresholds.

### Partial sources

Of the 352, 304 were full-content sources and 48 were partial-content sources. A partial source
may contain songs or other fragments without asserting a complete order. Its safe destination is
evidence retention, not a complete canonical service. Improving the parser's ability to recognise
partial material is useful; making partial material look complete is not.

## Plan-level diagnosis

The stored parse metadata contained 686 service-plan records:

| Plan disposition | Plans |
|---|---:|
| `auto_importable` | 201 |
| `review_required` | 463 |
| `invalid_extraction` | 22 |

These counts are not a partition of the 352 sources because sources can contain multiple plans and
source-level gates can hold all plans in an entry.

The read-only classification of the stored reasons found:

- **131 review-required plans were bookkeeping-only.** Examples include an ignored line also being
  claimed as evidence, an ignored item-like line inside a plan span, or an unclassified sign-off
  line. F65 correctly leaves these reviewable but does not make them unattended-safe.
- **138 review-required plans had attempt disagreement without a content disagreement.** F65 has
  already removed optional evidence-line citations from the consensus signature. These remaining
  disagreements are differences in service/date/scope/items or item provenance and need targeted
  reconciliation rather than being silently accepted.
- **22 plans had content-invalid findings.** The observed families were out-of-order items,
  item/source-line duplication, merged lines, evening or multi-service boundary errors, and plans
  with no items. These remain genuine parser/validator concerns; F65 must not be used to relax them.

The plan-level categories are diagnostic populations, not additive source counts. The current
archive report should be extended to carry these reasons directly so the next run can produce a
source-level hold census without reconstructing JSON from the rehearsal database.

## What is and is not a parser defect

### High-confidence, high-value parser targets

#### 1. Attempt disagreement and corrective-call volume

The 506 corrective calls are the clearest operational signal. A second call is being used for most
of the corpus, and 138 plans still ended with a disagreement that did not appear to be a content
difference.

Likely contributors:

- model non-determinism around service boundaries and item grouping;
- different source-line attribution for otherwise identical items;
- remaining differences in service/date/content scope;
- corrective prompts that ask for a complete re-extraction instead of resolving the specific diff.

The next implementation should preserve the F65 consensus rule that excludes
`service_evidence_line_ids` from the signature. Reintroducing that optional metadata would recreate
the false disagreements F65 was intended to remove.

Recommended direction:

1. Record a typed diff between attempts: service, date, content scope, item count, item order,
   item type, title, and source-line IDs.
2. Canonicalise whitespace and equivalent line representations before comparing attempts, while
   retaining source order and item provenance.
3. Use a targeted corrective request that presents only the disagreement and asks the model to
   resolve it against the source lines.
4. Keep unresolved disagreements held; measure whether the targeted retry lowers review volume
   without reducing exact correctness.

#### 2. Confidence calibration

There are 307 gate-eligible held sources below 0.90, but the confidence score is not a reliable
standalone correctness measure:

| Full-plan confidence band | Exact / total | Accuracy |
|---|---:|---:|
| 0.00–0.49 | 14 / 23 | 60.9% |
| 0.50–0.74 | 99 / 147 | 67.4% |
| 0.75–0.89 | 211 / 274 | 77.0% |
| 0.90–1.00 | 129 / 164 | 78.7% |

Do not lower the 0.90 automatic-import threshold based on this run. Instead, investigate a
composite score using objective signals:

- consensus or targeted adjudication success;
- date and service agreement with the curated identity;
- valid, monotonic source-line provenance;
- item sequence and boundary checks;
- complete versus partial content scope;
- explicit unknown-service/date handling.

The model's confidence can remain an input, but it should not be the only reason a plan clears the
gate.

#### 3. Genuine content extraction failures

The 22 invalid plans are the right place for focused parser fixtures and prompt improvements. The
next session should collect representative source snippets for each family and add non-vacuous
tests for:

- two items claiming the same source line;
- an item merged across source lines;
- an item emitted out of source order;
- morning/evening or multi-service boundary confusion;
- an empty extracted plan;
- a line that is genuinely an item versus a sign-off, aside, or appendix.

The content-invalid gate should remain fail-closed. The objective is to make extraction better, not
to make these plans approvable by changing the validator.

### Important signals that should not be misclassified

#### `service_beyond_manifest`

The run flagged this on 142 sources overall and 91 of the 352 gate-eligible held sources. Many
Sunday emails contain both morning and evening orders while the manifest entry names only one
curated service. The importer deliberately permits additional service plans for full-content
sources.

This is primarily an identity/reporting-model issue, not proof that the parser hallucinated a
service. Improve per-plan reporting and identity-aware metrics before attempting to suppress this
signal. The current evening precision of 23.1% and the 77.8% aggregate auto-import precision are
both distorted by how additional services are scored, so neither should be used alone as a parser
quality target.

#### Bookkeeping-only findings

F65 moved bookkeeping complaints from `invalid_extraction` to `review_required`. That is a safety
improvement, not a claim that the parser is finished. The useful next question is whether the
parser can classify obvious sign-offs, asides, and trailing appendices more reliably while still
holding an item-like line that appears inside the service sequence.

Do not make all bookkeeping reasons auto-importable without a corpus measurement showing that the
specific rule cannot hide a dropped item.

#### Partial content

The 48 partial held sources should be evaluated against evidence retention, not full-order recall.
Their missing material is a property of the source, not necessarily a parser failure.

## Recommended implementation order

### Slice A — expose the hold reasons first — implemented 2026-08-14

Add per-plan diagnostic fields to the archive report and a source-level reason rollup:

- plan key, disposition, confidence, content scope, consensus state;
- all validation reasons plus the content-only subset;
- attempt count and typed disagreement categories;
- corroborated plan keys, imported plan keys, and held plan keys;
- source-level `hold_reason_categories` derived from the plans.

This slice should not change import behaviour. Its observable outcome is that every held source can
be assigned a reason from the report alone, including the current 27 no-flag sources.

**As shipped**, the reasons are produced by the parser as a typed `OosEmailPlanHoldReason` list and
carried through identity resolution, not reconstructed downstream. Reconstruction was tried first
and got three things wrong: it merged F65 bookkeeping holds with attempt-disagreement holds, so the
131/138 split could not be reproduced; it read a corrective API call that *threw* as a disagreement
across five fields; and it counted plan-level reasons from entries that were never held, against a
baseline counted in sources. The census now reports the two units separately
(`hold_reason_category_counts` for held sources, `held_plan_reason_counts` for plans), and
`imported_plan_keys` comes from the importer rather than being inferred from plan dispositions —
`null` when no import was attempted, which is not the same fact as an import that took nothing.

### Slice B — targeted disagreement resolution — implemented 2026-08-14 as diagnostic only; corpus proof pending

**Adjudication does not clear the import gate.** A first implementation set `consensus` on a
resolved adjudication, which would have let every 0.75–0.89 plan whose two attempts disagreed
import unattended — a threshold change in substance, taken before the run that was meant to justify
it, in a band measured at only 77.0% exact. The shipped version records `adjudicated` as a separate
flag, adopts the adjudicated order so a reviewer sees the resolution, and leaves the plan
`ReviewRequired`. `consensus` still means only what it meant: two *independent* attempts agreeing.

Whether adjudication should ever clear the gate is HIR-D6's open question, to be answered from this
slice's measurement rather than from a reading of the exception. See
`HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md` §4.4.

Add typed attempt diffs and a targeted corrective prompt. Keep the current fail-closed outcome when
the diff cannot be resolved. Measure:

- corrective-call rate;
- unresolved disagreement plans and sources;
- exact plan correctness;
- phantom source-line count;
- importable and held populations.

### Slice C — objective confidence calibration

Build a calibration table from the fresh run and the next controlled rerun. Test whether objective
signals predict exact correctness better than the model score. Keep the existing 0.75 review and
0.90 automatic thresholds until evidence supports a change.

### Slice D — content-boundary fixtures and prompt changes

Use the 22 content-invalid plans as a fixture set. Change extraction instructions or normalisation
only where a fixture demonstrates a real improvement. Preserve the strict source-line schema and
the item/item duplication, order, date, and identity gates.

### Slice E — bookkeeping and multi-service refinement

Only after the reason census is visible, decide whether any bookkeeping family can be reduced by
better line-role classification. Separately refine per-service identity reporting so an expected
second service is not counted as a false positive.

## Next-session procedure

1. Read this report and the current historic-import authorities before editing:
   - `docs/archived-plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md`
   - `docs/archived-plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md`
   - `app/Services/Email/OosEmailParserService.php`
   - `app/Services/Email/OosEmailExtractionValidator.php`
   - `app/Services/Email/OpenAiOosEmailItemExtractor.php`
   - `app/Services/Email/InboundEmailImportService.php`
   - `app/Console/Commands/ImportOosArchiveCommand.php`
2. ~~Start with Slice A, because the current report cannot explain every held source without
   database-side reconstruction.~~ Done in code; the next archive-v12 report carries the census.
3. Add or update focused PHPUnit coverage before changing parser behaviour.
4. Run the focused parser/Email tests through Sail.
5. Reprovision and certify `crockenhill_rehearsal` before any staging rerun.
6. ~~Run the complete 534-entry corpus with `--fresh-parse` against the isolated rehearsal
   database.~~ Done 2026-08-14 — see below.
7. ~~Compare the new report with the archive-v11 baseline.~~ Done 2026-08-14 — see below.
8. Do not begin production apply or mass review from a parser experiment. The historic final-readiness
   plan remains the go/no-go authority. **Still true after the v12 run** — see below.

## archive-v12 corpus measurement (2026-08-14)

### Authoritative run

| Item | Value |
|---|---|
| Report | `storage/scratch/archive-v12-restage-20260814.json` |
| Report SHA-256 | `acf6b18ac172e93d6fc667e5200d61d2a206ea808bcc4eb7f8340a295b136a55` |
| Parser | `archive-v12` (Slice A + B code, `ImportOosArchiveCommand::ParserVersion` bumped) |
| Fresh parse | Yes; `cache_policy: raw-extraction-bypassed; curation always re-applied` |
| Plan hash | `6795f1497d54d85baac353d026544445f78a151ad0c77c254cf58ce9ba016cda` (manifest unchanged since 08-12) |
| Approved / selected sources | 534 / 534 |
| Working database | `crockenhill_rehearsal`, reprovisioned and certified clean immediately before this run |

Pre-flight before this run: 117 focused tests green (`ImportOosArchiveCommandTest`,
`OosEmailParserServiceTest`, `OosArchiveEvaluatorTest`, `OpenAiOosEmailItemExtractorTest`), Pint
clean, PHPStan clean (760 files). The first launch attempt was killed mid-run by the tool harness
and, non-obviously, kept executing detached inside the container for a short time afterward; a
reprovision issued while it was still alive dropped the schema out from under its open connection.
That partial run was discarded (process killed, database reprovisioned again) before the run below,
which was launched with a container-level detach (`docker compose exec -d`) so it had no dependency
on the calling tool's lifecycle.

### Baseline comparison (archive-v11 → archive-v12)

| Metric | v11 (08-14 baseline) | v12 (this run) |
|---|---:|---:|
| Held sources (source-level) | 373 | 370 |
| Gate-eligible held sources | 352 | 350 |
| Low-confidence gate-eligible held sources | 307 | 323 |
| Source-gated (non-gate-eligible) held | 21 | 20 |
| Total extraction attempts | 1,040 | 1,142 |
| Sources needing a corrective 2nd+ attempt | 506 / 534 (94.8%) | 508 / 534 (95.1%) |
| Phantom source-line validation failures | 0 | 0 |
| Plans: bookkeeping-only (held, reviewable) | 131 | 156 |
| Plans: attempt-disagreement, no content diff | 138 | 117 |
| Plans: content-invalid | 22 | 33 |
| Date accuracy (all sources) | not reported at this grain in v11 | 98.31% (525/534) |
| Auto-import precision | 77.8% (aggregate, distorted by `service_beyond_manifest`) | 77.4% (580 candidates, 449 correct) |
| Confidence band 0.75–0.89 exactness | 77.0% | 78.75% (240 plans) |
| Staged canonical services written | 196 rows / 159 approved identities covered | 199 rows / 157 approved identities covered, 42 extras |
| Missing approved identities (of 521) | 362 | 364 |

The two runs are close enough on every headline number to say the corpus is stable under the new
parser version — this is not a regression, it's the same population measured through a report shape
that now exposes reasons instead of requiring reconstruction. The small movements (350 vs 352
gate-eligible held, 1,142 vs 1,040 attempts) are within the range expected from LLM non-determinism
across two fully independent fresh-parse runs, not a code regression — F64/F65 did not change
extraction behaviour for the majority of sources, only reclassified specific reasons.

**Slice A's stated goal is met**: every held source now carries a `hold_reason_categories` list and
every plan a `hold_reasons` list, `adjudicated`, `corroborated_plan_keys`, `imported_plan_keys`, and
`held_plan_keys` — no database reconstruction needed. `imported_plan_keys` is `[]` for every held
entry in this run (correctly distinct from `null`, which appears only where import never ran) and
non-empty exactly for `created`/`evidence_retained`/`merged` entries.

**One artefact worth flagging, not a defect**: `song_link_hit_rate` reads 0/2580 (0%). The rehearsal
database's `songs` table is empty — the schema dump provisions structure, not the song catalogue
reference data — so this measures an unseeded rehearsal database, not the parser's song-matching
behaviour. Re-seed the song catalogue before trusting this figure from a rehearsal run.

**Upgraded in the 2026-08-14 review from artefact to opportunity.** Seeding the catalogue does not
just repair a broken statistic — it converts 2,580 extracted song items into item-level correctness
checks, against a corpus that otherwise contains exactly one human-asserted item count. It is the
cheapest part of item 0 and should be done before any further corpus run.

### HIR-D6: should adjudication ever clear the gate?

This run adjudicated **86 sources** (all still `held_for_review` — confirms `consensus` was never set
by adjudication in a live run, not just in unit tests: 0 of 691 plans have both `consensus: true` and
came from an adjudicated entry, and 0 of the 117 attempt-disagreement plans have
`disposition: auto_importable`). The adopted adjudicated order is what the report's plan fields show.

Exactness of the disagreement population (`exact_correct`, computed against the corroborated/manifest
identity) that adjudication resolves:

| Population | Correct / total | Rate |
|---|---:|---:|
| All plans | 456 / 691 | 66.0% |
| Plans held for attempt disagreement | 91 / 117 | 77.8% |
| ...within adjudicated entries | 77 / 99 | 77.8% |
| ...within entries where disagreement fired but adjudication did not | 14 / 18 | 77.8% |
| Adjudicated-disagreement plans, confidence ≥ 0.75 | 52 / 64 | 81.3% |
| Adjudicated-disagreement plans, confidence ≥ 0.90 | 18 / 22 | 81.8% |

**Answer: not yet.** The decision stands, but the reasoning below was corrected in the 2026-08-14
review and the evidence is weaker than it reads. Three problems, none of which change the answer:

1. **The metric is blind to most of what adjudication does.** `exact_correct` checks date, service
   and non-emptiness only (see "Revision 2026-08-14"). Of the 165 attempt-disagreement category
   instances in this run, roughly 115 are item-level — `item_type_or_order` 71, `title` 16,
   `item_count` 14, `source_line_ids` 14 — and invisible to it. Adjudication is being scored on a
   measure that cannot see the thing it resolves.
2. **The "identical" rates are one observation, not three.** 99 + 18 = 117 and 77 + 14 = 91, so the
   third row is the arithmetic complement of the second; once 77/99 lands on 7/9 the others follow.
3. **The control arm is n=18.** "Adjudication is not measurably improving correctness" cannot be
   concluded from an 18-plan comparison group.

So the correct statement is *not measurable with the current metric*, not *measured and found
ineffective*. Holding is still right — a wrong plan imported unattended is worse than one held — and
this is exactly the "independent accuracy signal" the paragraph below asks for; item 0 supplies it,
and HIR-D6 should be revisited only once it has.

The original reasoning, retained: adjudicated and non-adjudicated disagreement plans land at the
identical 77.8% exactness, so adjudication is not improving correctness over the population baseline
in this run, only surfacing a resolution for a reviewer to look at. Restricting to confidence ≥ 0.75 lifts
exactness to 81.3%, better than the 77.0% general 0.75–0.89 band, but the sample is small (n=64) and
still well short of a bar that would justify unattended import — a wrong plan imported unattended is a
worse outcome than one held for review. HIR-D6's shipped design (adjudication resolves and is adopted
for review, `consensus` stays two-independent-attempts-only) is confirmed correct by this measurement,
and should not be revisited without a larger adjudicated sample or an independent accuracy signal
beyond `exact_correct` against the manifest identity.

### Conclusion

Do not lower the 0.90 threshold or let adjudication set `consensus` based on this run. See "Parsing
improvement plan, queued 2026-08-14" immediately below for the concrete next-session work this
measurement, and the discussion that followed it, actually converged on — it supersedes the
generic "Slice C then Slice D" ordering above with specific, scoped tasks.

## Parsing improvement plan, queued 2026-08-14

Originally four items, discussed and scoped in the session that ran the archive-v12 corpus
measurement, for a later session to implement. **Now five: a critical review on 2026-08-14 added an
item 0 and rewrote item 1** — read "Revision 2026-08-14" immediately below before starting, and
implement in the order 0, 1, 2, 3 (item 4 is a no-change record). Items 1 and 3 are blocked on
item 0.

**[HIR-D7](HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md) (§4.5) clears the governance question
for items 1–3** — they are extraction-accuracy work serving the import's own purpose, not "features
or polish," and none of them touch what the importer imports unattended, so none need a further
recorded decision before starting. Item 0 is measurement only and changes no import behaviour at
all. Read the HIR-D7 outcome (§4.5 of that plan) before assuming otherwise.

### Revision 2026-08-14 — what a critical review of this plan changed

The four items below were reviewed against the code and the v12 report JSON before any of them was
started. Items 2, 3 and 4 survived unchanged (every citation in them was checked and holds). Item 1
did not, and a new item 0 precedes all of them. The three findings that forced the change:

**The accuracy metric does not measure item content.** `OosArchiveEvaluator::planRecord()` defines
`exact_correct` as `assertsFullOrder() && $dateMatches && $serviceMatches && $plan->items !== []`.
It never inspects an item. Every figure in this report called "full-plan exactness" or "exact plan
correctness" is therefore *identity agreement* — date plus service slot plus non-emptiness. The one
field that could check content, `expected_item_count`, is null unless a human asserted a count, and
in the v12 run that is **1 plan out of 691**. This does not invalidate the metric, which is a fair
measure of identity resolution; it invalidates using it to judge extraction quality, which is what
items 1–3 were all implicitly doing.

**Item 1's non-monotonicity finding is noise.** The v12 numbers reproduce exactly, but the v11
baseline in this same report was perfectly monotonic, and the ordering flipped between two runs of
one corpus:

| Band | v11 | v12 |
|---|---:|---:|
| 0.00–0.49 | 60.9% | 45.8% |
| 0.50–0.74 | 67.4% | 72.2% |
| 0.75–0.89 | 77.0% | **78.75%** |
| 0.90–1.00 | **78.7%** | **75.45%** |

The v12 top-two-band difference is z = 0.78 (n=167 against n=240) — not significant. The bottom band
moved 15 points on n≈24. Both tables were already in this document; nobody put the four v11 bands
next to the four v12 bands. The stronger claim that the score "can't rank-order its own population"
is contradicted by its own data: 45.8 → 72.2 → 78.75 across the lower three bands is a 33-point
spread in the correct order. The score rank-orders; its top two bands are tied within noise, which
is a saturating score, not a broken one.

**Item 1's payoff was overstated ~2.3×.** `low_confidence` co-occurs on 339 of 370 held sources, but
only **149 sources are held for low confidence alone**:

```
149  low_confidence                                  <- releasable by item 1 alone
 75  attempt_disagreement + low_confidence
 66  bookkeeping + low_confidence
 22  bookkeeping + content_invalid + low_confidence
 20  attempt_disagreement
```

190 of the 339 carry an independent reason that holds them regardless. It is still the largest
single lever (40% of held sources), and items 1 and 2 together reach ~215, but 339 is not the
reachable population and should not be used to size the work.

### 0. Build item-level ground truth first — new, blocks items 1 and 3

Nothing in the original four items can be validated on what it claims to improve, because no
item-level truth set exists (1 human item count in 691 plans). It does not have to be built by hand:
three independent sources already cover most of the corpus, and cross-source agreement is a stronger
label than anything the parser can say about itself. Measured 2026-08-14:

| Era | Email ids | OpenLP | Hymn workbook | Email ids with no corroboration |
|---|---:|---|---|---:|
| 2009–2013 | 0 | — | 504 ids | — |
| 2014–2018 | 133 | — | yes | **3** |
| 2019–2022 | 193 | 57 (2021+) | — | **136** |
| 2023–2026 | 195 | yes | yes | 7 |

**375 of 521 email identities (72%) have at least one corroborating source.** The union of all three
sources is **1,594 service identities** against 521 from email alone.

The two corroborating sources differ in what they prove, so the census must record which one fired:

- **OpenLP** (`storage/scratch/openlp-curation-manifest.json`) — 427 included entries, **every one**
  carrying a curated `expected_item_count`, and the `.osz` files carry the item sequence. This is the
  only source that can validate item *order*, which matters because `item_type_or_order` is the
  largest attempt-disagreement category (71). Overlaps 242 email identities, 2021 onwards.
- **Hymn workbook** (`storage/scratch/outputs/hymn-reconciliation-2026-08-09/`, `Known Usage` sheet) —
  5,759 song occurrences across 1,306 date+service identities, each with hymn number, workbook title
  and resolved catalogue song ID. Overlaps 297 email identities. Songs only, and because the source
  is a crosstab (hymns down rows, dates across columns) it proves song *membership*, never sequence.

The 2019–2022 gap is a genuine source gap, not a generator artefact: no source workbook contains a
sheet for those years — `Hymn Database @ 31.12.2023.xlsx` runs 2004–2018 and then jumps to 2023 —
and OpenLP does not begin until 2021. 136 of the 146 uncorroborated identities fall in that window.
See the import-plan proposals for what, if anything, covers it.

Work for this item:

1. Seed the song catalogue into the rehearsal database. This alone converts `song_link_hit_rate`
   from the meaningless 0/2580 flagged below into 2,580 item-level checks: an extracted song title
   that resolves to the catalogue is evidence the title was extracted correctly.
2. Join `Known Usage` and the OpenLP manifest against the staged plans, and report per identity
   which source corroborated it and on what — membership, count, or sequence.
3. Rename `exact_correct` to `identity_correct` throughout the evaluator and report, so it stops
   reading as content correctness. Add a separate content-level measure fed by (1) and (2).
4. Add a title-hygiene check. The v12 `song_link.unmatched_titles` already exposes a systematic
   defect family that no current metric counts — titles truncated mid-word with surrounding prose
   bled in:

   ```
   "78 - as previously discussed with Ruth! Can we have a run through of the"
   "335 'there's no greater name than"
   "574 'one holy apostolic church' (can we try and pick a well known t"
   ```

   Every one of those plans can still be `exact_correct: true`.

### 1. Stop treating the model's confidence as the gate (was Slice C)

**Revised.** The original justification — non-monotonic bands — is withdrawn as noise; see the
revision note above, and do not re-derive it. The item survives on a different and weaker argument:
the score **saturates**. It separates the bottom three bands well (45.8 → 72.2 → 78.75) and then
stops discriminating at the top, which is exactly where an auto-import threshold sits. A gate placed
in the region where a score has no resolution is a poor gate even when the score is correctly
ordered elsewhere.

Two constraints on any replacement, both of which the original wording would have violated:

- **Do not put date/service agreement into the composite while the label retains it.** Those are two
  of the four conjuncts of `exact_correct`. A composite containing them, scored against it,
  approaches 100% by construction and proves nothing. Item 0(3) separates the measures; until that
  lands, this item cannot be evaluated honestly.
- **Hold out a sample.** The original Slice C said "from the fresh run *and the next controlled
  rerun*"; the queued rewrite dropped the second run, leaving the composite built and evaluated on
  one sample. Restore it, or split the corroborated population into fit and holdout sets.

Sizing: 149 sources are held for low confidence alone, 339 have it among their reasons.

### 2. Port item-type-aware review classification from the live structure pipeline

`ServiceSectionType::requiresStructuralUncertaintyReview()` already encodes which item types have a
downstream effect (`song`, `sermon`, `bible_reading`, `childrens_talk`) versus which don't
(`welcome`, `notices`, `prayer`, `other`), and `SectionReviewFlagPolicy` already uses it to demote
review flags on filler types for the live service-structure pipeline. The OoS extraction schema's
`items[].type` enum is the identical vocabulary, so this is a port, not a new design.

Scope this to **review classification only** — which held plans actually need an operator's
attention — never to the auto-import gate itself; widening what auto-imports unattended is HIR-D6/
Axis B territory and needs its own decision regardless of item type.

Before implementing: pull a per-item-type breakdown of this run's `bookkeeping` (156 plans) and
`attempt_disagreement` (117 plans) holds from the raw extraction metadata — the archive report
doesn't currently carry per-item type, only `item_count` — to find the actual ceiling on how much
review backlog this removes before investing in the policy port.

**Cheaper first cut, added in review:** the report already carries a coarse version of that signal.
`attempt_disagreement_categories` across the v12 run is `item_type_or_order` 71, `content_scope` 28,
`title` 16, `service` 15, `item_count` 14, `source_line_ids` 14, `date` 4, `plan_count` 3. That
`item_type_or_order` is the largest category is the strongest available evidence for this item, and
it costs nothing to read. It conflates type with order, so it sizes the opportunity without
resolving it — but check it before paying to reconstruct per-item types from raw metadata, and note
that item 0's OpenLP join can separate type from order properly for the 242 corroborated identities.

### 3. Sample the extraction model/reasoning-effort against sibling settings

`OosEmailParserService` runs on `gpt-5.4-nano` at `reasoning_effort: minimal`
(`config('service-tracking.email_parsing.model'/'reasoning_effort')`) — the cheapest combination in
the codebase's LLM config, explicitly commented as "lowest-stakes structured extraction." Sibling
structured-extraction tasks run meaningfully stronger: sermon analysis on `gpt-5.6-terra`/`low`,
service-structure extraction on `gpt-5.6-sol`/`medium`. Rerun a few hundred sources at a stronger
setting (env-overridable: `OOS_EMAIL_PARSING_MODEL`, `OOS_EMAIL_PARSING_REASONING_EFFORT`, no code
change needed) and compare `exact_correct` and the corrective-retry rate (95.1% of sources needed a
second call this run) against this measurement's baseline.

**This is live production code**, not archive-only — `ProcessInboundOosEmail` calls the same
classes for the current weekly email intake. Roll out via sample comparison first, not a blind
swap; a change here affects this week's real inbound emails immediately, unlike the archive
command's throwaway rehearsal-database experiments.

Two additions from review:

- **Sample against the corroborated population, not an arbitrary few hundred.** Item 0 gives 375
  identities with an external label; a model comparison run on those measures something, whereas the
  same run scored on `exact_correct` mostly measures date parsing. This item is therefore blocked on
  item 0, and becomes considerably more valuable once it lands.
- **Record the cost.** `gpt-5.4-nano` is the cheapest model in the codebase's LLM config and the
  v12 run took 1,142 calls at up to 6,000 completion tokens each. Moving to `gpt-5.6-sol`/`medium`
  is a large per-call multiple on a volume that is re-run in full whenever `ParserVersion` changes
  (`cache_key` is `[input_hash, parser_version, received_date]`, so a version bump invalidates every
  entry). Put the measured sample cost and the projected full-corpus cost in the comparison before
  proposing a default change, and state the production monthly delta separately — the weekly intake
  is a handful of emails, so the live-path cost and the archive cost are different decisions.

### 4. No change needed to manifest-disagreement handling — document why

Checked in the same session and worth recording so it isn't re-litigated: `service_beyond_manifest`
(148 sources this run) is not in `OosEmailPlanHoldReason` at all and never gates import on its own —
the importer already permits extra services beyond the manifest for full-content sources by design
(D9). The low apparent "evening precision" is a reporting artefact of comparing against the
manifest, not an import block. `date_mismatch`, by contrast, does gate (part of the `source_gate`
family) and has an empirically validated track record — 8 of 8 manifest-flagged date disagreements
in the corpus were confirmed genuine parser errors, not manifest errors, in the held-backlog review.
The manifest's `resolved_date`/`resolved_service` fields come from a deterministic, versioned rule
(`decision_rule_version: oos-curation-expanded-v2`) for 525 of 535 entries — not an LLM judgement
call — so treating a date disagreement as a signal against the *parse*, not the *manifest*, remains
the right default. No corpus proof would improve on 8/8; leave this gate as-is.

## Acceptance criteria for parser work

A parser change is ready for another corpus measurement only when:

- focused tests cover the changed extraction or validation behaviour;
- strict source-line output remains enforced;
- phantom source-line failures remain zero;
- all manifest date mismatches remain held by corroboration;
- content-invalid plans remain unable to auto-import;
- bookkeeping-only plans remain reviewable and do not become silently accepted;
- partial sources remain evidence rather than complete orders;
- attempt disagreements remain held whether or not adjudication resolved them, and `consensus`
  continues to mean two independent agreeing attempts;
- the report records per-plan and source-level hold reasons;
- the new run reports model-call volume, confidence calibration, held population, staged identities,
  and the same hashes needed to reproduce the comparison;
- **no accuracy claim about extraction rests on `exact_correct`/`identity_correct` alone** — that
  measure checks date, service and non-emptiness, never item content, and any statement about
  extraction quality must cite an item-level measure from item 0;
- **the rehearsal database is song-seeded** before `song_link_hit_rate` is quoted as evidence;
- **band-level calibration movements are checked against the prior run before being called a
  finding** — the v11→v12 ordering flip was noise and was briefly written up as a result.

## Copy/paste brief for the next session

> Continue the historic Email import parser follow-up from
> `docs/reports/historic-import-f64-f65-parser-follow-up-2026-08-14.md`. Slices A and B are done and
> corpus-proven (archive-v12); HIR-D7 is decided and clears the governance question for
> extraction-accuracy work. Read "Revision 2026-08-14" first — it withdraws the original item 1
> justification and resizes it, so do not re-derive the non-monotonicity argument. Implement the
> queued plan in order: **(0) build item-level ground truth** — seed the song catalogue into
> rehearsal (turns `song_link_hit_rate` 0/2580 into 2,580 real checks), join the OpenLP manifest and
> the hymn workbook's `Known Usage` sheet against staged plans to label the 375 of 521 corroborated
> email identities, rename `exact_correct` to `identity_correct` because it only checks
> date/service/non-emptiness, and add a title-hygiene check for the truncated-title family visible in
> `song_link.unmatched_titles`. Items 1 and 3 are blocked on this and cannot be honestly evaluated
> without it. (1) Stop using the model confidence as the gate — justified by *saturation* at the top
> band, not by non-monotonicity; keep date/service agreement **out** of any composite while the label
> still contains it, and hold out a sample; sizing is 149 sources held for low confidence alone, not
> 339. (2) Port
> `ServiceSectionType::requiresStructuralUncertaintyReview()`/`SectionReviewFlagPolicy`'s item-type
> classification into OoS review-flagging only, never the auto-import gate — read
> `attempt_disagreement_categories` (`item_type_or_order` is the top category at 71) before paying to
> reconstruct per-item types. (3) Sample `gpt-5.4-nano`/`minimal` against a stronger model/effort
> setting, scored on item 0's corroborated population and reported with measured cost — this is live
> production code (`ProcessInboundOosEmail` too), so sample first, don't swap blindly. Item 4
> (manifest-disagreement handling) needs no change — it's already correct, documented so it isn't
> re-litigated. Preserve F64's strict source-line schema, F65's bookkeeping/content distinction,
> zero phantom source-line failures, and — regardless of any of the above — that nothing changes what
> the importer imports unattended without its own recorded decision (HIR-D6/Axis B, unchanged).
