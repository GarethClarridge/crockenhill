# Historic Import: Incremental Convergence Plan

> **Status (2026-08-14): plan of record for the whole historic-import programme.** This plan
> supersedes the executable content of the three prior authorities, now archived:
> [final import readiness](../archived-plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md)
> (findings F1, F29–F66 and decisions FR-D1–FR-D10),
> [readiness remediation](../archived-plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md)
> (blockers B1–B21, WP0–WP10, gates G0–G9) and
> [safety remediation](../archived-plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md)
> (HIR0–HIR8, HIR-D1–HIR-D8). Their findings and decisions remain the evidence record; their
> *sequences and gates* are replaced by §3–§7 here. The change of direction is the reviewed
> maintainer decision set REV-D1–REV-D4
> (`docs/reports/historic-import-plan-review-2026-08-14.md`), restated authoritatively in §2.
>
> **Production mutation is permitted only as a §7 round**: approved manifests, pre-round backup,
> incremental apply, audit report — and never publishes anything; release is always a separate
> signed act (§8). The one-shot windowed operation, its ingress freeze and its G-gate ladder no
> longer exist.
>
> **Execution status verified 2026-08-25.** Start with §0. The long IC1–IC3 narrative in §6 is an
> evidence journal, not an executable backlog. Do not restart closed parser/model work from it.
>
> **Email/OpenLP corpus settlement is complete.** The final expectation is **553 approved sources /
> 539 identities / 20 operator-approved accepted holds**. Both lanes reconcile with zero unstaged
> approved sources, zero unstaged identities and zero unexplained identities. The remaining red
> proposal census is a cross-source convergence result, not an Email/OpenLP corpus defect and not a
> reason to delay the video source that can supply its missing evidence.
>
> **Cannot proceed without a human:**
> video worksheet exceptions and manifest freeze; recovery of authoritative source material for any
> current-era services still reported as unevidenced; every release batch; and the §8.4
> publication-policy decision.

## 0. Current execution brief — use this for new work

### 0.1 Binding execution sequence — 2026-08-25

This is the complete remaining sequence. A cheaper agent should work one numbered slice at a time,
run the named acceptance check, update this table, and stop. Do not infer work from the older
2026-08-24 execution snapshot or a closed investigation later in this document.

| Order | Slice | Current state | Done when |
|---|---|---|---|
| 1 | **Email + OpenLP corpus settlement** | **DONE 2026-08-24.** Final expectation: 553 approved sources / 539 identities / 20 operator-approved holds. Both lanes reconcile: 0 unstaged approved sources, 0 unstaged identities and 0 unexplained identities. Date accuracy is 100.0%; automatic identity precision is 99.7% against the 0.98 floor. The census contains 398 proposals in 21 classes: 223 services lack any corroborating source and 135 currently disagree with one. `source_item_projection_stale` is expected while those proposals remain. | Closed. This is the REV-D4 trigger for IC5. Do not mark an evidence-dependent class `automated` or `irreducible` merely to make the final convergence gate green. |
| 2 | **Close the production-round authority gaps** | Open. Portable Email apply, OpenLP apply and video dispatch do not all bind the approved operation/manifest/plan. Video dispatch also lacks the promised same-manifest resume behaviour. | Focused tests prove every mutating lane binds its exact authority; a stopped full-manifest video run resumes its own completed work; historic notification containment reaches nested jobs. No new audit framework. |
| 3 | **Carry historic-video corroboration authority end to end** | Open and mandatory before any video calibration. The manifest grades each recording, but the grade is not carried into the Livestream source revision; the projector therefore currently trusts every Livestream as if it captured the full service. | The hash-bound manifest grade reaches processing metadata and the Livestream source fingerprint. Only `full` historic recordings may corroborate song membership, count and order. `short_partial`, `fragmented`, `unknown`, and a missing grade on a historic source are neutral and fail closed. Focused importer/adapter/projector tests cover full and non-full recordings. |
| 4 | **Curate and calibrate video** | REV-D4 trigger reached. Mounted corpus: 1.0 TB, 649 files, 462 service identities. No worksheet or frozen manifest. | Draft one worksheet, review exceptions rather than 462 ordinary includes, freeze one full-corpus manifest, then run a representative calibration through the real livestream pipeline after slices 2–3 pass. |
| 5 | **Run definitive historic video processing and re-census** | Blocked by slices 2–4 and operator manifest freeze. | Dispatch the same frozen manifest in bounded resumable passes. Then regenerate membership and the proposal census. The 223-service awaiting-evidence population must either finalise on a `full` recording or remain explicitly unresolved; re-evaluate the 135 prior disagreements with the new evidence before calling any residue irreducible. The final convergence gate remains red until every surviving class has a truthful terminal disposition. |
| 6 | **Current-era source back-fill** | Operator-dependent and based on a 2026-08-09 production count. | Re-run `audit:service-evidence-coverage`; recover each real source still missing, ingest it through the ordinary revision path and reproject. Never manufacture evidence. |
| 7 | **Hymn convergence** | Import controls exist; the apply artifact is stale relative to the final converged corpus. | Regenerate against the exact converged corpus, review exceptions, bind the artifact to its operation and apply once. Hymn evidence proves song membership only, never count or order. |
| 8 | **Production rounds, release and retirement** | No lane is authorised by this plan alone. | Run RG-B per lane and RG-C per era; release remains separately signed. Retire one-shot code only at historic closeout (IC8). |

### 0.2 Superseded 2026-08-24 execution snapshot

The table below is retained as the evidence journal for the Email/OpenLP settlement. Its slice
states and next actions are superseded by §0.1. In particular, do not try to mark all 21 proposal
classes before video, and do not interpret the red final convergence gate as reversing REV-D4.

| Order | Slice | Current state | Done when |
|---|---|---|---|
| 1 | **Settle Email + OpenLP RG-A** | Portable-subset apply behaviour proved **DONE 2026-08-24** (`cfe190069`). Accepted-holds draft reviewed and approved 2026-08-24 (all 18 as written). **First combined clean-rehearsal RG-A run DONE 2026-08-24** (`2e29d50d1`): a genuinely empty `crockenhill_rehearsal` DB (the `catalogued_v7` base tried first had off-band revisions from earlier repair work and wasn't clean), 554/554 Email + 614/614 OpenLP staged with zero import failures, `services:generate-corpus-membership` + `services:proposal-census --expectation=` (both lanes, `HISTORIC_CORPUS_CENSUS_SOURCE_KINDS=email,openlp`) reconciled. This surfaced and fixed two real defects: (1) the live `--import` path never threaded the manifest's verbatim sha256 into `EmailSourceAdapter::adapt()`, so `approved_source_input_hash_mismatch` fired for 531/554 sources (96%) — the bundle-staging path had it right, so this was invisible until a live import was reconciled this way for the first time; fixed by passing `sourceInputHash: $entry->inputHash` at the call site. (2) `OosArchiveEvaluator::holdReasonCategories()` gated on the entry's overall disposition, so a dual-service entry with one plan held and one imported reported an empty `hold_reason_categories` despite `held_plan_keys` correctly naming the held plan; fixed to read reasons from the genuinely-held plans. Re-run after both fixes: `input_hash_mismatches` 531 → 0 on both lanes, `census_source_kinds` correctly declared, OpenLP lane fully clean (zero blockers). **5 Email sources remain genuinely unstaged and are now named exactly**: `2015-06-14`, `2016-05-01`, `2020-01-05` (newly visible — each silently dropped its morning plan under `no_items`/`low_confidence`/`missing_identity`, previously masked by finding 2 above) plus `2015-12-20`, `2023-01-01` (already known from item 14, cleared-but-unstaged). `source_item_projection_stale` also shows on the membership (routine — this rehearsal hasn't run reprojection yet, not a defect). **The 5 named sources were RULED on 2026-08-24 (§10 journal)**: 3 of 5 (`2015-06-14`, `2016-05-01`, `2020-01-05`) turned out already `eligible` — the finding note predated later fixes; `2023-01-01`'s date miss was root-caused and **fixed in code** (year-boundary bug in `contextYear()`), not held; only `2016-05-01-pm` (a second, separate email for the same date, correctly zero-item) is a genuine accept-as-hold, folded into the draft. **2026-08-24 follow-up**: `2024-10-06`'s date miss was also root-caused and fixed (resolver `tomorrow`-precedence bug) and came back *off* the draft, taking it 20 → 19; a fresh `--evaluate --cache-only` on the 19-hold expectation confirmed the repointed `low_confidence` rule reads 107 low-confidence / 599 clean of 710 dated plans, matching the predicted ~105/~601 — but that same re-check found `2016-05-01-pm` was mischaracterised: its plan extracts one spurious item (an entire announcement paragraph misclassified as a `song` title), not the zero items its accepted-hold reasoning claimed, and under the corrected `low_confidence` rule it now carries **no** hold reason at all, grading straight through to disposition `eligible` — it would stage that garbage item on the next real `--import`. A corpus-wide scan for the same shape (long/multi-sentence item titles) found no safe general rule — it flags 264 of 6,908 items (3.8%), nearly all legitimate hymn entries with parenthetical tune notes — so rather than build a heuristic for one confirmed case, this one source was moved to `disposition: exclude` in the curation manifest (reason recorded there) and dropped from the accepted-holds draft, 19 → **18**. Re-run: **553 approved sources / 539 identities / 18 accepted holds**, 709 dated plans, and the source no longer appears at all. **Second combined clean-rehearsal RG-A DONE 2026-08-24** on a freshly provisioned `crockenhill_rehearsal_rga_20260824d`, at **zero model spend** (`--cache-only` throughout; the whole slice cost 5 live calls, all of them targeted `--fresh-parse` probes on named sources). **Both lanes now fully reconcile: 0 unstaged approved sources and 0 unstaged identities on each, 0 unexplained beyond-manifest on each.** Email 553/553 selected — 445 created / 76 evidence-retained / 17 held / 15 merged, **date accuracy 100.0%**, auto-import identity precision **99.7%** (RG-A floor 0.98); OpenLP 614/614 processed, 0 failures. Getting there disproved this table's own earlier ruling: the 5 named unstaged Email sources were **not** "already eligible" (that check ran against local dev, not the DB of record). Three were **stale-cache defects invisible to `--cache-only`** and cleared by a single-item `--fresh-parse`: `2023-01-01` (cached date `2022-01-01`, despite the `tomorrow`/year-boundary fixes already being committed), `2015-12-20` (cached parse emitted two `morning:2015-12-20` plans, collapsing Christmas morning and 27 Dec onto the entry date; the duplicate invalidated **all four** plans including a 9-carol evening carol service — the fresh parse splits them correctly to `other:2015-12-25` and `morning:2015-12-27`), and `2020-01-05` (empty morning). `2015-06-14` **regressed** on a fresh parse (both plans empty, losing its evening sermon item) and its row was restored from `…rga_20260824` and re-verified with `--cache-only`. The remaining two — `2015-06-14` and `2016-05-01`, both morning, both reproducing across cached and fresh draws — are genuine sermon-outline-only holds, **operator-approved 2026-08-24**, taking the accepted-holds file 18 → **20** (`storage/scratch/oos-accepted-holds-20260824-draft.json`); the expectation regenerates to **553 approved sources / 539 identities / 20 holds** (`oos-corpus-expectation-20260824e.json`). A corpus-wide symptom scan for the same stale-cache shapes found **exactly one instance of each** — both already fixed above — so no further stale-cache damage is visible; see the cache caveat in §10. Two gate blockers remain and neither is a corpus defect: (a) `source_item_projection_stale` on the item-level membership — `service-tracking:reproject-current-era` reports **0 creates and 0 safe enrichments** (465 services differ, 398 `conflict`, 67 `blocked_difference`), so it cannot clear it here; (b) **21 proposal classes covering 398 proposals**, none yet marked automated or irreducible. **An earlier reading of this figure was wrong and is retracted.** It reported 204/207 classes and concluded that ~63% of the review load was two content shapes — liturgical framing (`custom:welcome and any notices` ×100 and five other spellings) and OpenLP projection assets — recommending rules to exempt both from corroboration. That analysis was an artefact of class *labelling*, not of content. `ChurchServiceProjector::semanticType()` already excludes `custom`, `images`, `presentations` and `media` from the song dimensions, so those items were never being counted as songs and no exemption rule was needed or possible. The real cause: **every conflict in the census is on `song_membership`, `song_count` or `song_order`** — whole-service dimensions that name no item and therefore carry neither `canonical_identity` nor `assertion_key` — and `ChurchServiceProposalCensus::subject()` fell through to the *first proposed item*, labelling each class with whatever happened to lead that service's order. The welcome line usually leads, so 100 unrelated services were filed under one welcome spelling and one shape split across six. **Fixed**: `subject()` now derives the subject from the conflict's dimension (names sorted, so ordering cannot split a class) before the item fallback, which still applies where a conflict names neither. Same corpus, same 398 proposals, **204 classes → 21**, singletons 179 → 6. Three classes now carry 358 of 398: **223** services at tier 5 `uncorroborated_content_dimension` on `song_count+song_membership+song_order` (no corroborating source exists at all), **76** at tier 1 `corroboration_mismatch` on the same three, and **59** at tier 1 on `song_membership+song_order` (a source corroborated and disagreed). `occurrence_count` equals `service_count` on all 21, confirming one proposal per service rather than repeated judgements about one item. The tail is 18 small classes, four of which legitimately name an item because their conflicts do carry an identity. Covered by `ChurchServiceProposalCensusSubjectTest` (5 tests). Two decisions remain and they are now real ones: what to do about the 223 uncorroborated services and the 135 disagreements. Separately, slides cannot corroborate children's-talk or sermon *position* today — there is no such dimension; `section_order` exists in `ChurchServiceProjector` and `HistoricItemGroundTruth` but is not wired into the census. Wiring it in is new capability, not slice-1 cleanup, and is the point at which a positional rule (pre-service screen content sits before the first song; order-of-service items after) becomes worth building — asset notices sit at average position ~2.0 with 84% in positions 1-2, against the `custom` notices item at average 7.8. Remaining before this slice closes: mark the 21 classes with a status and reason. | A clean rehearsal stages the current Email portable-valid subset and all 614 OpenLP includes; every invalid/held Email entry is named; Email and OpenLP expectations plus one combined membership artifact reconcile; the census has no corpus blocker. Review only high-volume proposal classes with a plausible shared fix. Enumerated residual proposals do **not** block video merely because no class-review row was created for every unique title. |
| 2 | **Close the thin production-round gaps** | Not complete; IC2 was overstated. Canonical Email import and convergence bind their guard to corpus/plan hashes, but portable Email apply, OpenLP apply and video dispatch do not all do so. Video dispatch also fails to attach the immutable operation required by notification suppression, and its documented checkpoint/resume behaviour does not exist. | Focused tests prove each mutating lane binds the approved operation/manifest/plan; a historic video reaches completion with notifications suppressed and nested jobs owned; a stopped full-manifest video run resumes without treating its own exact completed runs as corpus skips. No new audit framework is required. |
| 3 | **Curate and calibrate video** | The mounted `/Volumes/Sonnics/Services` corpus is 1.0 TB, 649 recording files, 462 service identities (347 morning, 115 evening). No worksheet or frozen manifest exists. Duration recovery, including headerless WebM packet counting, is already implemented. | Draft one worksheet, review exceptions rather than 462 ordinary includes, freeze one full-corpus manifest, then run a small representative calibration through the real livestream pipeline. |
| 4 | **Run definitive historic video processing** | Blocked by slices 1–3 and REV-D4. | The same frozen full manifest is dispatched in bounded resumable passes through `UnifiedMediaProcessor` as `livestream`; completed work is reused by exact manifest job key; per-era Bundle A exports select completed IDs from that one staging context. |
| 5 | **Current-era source back-fill** | Operator-dependent and based on a 2026-08-09 production count. | Re-run `audit:service-evidence-coverage`; for each still-unevidenced service recover a real source, ingest it through the ordinary source-revision path, then reproject. Never manufacture evidence to preserve the old count of three. |
| 6 | **Hymn convergence** | Import controls exist; the apply artifact is stale relative to the final converged corpus. | Regenerate the reconciliation from the August workbook against the exact converged corpus, review its reported exceptions, bind the resulting artifact to its operation, and apply it once. |
| 7 | **Production rounds and release** | No lane is authorised by this plan alone. | Per lane: verified backup, hash-bound approval, apply, evidence pack, review. Release remains a separate signed era batch after §8 checks. |
| 8 | **Retire one-shot code** | Waits for historic closeout. | IC8. |

### 0.3 Design rule: bulk is only orchestration

Historic-only code may select approved inputs, verify hashes, concatenate declared segments, bind
private staging/operation context, pace dispatch and transport results. It must not reimplement
segmentation, transcription, structure detection, song matching, sermon extraction, analysis,
thumbnailing or review. `HistoricVideoImporter` already calls `UnifiedMediaProcessor::process()`
with type `livestream`, which reaches the same `ProcessingPipelineBuilder::buildLivestreamChainJobs()`
used by the weekly upload. Preserve that seam; the corpus is meant to exercise it.

Do not add Horizon, a second batch framework, a new historic processing pipeline, or a general
audit subsystem for this one-off. Prefer a small command fix and focused regression test. The
round evidence pack may be a reviewed collection of existing JSON reports with their hashes.

## 1. Goal and value

Backfill the website from the historic content — emailed orders of service, OpenLP archives,
livestream/sermon recordings, and the hymn workbook — so that production reaches the durable state
each service would have if its sources had arrived through the current application at the time.
Visitors gain a browsable public service history linking sermons, children's talks, songs and
scripture back across the archive; song usage history extends to 2004; historic sermons gain the
current pipeline's transcripts, sections and media.

Two standing requirements shape everything here:

1. **Minimal manual review.** Residual human work is a designed quantity: cross-source
   corroboration and automatic finalisation do the clearing; the operator handles genuine
   ambiguity, era release decisions and spot-checks. Repeated refinement rounds are expected and
   cheap; per-item human adjudication at corpus scale is a design failure.
2. **Bulk is the weekly path.** Historic processing runs the same code as weekly processing —
   `UnifiedMediaProcessor`, `OosEmailParserService`/`InboundEmailImportService`,
   `ImportChurchServiceFromOpenLp`, `ChurchServiceProjector`, the proposal/review inbox. Every
   parser, projector or review improvement made for the import must land on the shared path, and
   single-use machinery is kept to the promotion/transport layer and retired at closeout (§11).

## 2. Decision record

### 2.1 REV-D1 — incremental convergence replaces the one-shot operation

Production apply is a repeatable, per-service, idempotent convergence round behind the
quarantine/release seam — not a windowed atomic operation. Rounds run as lanes mature; a defective
round is fixed forward per service (or superseded) rather than rolled back wholesale; completeness
is a report driven to 100% across rounds, not a precondition. Retired with the operation model, as
*gates*: the ingress freeze, deploy/config freeze, window budgets and split thresholds, the exact
whole-operation closeout, the provably no-op second import, timed restore/RPO/RTO drills, and
forced-crash recovery proofs. Retained: everything in §3.2.

### 2.2 REV-D2 — held email extractions import as flagged evidence

The unattended boundary has three tiers:

- **Existence widens.** A parsed plan whose *identity* (date + service slot) is corroborated by the
  approved curation manifest imports as source evidence regardless of content confidence, flagged
  for review instead of being held outside the service graph. Identity failures (the
  manifest-corroboration gate, which caught 8 of 8 date errors with zero false passes — do not
  weaken it) and known-bad extractions (`InvalidExtraction` for *content* reasons) still hold.
- **Unattended finalisation does not widen.** Automatic finalisation still requires the existing
  bar — confidence ≥ 0.90 with two-attempt consensus — or cross-source agreement established by
  convergence. Widening beyond that waits on the item-level ground truth (IC3) exactly as HIR-D8
  requires. Adjudication still never sets `consensus` (HIR-D6).
- **Unattended publication stays zero.** Release is a signed operator act; a service carrying
  unreviewed, unfinalised email evidence is not release-eligible.

This is the recorded maintainer decision that HIR-D6/HIR-D7's "Axis B" (never change what imports
unattended without one) requires, taken 2026-08-14 at evidence level only.

### 2.3 REV-D3 — staging rehearsal and backups replace the infrastructure rehearsal

Gating evidence per lane is: a clean staging rehearsal on `crockenhill_rehearsal` with its audit
report; a verified pre-round production database backup; the landed create-only object path; and
the post-round audit report. The archived HIR8 §14 rehearsal steps 1, 2, 4 and 6–11 (production-
shaped deploy, acquisition host custody v2, freeze-window webhook, disposable restore targets,
interleaved release attempts, timed rollback repetition) stop gating. All landed HIR0–HIR7 code is
kept and in service.

### 2.4 REV-D4 — video is sequenced after the email lane settles

Maintainer's call, against the review's recommendation to parallelise; do not re-propose
parallelism. The email lane **settles** when: (a) IC1 is implemented and a staging round shows
holds reduced to the identity/content-invalid residue; (b) the genuinely-manual adjudications
measured by the first semantic RG-A are done; and (c) IC3's ground truth exists. That state triggers
IC5. The earlier "~14" figure is a superseded legacy-v12 estimate, not a release condition.

**Operational reading amended 2026-08-25:** IC1 and IC3 are done, the current Email/OpenLP manifests
reconcile on a clean rehearsal, all 20 accepted holds are operator-approved, and every remaining
invalid/proposal is enumerated. The REV-D4 trigger is therefore **met**. It is a source-lane
settlement trigger, not the final §9 convergence gate: requiring that gate to pass before IC5 would
make video wait for the video evidence needed to clear 223 uncorroborated services. The gate remains
red truthfully until that evidence arrives. Do not force evidence-dependent classes into the
terminal `automated` or `irreducible` statuses; inspect and automate only repeated classes with a
credible shared weekly-path improvement, then re-census after IC5.

### 2.5 HIR-D8 — cross-source corroboration may finalise unattended

**Decided 2026-08-19 by the maintainer: yes.** Independent cross-source corroboration may replace
the 0.90 model-confidence threshold for unattended finalisation, but only for the dimension the
source actually proves. A hymn-workbook agreement proves actual song usage, never count or sequence;
an OpenLP agreement proves planned song membership, count and sequence. Source absence is neutral,
not disagreement, and a cross-source mismatch routes the affected dimension to review rather than
finalising it. The
identity/date manifest-corroboration gate remains unchanged, adjudication still never manufactures
`consensus`, and unattended publication remains impossible. For uncorroborated dimensions, the
existing confidence-plus-consensus path remains until separately changed on evidence.

**Implementation status 2026-08-21, superseded in part below.** IC3 records whether Email evidence
cleared the existing confidence-and-consensus route and the shared projector fail-closes
evidence-tier Email plans by dimension. OpenLP corroborates planned song membership, count and
order; missing evidence stays in review; and a mismatch creates an explicit review conflict. The
existing confidence-and-consensus route is unchanged. Hymn-workbook evidence proves actual song
membership only when IC6 binds that source lane. The later amendments below govern Livestream.

**Amended 2026-08-24 by the maintainer: a complete livestream corroborates all three song
dimensions.** The implementation above restricted Livestream to `song_order` alone. No rationale
for that restriction was ever recorded — not in this plan, where the sentence above simply asserts
it, and not in `ChurchServiceProjector::sourceProvesDimension()`, which carried no docblock. The
restriction is unsound on the code's own arithmetic: `songDimensionValue()` builds one list of song
keys in `source_position` order and returns three views of it — `song_order` *is* the list,
`song_membership` is that list deduplicated and sorted, `song_count` is its length. Membership and
count are pure functions of order, so a source trusted to prove the sequence has by construction
proved the set and its size. Granting authority over the value while withholding it from the
derivations cannot be expressed coherently.

The maintainer's reasoning also inverts the earlier intuition about which source closes the set: a
complete livestream is the record of what was *actually* sung, whereas OpenLP is a *plan*, and a
planned song may be dropped on the day. For actual membership the complete recording is the stronger
witness. The intended contract is that a complete Livestream proves every dimension; the current
implementation is temporarily broader than that contract, as qualified below. The docblock records the reasoning and
`ChurchServiceProjectorTest` pins both the finalisation and the mismatch shape (a song planned but
not sung now raises a mismatch on all three dimensions, not just order).

**Safety qualification discovered 2026-08-25; implementation required before IC5 calibration.**
The sentence above means a recording that captures the complete service. The historic manifest
already records `HistoricVideoCorroborationGrade`, whose `full` value alone may corroborate song
membership. That grade currently stops at the manifest: `HistoricVideoImporter` does not carry it
into processing metadata, `LivestreamSourceAdapter` does not bind it into the source revision, and
`sourceProvesDimension()` consequently trusts every Livestream record. That would let a sermon-only
historic clip with no song assertions disagree with an Email plan on all three dimensions. Slice 3
in §0.1 and IC5 require the grade to reach the source fingerprint and require historic
`short_partial`, `fragmented`, `unknown`, or missing grades to remain neutral. Ordinary weekly
livestream behaviour is unchanged by this historic qualification.

**Decided 2026-08-25 — retain three separate verdicts.** Because all three derive from one list, a
`song_order` mismatch and a `song_membership` mismatch on the same service are usually a single
disagreement described by several facets. The 2026-08-24 census shows exactly that shape: `song_order`
245, `song_membership` 235, `song_count` 180 across 398 proposals, with
`dimension:song_count+song_membership+song_order` the dominant class. `song_count` in particular
adds little as an independent check — it is `count($songs)` before deduplication, so it differs from
`count(song_membership)` only where a service repeats a song, and it agrees more often than the
others precisely because it is the weakest signal. The 76 all-three classes versus 59
membership-plus-order classes show that these facets distinguish changed membership/order with the
same count from additions or omissions. More importantly, `occurrence_count == service_count` on all
21 census classes proves there is already one proposal and one human decision per service, not three.
`ChurchServiceProposalCensus::subject()` now groups the facets into one dimension-set subject. Keep
the three verdicts for diagnosis; report unique proposals/services as the primary review-load number,
and treat per-dimension totals as overlapping, non-additive diagnostics. No projector contract change
is required before IC5 on this account.


**Recovered corpus measurement 2026-08-21.** The deleted semantic evaluation was recovered from
the local MySQL binlog and replayed from all 554 exact semantic-cache entries with zero fresh model
calls. The corrected clean import created 438 entries, retained 75 as evidence, held 41 for review
and failed none. OpenLP then staged all 427 approved archives without failure. Of 627 staged Email
service identities, 298 have OpenLP evidence: 29 automatically converged and 269 produced pending
cross-source mismatches (song membership 269, order 269, count 55). Of the 329 without OpenLP, 262
produced uncorroborated-dimension proposals and 67 currently have no pending proposal.

This established the first-pass residue, but it was **diagnostic evidence, not an accepted
HIR-D8/RG-A certificate**: the rehearsal had no seeded song catalogue (the import report therefore
resolved 0 of 2,639 song items) and every pending proposal also carried
`incomplete_projection_audit`. It is superseded by the catalogued replay below. Its "29
automatically converged" figure in particular is **withdrawn** — see the corrected reading.

**Catalogued replay 2026-08-21: HIR-D8 finalised nothing, and the 29 were misread.** The recovered
evidence was replayed on a certified-clean rehearsal carrying the song catalogue, again with zero
model calls (IC1). With the catalogue present, song-title resolution moves from 0 to 2,378 of 2,639
items, but **every projection figure is unchanged**: 756 services, 627 Email identities, 427 OpenLP,
298 overlaps, 531 pending proposals, 158 finalised. Dimension outcomes are `corroboration_mismatch`
on song membership 269, song order 269 and song count 55, plus `uncorroborated_content_dimension`
across all three dimensions for 263 services.

All 650 staged Email revisions carry `unattended_content_finalization: false`, and all 29 services
previously described as "automatically converged" carry `payload_complete = 0`. They are *partial*
Email evidence, which {@see InboundEmailImportService::retainPlanEvidence()} deliberately keeps out
of the projection; they finalised on **OpenLP alone**. So cross-source corroboration finalised
**zero** services on this corpus, and the earlier 29 must not be cited as evidence that the HIR-D8
path works.

The cause is measured, not inferred. `ChurchServiceProjector::songDimensionValue()` compares
`song_canonical_key ?? normalized_title`, but neither `EmailSourceAdapter` nor `OpenLpSourceAdapter`
resolves songs against the catalogue, and `ChurchServiceAssertionNormalizer` only carries a
pre-declared key or a supplied `song_id` — so every historic assertion has a null key and the
comparison degrades to raw title equality between two independently authored sources. Measured over
the 298 overlaps: raw comparison agrees on song membership 0/298 and order 0/298; resolving both
sides through the catalogue would agree on 162/298 and 154/298. Count is 238/298 either way, since
it does not depend on titles. Of the song assertions, 104 of 1,315 Email titles fail to resolve
against 4 of 1,346 OpenLP titles — the Email side is the noisy one.

**Decided 2026-08-21 by the maintainer: resolve song identity in
`ChurchServiceAssertionNormalizer` (the evidence-level placement).** The reasoning and the
measurement that produced it are recorded below; invariant 4 is amended accordingly in §3.2. The
alternative — resolving at comparison time in `ChurchServiceProjector::songDimensionValue()` — was
considered and **rejected**, for the reason in "why comparison time is the wrong placement" below.
Until it is implemented (IC3), HIR-D8's corroboration path cannot finalise a service on this corpus
and its acceptance criteria cannot be met.

**Framing corrected 2026-08-21 against the code; the decision stays open.** The earlier reading —
that both placements change a hash-bound portable contract, and that the normalizer placement
offends invariants 3 and 10 — is wrong on both counts, and the two options are not symmetrical.

*The comparison-time placement does not touch any hash.* `project()` computes the projection hash
from `portableHashItems($items)` and `$serviceContent` only; `$conflicts` is assembled afterwards
and is not an input. `songDimensionValue()` has exactly two callers, both inside
`contentCorroborationConflicts()`, so resolving titles there changes the conflict set and nothing
else. `proposed_hash` is byte-identical before and after. What it does change is finalisation,
because {@see IngestChurchServiceSourceRevision} finalises only when the hash agrees *and* the
conflict set is empty — which is the whole 0/298 → 162/298 membership and 0/298 → 154/298 order
movement. The dependence should be declared by bumping `policyVersion`, which
{@see ChurchServiceConvergenceBundleImporter} already enforces on both import paths.

*The normalizer placement does change the hash* — via `strongIdentity()`, which turns a filled
`song_canonical_key` into the grouping key and therefore into `canonical_identity`, which
`portableHashItems()` retains. It also regroups assertions into items.

*The invariants cited were the wrong ones.* Invariant 3 binds parsing to an immutable byte
snapshot and invariant 10 governs source-key identity; neither concerns song identity. The
invariant actually engaged is **4** (source evidence is immutable and single-origin). Nor is
catalogue-dependent evidence forbidden in general: {@see CurrentEraChurchServiceReprojection}
already carries `song_canonical_key` on projected items. The distinction is provenance, not
presence — a current-era key records a link someone actually made at the time, whereas a historic
key would record a fuzzy match inferred now against a catalogue assembled later.

Three consequences follow if the inference is stored as evidence: stored assertions become
time-varying under catalogue edits though the source documents do not change (invariant 4);
already-approved proposals and exported bundles change hash and are then refused on import, so a
routine catalogue tidy-up becomes an invalidation event; and — weightiest — the catalogue was
itself built from these sources (IC3), so normalising both sides through it makes part of any
Email/OpenLP agreement an artifact of shared normalisation rather than two independent authors
recording the same service, which is precisely what HIR-D8 corroboration claims to prove. That
third consequence is contained but not eliminated at comparison time: routing can be re-derived,
the dependence is announced by the policy version, and no stored row or hash carries the inference
forward. **That containment argument is superseded by the measurement below** — it weighed the
placements' side effects without establishing that comparison-time resolution repairs the defect,
which it does not.

**The measurement that decided it 2026-08-21.** The framing correction above established that the
placements differ in what they touch. Inspecting the catalogued rehearsal established that they
differ in what they *fix*, and that the comparison-time placement fixes nothing.

Because every historic assertion has a null `song_canonical_key`, `matchPair()`'s **tier-1
`song_identity` match never fires anywhere in the historic corpus**. Song matching therefore runs
entirely on the tier-3 anchored-title and tier-4 anchored-position fallbacks, which were designed
as a last resort and have become the only resort. The consequence is a defect in the projection
itself, not merely in review routing:

- Across the 269 dual-source (Email + OpenLP) pending proposals, **264 carry more song items than
  either source lists**, because each song is projected twice — once per source. Only 5 merge
  cleanly. The corpus carries **1,024 surplus song items**.
- The fallbacks also mis-pair. In service 297 (morning, 2021-10-24) both sources list the same six
  songs in the same order, but the projected item filed under canonical identity
  `songs:nip 'come people of the risen king'` carries the title *"The Best Book To Read"* — Email's
  first song merged with OpenLP's third by position. The mis-pairing raises no conflict of its own.

The Email side's titles carry the projectionist's shorthand (`NIP`, quotation marks, `Praise! 873`,
parenthetical performance notes) while OpenLP carries the archive file's own title, so string
equality reports six-of-six disagreement on a service a reader would call identical.

**Why comparison time is the wrong placement.** Service 297's stored staging reasons are exactly
two entries — `corroboration_mismatch` on song membership and on song order. Resolving titles at
comparison time removes both, `stagingReasons` becomes empty, and
{@see IngestChurchServiceSourceRevision} then takes the `else` branch and **applies the projection
unattended**. What it would apply is the eleven-item merge, mis-titled item included. The placement
does not repair the merge; it removes the reviewer who would have caught it, across 264 services.
Its "changes no hash" property is a symptom of that inertness, not a safety property.

**Why the normalizer placement is correct.** A stored `song_canonical_key` makes tier 1 fire, so
the six assertions collapse into six items; the mis-pairing cannot form, because tier 1 refuses two
non-identical strong identities outright instead of degrading to position; and corroboration then
agrees because the items genuinely agree rather than because the comparison was loosened. The
projection hash changes precisely because the projection is better.

**Accepted costs, recorded rather than mitigated away.**

1. *Stored evidence and hashes become catalogue-dependent.* A catalogue edit is henceforth a
   reprojection event, not a lookup-table tidy-up: it changes assertions, `proposed_hash`, and
   therefore the acceptance state of exported bundles. Handle it as a versioned reprojection round
   under the existing `policyVersion` machinery, which {@see ChurchServiceConvergenceBundleImporter}
   already enforces on both import paths.
2. *Partial circularity of corroboration.* The catalogue was built partly from these same sources
   (IC3), so some Email/OpenLP agreement reflects shared normalisation rather than two independent
   authors. This is accepted **because storing the key makes it inspectable**: an agreement resting
   on a resolved key is distinguishable in the data from a literal-title agreement, which it would
   not be under comparison-time resolution. Era accuracy reporting (§8) must not present
   key-resolved agreement as independent corroboration without that split.
3. *Invariant 4 is amended, not bent.* See §3.2.

### 2.6 Carried and lapsed prior decisions

FR-D1–FR-D10, HIR-D1–HIR-D7 and the archived §5 decision table remain binding wherever their
object survives — notably FR-D2 (release via signed `historic-import:release-batch`, never as an
import side effect), FR-D4 (accuracy floors: precision ≥ 0.98 stops a batch, recall ≥ 0.85 routes
to review, identity/hash/supersession/visibility at 100%), FR-D8 (source custody duration and
return of the drive), FR-D9 (fail-closed manifest adjudication with written reasons), D10
(one-person project; no multi-human control, ever — also recorded in `AGENTS.md`), HIR-D1
(Spaces semantics: conditional create honoured, conditional delete ignored → never delete by
path), and HIR-D5/D6/D7 (investment discipline and Axis B, now amended by REV-D2). Decisions whose
object was the one-shot operation — the 480-minute ingress window, checkpoint window splits,
freeze/approval protection (FR-D5/D6/D7 in part) — **lapse with the machinery rather than being
relitigated**. HIR-D8 is decided and its projector implementation has landed (§2.5); the corrected,
catalogued corpus certificate remains IC3 work.

## 3. Safety model

### 3.1 Why incremental convergence is safe here

Three properties, all landed, bound the blast radius of any import mistake to "wrong but private,
fixable per service":

1. **Quarantine-until-release.** Imported sermons/talks carry
   `SermonPublicationState::Quarantined` and every public surface excludes them — archive pages,
   podcast/feeds, sitemap, direct asset delivery — via `app/Services/Public/SermonRepository.php`,
   `SitemapService.php`, `PreacherListCache.php`, `PublicChurchServiceArchiveService.php` and
   `PublicServiceContentEligibility.php`. Services are additionally dark behind the
   `church.services.public_from` era boundary (`config/church.php`). Publication happens only
   through `historic-import:release-batch` (`HistoricSermonPublicationService`, HIR7).
2. **Create-only object writes.** A durable ownership claim precedes any byte;
   writes are conditional creates; compensation never deletes by path (HIR-D1: Spaces ignores
   conditional delete); failures orphan and are reconciled by a human.
3. **Idempotent per-service classification.** Every apply classifies each target as
   `already_present` / `create` / `safe_enrichment` / `blocked_difference` / `conflict` before
   writing; re-running a round is safe by construction. `apply` is an operation, not a
   classification; manifest `included`/`excluded` is corpus curation, not target classification.

### 3.2 Hard invariants

Every one is enforced by landed code and named tests; none may be weakened by any IC package.

| # | Invariant | Enforcing seam |
|---|---|---|
| 1 | No public surface exposes quarantined or pre-boundary content (audience preservation, was F29) | The §3.1 read-side services and `SermonExposurePolicy`; release only via signed batch |
| 2 | Exact approved manifests — never globs, rescans or ad-hoc subsets — are the sole mutation authority (was F37/F48) | `OosCurationManifest`, `OpenLpCurationManifest`, `HistoricVideoCurationManifest`, plan-hash checks in every import command |
| 3 | Parsing, provenance and mutation bind to one immutable byte snapshot whose hash equals the approved manifest hash (was F49/F50, B15) | `OosArchiveParseCacheBinding` (HIR2), snapshot checks in the OoS/OpenLP importers |
| 4 | Source evidence is immutable and single-origin, **save for versioned catalogue resolution** (amended 2026-08-21, §2.5); supersession is explicit lineage; source silence never removes another source's occurrence (was F30, B3, B5) | `IngestChurchServiceSourceRevision`, source adapters, `ChurchServiceProjector` |
| 5 | Manual final authority is never silently overwritten by machine evidence (was F40) | Projector/review-state services |
| 6 | Object storage is create-only under a prior durable claim; no DB transaction spans object I/O; cleanup deletes only exact owned receipts or not at all (was F45-objects, HIR7) | `HistoricSermonPublicationService`, `HistoricReleaseObjectStore` implementations |
| 7 | Historic bulk work emits no external notification, model/domain event or after-commit authoritative side effect; ordinary weekly processing still alerts (was F51/F52) | `ProcessingNotificationRouter` historic containment, convergence event audit |
| 8 | Every Scripture passage settles as linked-or-approved-absent before export/apply (was F59, HIR3) | `HistoricScripturePassageRequirements`, `EnrichHistoricScripturePassagesCommand` |
| 9 | Portable artifacts carry no raw email body, secret, local path, user ID or private note; private reports are `0700`/`0600` (was F33/F34) | `HistoricImportArtifactRedactor`/`ArtifactWriter`, bundle exporters |
| 10 | One source-key identity across PHP, bundles and MySQL; provenance recorded even for unchanged normalised content (was F54/F55) | Shared key value objects and their parity tests |
| 11 | Per-service apply runs under lock against current state; stale plans rebind or refuse (was F41-lock) | `ConvergeHistoricChurchService` |
| 12 | The date/identity manifest-corroboration gate on email extraction stays fail-closed (8/8 recall measured) | `OosArchiveEvaluator` gate reasons |
| 13 | Approved video bytes are hash-verified immediately before dispatch and outputs hash-verified after (was F31) | `HistoricVideoImporter`, `HistoricVideoCurationManifest::verifiedPath()` |
| 14 | Same-day special-service identity collisions fail closed; curated occasion/title facts are carried (was F44) | Identity resolver + manifest curation fields |

**Amendment to invariant 4, 2026-08-21 (maintainer, §2.5).** `song_canonical_key` may be resolved
against the song catalogue when an assertion is normalised, so that stored evidence carries an
inference made at normalisation time rather than a literal source string alone. The immutability
that invariant 4 protects is thereby narrowed, deliberately, to this shape:

- What a source *said* remains immutable: `source_title` and `normalized_title` are never rewritten
  by catalogue state, and no other field may acquire a catalogue dependence under this amendment.
- The resolved key is reproducible, not free-form: it must be derivable from the catalogue plus the
  source title, so any assertion can be re-derived from its byte snapshot and a stated catalogue
  version. Invariant 3's snapshot binding is untouched.
- A catalogue change is a **reprojection event**. It may change assertions, `canonical_identity` and
  `proposed_hash`, and therefore may invalidate approved proposals and exported bundles. It is
  carried out as a versioned round under `policyVersion`, never as an in-place edit.
- Single-origin is unaffected: resolution adds a key to one source's own assertion and never merges,
  borrows or infers content across sources.

Anything wider than this — resolving titles, borrowing another source's text, or storing a key that
cannot be re-derived — remains prohibited by invariant 4 as written.

The permanent regression canary (`HistoricNormalOutputContractTest` and the WP0/B-series red-test
suite) is retained forever as the portable-processing contract, exactly as the archived plan's
WP10 already required.

### 3.3 Round gates

Each lane round passes three gates; there is no other gate ladder.

- **RG-A (staging):** the lane's corpus stages cleanly on a certified-clean `crockenhill_rehearsal`
  (`historic-import:provision-rehearsal-database`), and the audit report (§7.3) reconciles:
  exact membership against the approved manifest (was F53), zero unexplained identities (F1 rule),
  held residue enumerated by reason, proposal census run (§9). Focused tests, Pint, PHPStan green.
  The manifest reconciliation is produced, not configured: run
  `oos:generate-corpus-expectation --manifest=<approved manifest>` and feed the artifact to
  `services:proposal-census --expectation=` (or `church.historic_corpus.expectation`). Setting
  `HISTORIC_CORPUS_EXPECTED_SERVICES` by hand no longer certifies anything and is ignored while an
  expectation is present.

  A round declaring more than one source kind is certified from **one artifact per lane**, because
  each is a hash-locked derivation of exactly one approved manifest and there is one manifest per
  lane. So an `email,openlp` round runs `oos:generate-corpus-expectation` *and*
  `openlp:generate-corpus-expectation`, and passes both to `services:proposal-census
  --expectation=<email> --expectation=<openlp>`. A declared kind with no artifact is
  `expectation_source_kind_unapproved`: the Email reconciliation is never read as covering OpenLP.
  Membership goes the other way — one certificate spans every lane, so
  `services:generate-corpus-membership --source=email --batch-hash=<email> --source=openlp
  --batch-hash=<openlp>` produces the single artifact `--membership=` takes. Note that the corpus
  size the gate reports is the **union** of the lanes' approved identities, not their sum: both
  lanes describe the same services from different evidence.

  **Sequencing rule (2026-08-25):** this final convergence gate is expected to remain red while an
  approved later source is still needed to resolve a proposal. A red gate still prevents RG-B for
  the affected converged service state, but it does not undo a source-lane settlement trigger such
  as REV-D4 or forbid staging the named next source. The Email/OpenLP rehearsal has 223 services in
  exactly that state awaiting video. `source_item_projection_stale` is truthful until their
  proposals settle; do not suppress it or invent a terminal class status to make the gate pass.
- **RG-B (production apply):** approved manifest + plan hash presented; pre-round verified
  database backup taken; `HistoricImportProductionGuard` satisfied for the named round (IC2
  re-scopes it from one-shot GO to per-round approval); apply executes only §3.2-compliant writes;
  post-round audit report generated and reviewed.
- **RG-C (release):** §8 — era accuracy evidence at the FR-D4 floors, spot-check against the truth
  set, editorial QA, acceptance journeys; signed release batch.

**Historic closeout** (supersedes G9 wherever other plans reference it): every lane's audit report
at 100% disposed membership, final releases done, drive returned per FR-D8. It triggers IC8
retirement, and is when other plans' "post-G9" work (architecture AM8–AM10, simplification R8
deletions) unblocks.

## 4. Finding and gate dispositions

Superseded documents keep the finding text; this table is the binding disposition. "Kept" items
appear in §3.2; "report" items live in the §7.3 audit report; "lapsed" items impose no further
work or evidence.

| Disposition | Findings |
|---|---|
| **Kept as hard invariant** | F29, F30, F31, F33, F34, F37, F40, F41 (lock half), F44, F48, F49, F50, F51, F52, F54, F55, F59; HIR1–HIR3, HIR6–HIR7 code |
| **Kept as open work** | F60 (IC6), current-era back-fill (IC4, after remeasurement), post-video convergence census, video corroboration-grade propagation, video manifest population and resumable operation binding (IC5) |
| **Reframed as report** | F32 (per-source accounting; exit contract changes in IC2), F53 (exact membership), F57 (round audit completeness), per-round cost/throughput accounting (was F58's measurement half) |
| **Closed with evidence** | F1 completeness (Email lane, 2026-08-16 — see below), F2, F3, F4, F42, F43, F46's guard code, F61, F62, F63, F64, F65, F66; B1–B21 (all repaired; red tests retained); HIR0–HIR7 landed |
| **Lapsed with the one-shot model** | F35 (journal-resume proof), F36 (forensic two-copy custody ceremony — read-only original, one verified working copy and hash inventory remain required practice), F38 (checkpoint *exactness* gating — checkpoints stay as tooling), F39 (fingerprint *binding* — fingerprints stay as recorded provenance), F45 (timed restore/RPO/RTO drills — verified backups remain mandatory), F46 (freeze/watchboard/change-control window), F47 (forced-crash recovery proof), F56 (freeze-sweep semantics — ingress lock tooling retained for optional brief pauses), F58 (window budget); HIR4/HIR5 evidence obligations; HIR8 steps 1, 2, 4, 6–11; safety invariants 4, 5 and 9 of the archived safety plan |

**F1 completeness, closed 2026-08-16 (Email lane).** The census could not fail for an approved
entry that never staged: `services:generate-corpus-membership` built the expected membership by
querying `church_service_source_records`, so a held entry was absent from both sides of the
comparison, and `church.historic_corpus.expected_services` was a scalar an operator typed — on the
2026-08-15 round, from what that round had just staged. `oos:generate-corpus-expectation` now
derives the expectation from the approved manifest alone (`OosApprovedCorpus`), which is possible
because every field a staged revision carries is already a function of the manifest: `batch_hash`
is the curation plan hash, `input_hash` is the entry's approved `sha256`, and the source key is
`OosCurationEntryFactory::sourceKey()`. `ChurchServiceCorpusExpectation` reconciles it against the
staged corpus and the census gate refuses without it. Extra identities are admitted only where an
approved entry's origin explains them on that entry's approved date — the hash-covered
`service_beyond_manifest` rule — because one email legitimately stages both that Sunday's orders,
which is exactly why the scalar comparison was the wrong instrument and is now suppressed whenever
an expectation is present. Intentional holds carry an operator's written reason through
`--accepted-holds`, on FR-D9's fail-closed-with-reasons pattern. **The OpenLP lane now has its
producer.** The populated v2 curation fields in batch `openlp-curated-2026-08-23` feed
`OpenLpApprovedCorpus`, which derives the expectation from the approved OpenLP manifest alone.
The combined census accepts one hash-locked expectation per lane, so its `email,openlp` round can
now certify all 614 staged OpenLP identities alongside Email.

G0–G9 as a ladder is retired; their still-live content maps to RG-A/RG-B/RG-C and §3.2. The
archived per-gate audit table's open items are absorbed as follows: G1/PR5's manifest-field
population → IC5; G2 census/completeness → RG-A; G3 round trips → closed; G5 rehearsal → RG-A per
lane; G7/G8 window approvals → RG-B per round; G9 → historic closeout.

## 5. Corpus facts and evidence regimes

Locations (recounted 2026-08-24): Email at `storage/scratch/oos` + `oos-verbatim`, authority batch
`oos-curated-2026-08-22-additional-services` (557 entries: 554 include, 3 exclude; 540 distinct
primary identities and 665 identities when declared additional services are included); OpenLP at
`storage/scratch/ServiceRecords`, authority batch `openlp-curated-2026-08-23` (655 entries: 614
include, 36 duplicate-of, 5 exclude); video at `/Volumes/Sonnics/Services` (1.0 TB, 649 supported
recording files grouped into 462 identities: 347 morning, 115 evening). The old 506-file and
`_Rejected/` descriptions predate the mounted corpus's current layout and are retired. The hymn
workbook crosstabs cover 2004–2018 + 2023 (5,759 known-service occurrences over 1,306 identities;
888 identities have no other source; date-only lane implemented and quarantined).

**Eras are defined by which sources survive.** Do not reconcile the opaque historic pairs
`193/220` or `538/662`; their source manifests have moved. Before a release decision, derive each
era denominator afresh from the exact approved Email, OpenLP, video and hymn artifacts used by that
round and retain the producing report.

| Era | Surviving sources | Accuracy method |
|---|---|---|
| 2004–2008 | Hymn date-only | Song membership by date; no service split |
| 2009–2013 | Hymn only | Song membership; no cross-check exists |
| 2014–2018 | Email + Hymn | Cross-source song membership |
| 2019 → 2020-03-21 | Email only | Hand-verified truth set — nothing else exists (zero video: recording starts 2020-03-22) |
| 2020-03-22 → 2022 | Email + video (OpenLP from 2021) | Video corroboration, **graded by recording completeness** (much of 2020 is sermon-only and cannot corroborate song membership); evenings weak until 2022 and never inventoried by the drive's morning-only CSV |
| 2023–2026 | Email + OpenLP + Hymn | Count, sequence and membership all checkable |

Cross-source shape: **375 of 521 email identities (72%) carry at least one corroborating source**;
the three-source union is ~1,594 service identities. (Measured 2026-08-14 against the then-current
521-identity corpus. The denominator is now 538 and the 17 added identities have not been tested for
corroboration, so the 72% is stale in the optimistic direction — re-measure before it informs a
release decision.) OpenLP is the only source proving item *order/count*; the hymn lane proves song
*membership* only — any corroboration rule must say which dimension it relies on.

Email lane baseline (archive-v12, fresh parse, report SHA-256 `acf6b18a…36a55`; measured against the
521-identity corpus and **not** restated for the 538 one — the report hash pins these figures to that
run): 370/534 sources held; 157/521 identities staged; ~95% of sources take a corrective second call
(~1,100 model calls per full re-run — budget accordingly, and do not trim the retry: it produces the
consensus that lets 0.75–0.89 plans finalise); self-reported confidence is weakly calibrated (78.7%
identity-exactness in the 0.90–1.00 band); the genuinely manual residue is ~14 items (6 identity
disagreements, 8 date corrections).

## 6. Work packages

The executable order is §0. IC1's evidence-tier behaviour and IC3's shared semantic-parser,
ground-truth and corroboration work are complete. IC2's per-service convergence semantics landed,
but its claim that every production lane was hash-bound was too broad; §0 slice 2 owns the remaining
portable Email, OpenLP and video command boundaries. Do not buy another whole-corpus model run.

### IC1 — Email evidence-tier import (implements REV-D2) — **IMPLEMENTED 2026-08-15**

**Behaviour contract.** For an archive or weekly email plan with disposition
`ReviewRequired` (`OosEmailParseDisposition`) whose identity is manifest-corroborated (archive) or
unambiguous (weekly): import it as source evidence — source record, assertions, service create or
merge via the existing `importPlan()`/`mergeOrCreatePlan()` path — leaving the service's review
state `NotReviewed` and the plan marked unfinalised. Plans holding for `MissingIdentity`,
identity-gate reasons, or `InvalidExtraction` with *content* reasons (`ContentInvalid`,
`items_out_of_source_order` genuine cases) still take `HeldForReview`/`InvalidExtraction` exactly
as today. Nothing about `AutoImportable` changes.

**Seams.** `OosArchiveEvaluator` (which plans are offered to import), `InboundEmailImportService`
(`importPlan`, `planImportMetadata` — record the evidence tier and hold reasons on the plan
metadata), `ImportOosArchiveCommand` (dispositions/report), `ProcessInboundOosEmail` (weekly
parity), release eligibility in `HistoricSermonReleaseAuthorisation`/release command (exclude
services with unfinalised email evidence), review inbox filters (surface "imported, unreviewed"
without flooding: they enter the §9 census, not the weekly attention queue).

**Red tests first** (`ImportOosArchiveCommandTest`, `InboundEmailImportServiceTest`,
`OosArchiveEvaluatorTest`): a corroborated `ReviewRequired` plan creates evidence + service and is
not finalised and not release-eligible; a date-gate-held plan still imports nothing; a content-
invalid plan still imports nothing; convergence with an agreeing OpenLP archive finalises without
human review (B11 behaviour); a second import round is an exact no-op per service.

**Acceptance.** A staging round (RG-A) shows held residue ≈ the identity + content-invalid
families; staged identity coverage from 157 toward the 393-of-394 the held backlog covers; the 82
already-converged Email/OpenLP services unchanged (`already_present`). Then hand the operator the
residue the round actually measures.

**Status 2026-08-15:** the behaviour, seams and red tests are implemented and merged. The RG-A
staging round itself has **not** been run against the semantic parser, so this package's acceptance
evidence is outstanding, not its code.

**RG0A status 2026-08-21: passed.** The semantic Email corpus was evaluated, its measured
identity/date residue was adjudicated, and the cache-backed replay reduced the honest residue to 19
accepted partial-evidence holds. This is the completed analysis and authority checkpoint, not a
clean-staging RG-A certificate. The corrected semantic cache and its evidence were subsequently
recovered, so the claim that a clean restage requires a new 554-source model evaluation is
withdrawn; the 19-hold figure is also superseded by the corrected rehearsal below. Do not describe
RG-A itself as passed or use RG0A to authorise production mutation.

**Corrected recovered rehearsal 2026-08-21: ran; RG-A remains open.** MySQL binlog recovery
restored all 554 `archive-v13:semantic-annotations-v1` cache entries. A fail-closed cache-only
evaluation selected all 554 approved sources without a model call. Its clean import report (SHA-256
`40d7c046fbfa3c0ff5d28c2011f4d3a44c253ec0841560ed034065e81f46177d`) records 438 `created`,
75 `evidence_retained`, 41 `held_for_review` and zero failures. Its overlapping held-reason counts
are: low confidence 41, source gate 24, no items 23, missing identity 11, unknown content scope 10
and content invalid 2. The portable assertion bundle is retained at
`storage/scratch/semantic-rg-a-recovered-assertions-20260821.json` (SHA-256
`7189a6b5a1fb6e06463626dd4b0203f0295cc23197d7f3336d3ee0025ab61061`); the recovered cache-only
report is SHA-256 `477adb042e3f43d7babc79f4fc6ff84cb31eeca680ed10ace2352a46c31cd4f1`.

The Email-lane census after staging OpenLP is
`storage/scratch/semantic-rg-a-email-census-after-openlp-20260821.json` (SHA-256
`9ae87fd32e0350bfe9f06c8a7ec70e3cbe23d719e87ab04b7ca3ee8c076b825e`). It contains 248
unclassified classes covering 531 pending proposals. The gate blockers are `membership_mismatch`
(`source_item_projection_stale`) and `expectation_mismatch`: 30 approved sources / 29 manifest
identities did not stage, while 116 beyond-manifest identities are explained and zero are
unexplained. Only 158 of 756 staged services carry projection policy v3 and 531 proposal services
are stale for census purposes. These are measured repair inputs, not operator decisions and not a
reason to buy another whole-corpus model run.

**Catalogued RG-A replay 2026-08-21: still open, with the blockers now attributed.** The recovered
evidence was replayed on a fresh `crockenhill_rehearsal_catalogued`, provisioned and certified
clean, then seeded with the song catalogue before staging. Zero fresh model calls: the run is
`--cache-only`, which refuses unless every selected source has a reusable raw parse cache, and all
554 did. Reproducible seed and artifacts:

| Artifact | SHA-256 |
|---|---|
| Song catalogue source `storage/scratch/songs.sqlite` (`service-tracking:sync-songs`) | `d02a274df8a70a2e8f048061fd7890b592d932c960c617c01f173cbb7023a791` |
| Seeded reference tables dump (1,159 songs, 594 authors, 2 songbooks, 1,386 author links, 754 book links) | `791ddab2da5060aee6a7ecb830de6687db2877523c11b438cc04ba4608dda5d9` |
| Import report `rga-catalogued-import-20260821.json` | `009e8ca4a9a824ba4825313f9dc3e3fa561b879cd5f0a44fc849bb094ee64358` |
| OpenLP dry run `rga-catalogued-openlp-dryrun-20260821.json` (plan hash `7acb266f…`, unchanged) | `041d5b12ef4979a33d4846182bc8db08c2296de61d31368aa2a082e7ff9bca27` |
| Email membership `rga-catalogued-email-membership-20260821.json` (membership hash `d8abdc92…`, byte-identical to the recovered run) | `07c577269e7b13a0bfc110b02e40173eb09a2e309628ba9df247e987238193c3` |
| OpenLP membership `rga-catalogued-openlp-membership-20260821.json` | `7eeff761d1b9002e157c3dda18249f97b34a4016bed4f826bd1dd476c4230523` |
| Corpus expectation `rga-catalogued-expectation-20260821.json` (expectation hash `d9702172…`) | `3742a1d8311d4301f85239707d68c4de49b9eb7737532538ba5df0b0e0ea9a4e` |
| Proposal census `rga-catalogued-census-20260821.json` | `3674a53a5053acd8dc1b420e034f55ea693bb381d95f58051b25f4a4c751fa1e` |
| Portable structural holds `rga-catalogued-portable-structural-holds-20260821.json` | `c953c40c7418cbc69904085c2fe0687060c221a7ce2f630b5f23896495c4e0db` |
| Staged rehearsal dump `rehearsal-catalogued-staged-2026-08-21.sql` | `598caa9b0fa141db95389b901c89b411b6e4dc459e79d63ddd4b5afdf3328af4` |

**Superseded on 2026-08-23 by the enlarged OpenLP corpus.** More `.osz` archives were added to
`storage/scratch/ServiceRecords`, taking it from 536 raw (431 flat + 105 in `MorningServices/`) to
**655 flat**, so batch `openlp-curated-2026-08-13` / plan hash `7acb266f…` no longer describes the
corpus and `plan()` refuses it — the raw directory must match the manifest exactly, and it now has
224 unmanifested files and 105 missing ones. The rows above stay as the record of that round; the
OpenLP-derived rows are replaced by:

| Artifact (all `0600`) | SHA-256 |
|---|---|
| OpenLP dry run `openlp-dryrun-20260823.json` (batch `openlp-curated-2026-08-23`, plan hash `64e8c36f…`) | `2559ec5e7ac43f684de62cb3adb420fec169f4d59fdb73f7af21934ed3ea4b34` |
| OpenLP membership `rga-openlp-membership-20260823.json` (614 items) | `6f3d8f917f0f98a4d5cec3dfc4d1f8880c81b1250cda5dd0eeaea71505376cbd` |
| Email membership `rga-email-membership-20260823.json` (650 items) | `07c577269e7b13a0bfc110b02e40173eb09a2e309628ba9df247e987238193c3` |
| Proposal census `rga-census-20260823.json` | `a248c428b6b91b095fb5f77ff5fb7beb36dad2c52aadaa8bef951918b2c5ec26` |
| Pre-apply backup `crockenhill_rehearsal-pre-openlp-v4-20260823.sql` | 74 MB, verified restorable by loading it |

The Email membership is **byte-identical** to `rga-catalogued-email-membership-20260821.json`, which
is the evidence that the Email lane is untouched by this. The Email **expectation is deliberately
not regenerated**: the staged email `batch_hash` is still `2c139a880b78…`, which
`rga-catalogued-expectation-20260821.json` matches, whereas the Email manifest has since moved to
`oos-curated-2026-08-22-additional-services` — rebuilding from the current manifest would assert a
batch that is not staged and raise `expectation_batch_unstaged` for reasons unrelated to OpenLP.

The census was re-run against `crockenhill_rehearsal` after applying the new corpus (614/614
archives, 0 failures, 106 services created, 508 updated). It **still fails on the same two
blockers**, `membership_mismatch` (`source_item_projection_stale`) and `expectation_mismatch`:
staged services 756 → 862, OpenLP evidence 427 → 614, projected services 158 → **287**, pending
proposals unchanged at 531, unclassified classes 248 → 268. The enlarged corpus neither fixed nor
worsened the gate, which is the expected result — those blockers are about Email projection
staleness, not corpus membership. **RG-A is still not passed and HIR-D8 is still not accepted.**

Two counts that look alarming in the rehearsal database and are not. OpenLP source records read
427 → 1,041 because the table is append-only: there are only **614 distinct `source_key_hash`**
values and 427 of the new rows carry `supersedes_id`, so 1,041 = 427 superseded + 614 current. And
the importer's "Imports flagged for review: 329" is a state readout, not a delta — it counts each
archive's service `needs_review` *after* import, including flags Email staging set earlier.
Measured against the pre-apply backup: **0 services flipped in either direction**, and of the 106
new services exactly **1** needs review (2016-05-29 evening, the AM/PM conflict below).

Every private artifact is `0600`. The recovered inputs and the recovered rehearsal database are
retained untouched; the diagnostic database is dumped at
`rehearsal-recovered-diagnostic-2026-08-21.sql` (`b4fbf829f9f01f4dfb6be41cf376e95edd31b0cf88e91a661d3a91f029e6ac30`).

**What the catalogue changed.** Song-title resolution moves from 0 to 2,378 of 2,639 items (90.1%),
and plans with every song resolved from 0 to 472 of 657. Dispositions become 438 `created`,
74 `evidence_retained`, 16 `merged`, 26 `held_for_review`, zero failures; hold-reason categories are
low confidence 26, source gate 24, no items 23, missing identity 11, unknown content scope 10,
content invalid 2. OpenLP staged all 427 again (129 created, 298 updated, 0 failures).

**What the catalogue did not change: anything the gate measures.** 756 services, 627 Email
identities, 427 OpenLP, 298 overlaps, 531 pending proposals, 158 finalised — identical to the
recovered run, for the reason recorded in §2.5. The census gate still **fails** on
`membership_mismatch` (`source_item_projection_stale`) and `expectation_mismatch`, with 248
unclassified classes over 531 proposals. **RG-A is not passed and HIR-D8 is not accepted.**

**The four accounting items, resolved.**

1. *The 67 staged Email identities with no pending proposal are correct, not a gap.* Every one has
   `payload_complete = 0`: `retainPlanEvidence()` ingests them with `project: false`, so they hold
   no canonical items and stage no proposal by design, and `ChurchServiceCorpusMembership` already
   exempts them from `source_item_projection_stale`. No change is required and none was made.
2. *The 30 unstaged approved sources / 29 identities are honest hold residue.* They resolve to 26
   `held_for_review` entries plus four whose manifest-expected plan key held while a sibling plan
   imported. `oos:generate-corpus-expectation --accepted-holds=` exists precisely for these and
   takes an operator ruling per `{item_key, reason}`. **No accepted hold was invented**, so
   `accepted_holds` stays 0 and `expectation_mismatch` stays a blocker. This is the §10 operator
   item, now reduced from 41 to 26 held sources.
3. *`incomplete_projection_audit` on every pending proposal was a defect, now fixed.*
   `ChurchServiceProjector::hasCompleteAudit()` returns false on *any* conflict, and
   `IngestChurchServiceSourceRevision::stagingReasons()` called it and then also appended
   `$projection->conflicts` — so the reason appeared on 100% of the 531 proposals while naming
   nothing an operator could act on, and masked the class each proposal belonged to.
   `hasCompleteFieldDecisionAudit()` splits the explanation half out; `hasCompleteAudit()` and its
   bundle callers are unchanged. The catalogued census carries the reason on **zero** proposals.
4. *`source_item_projection_stale` is not an independent failure.* `projection_policy_version` is
   written only by `ChurchServiceProjectionPersister::apply()`, which the staging branch skips, so
   stale services and pending proposals are the same 531 set by construction. It clears when the
   proposals clear — which, per §2.5, currently requires the corroboration decision.

**Correctness fixes made under the HIR-D5/D7 exception**, each with a red test first:

- **Create/merge classification asymmetry** (`InboundEmailImportService`). `mergeOrCreatePlan()`
  returns `Created` for a new service whether or not the projector staged a proposal, but the merge
  arm returned `HeldForReview` for exactly that state — so an entry whose source revision *was*
  written reported as unimported and stopped later entries superseding it. The projector arm now
  reports `Merged`; the compatibility-merge arm keeps its older meaning, because it stages a
  structure merge without ingesting a projected revision. This is what the two focused failures at
  `ImportOosArchiveCommandTest.php` 1179 and 1344 were reporting: **the expectations were right and
  the implementation was wrong.** Holds fall 41 → 26 with no change to what staged.
- **Human approval was treated as unattended evidence** (`EmailSourceAdapter`). The adapter set
  `unattended_content_finalization` from `$plan->isAutoImportable()` alone, so an admin-approved
  import entered the evidence tier and §2.5's dimension corroboration reopened a service a person
  had just approved — invariant 5 read backwards. The adapter now takes `reviewedByPerson`.
- **A partial review closed itself** (`ChurchServiceProjectionPersister`, `IngestChurchServiceSourceRevision`).
  The persister retracted `needs_review` whenever an automatic projection landed, and the ingest
  action marked *every* pending proposal stale before projecting. Together they dropped proposals a
  reviewer had deliberately left pending out of the attention inbox. The retraction now requires no
  pending proposal to remain, and the stale sweep no longer applies to a `Manual` revision, which
  `ReviewChurchServiceEvidence` owns explicitly.

These three restored six pre-existing failures in `EvidenceReviewTest`,
`ApproveInboundEmailImportTest`, `ChurchServiceProposalRuleServiceTest`,
`ChurchServiceConvergenceBundleRoundTripTest` and `ChurchServiceConvergenceBundleImporterTest`. None
of them can affect the staged corpus: it holds 0 Manual source records, 0 reviewed imports and 0
review sessions, and all 650 Email revisions carry `unattended_content_finalization: false`.

**The portable bundle's structural re-validation is incomplete — open.** Replaying
`semantic-rg-a-recovered-assertions-20260821.json` through `preflightPortable()` refuses **403** of
554 entries, not 24; the enumerated, reasoned holds are in the artifact above and fail-closed
`apply()` was left exactly as it is. The dominant reason (2,121 occurrences) is "Source line N was
not classified as evidence, an item, or ignored context", and the cause is that the bundle never
ships the ignored-line half of the provenance: `OosArchiveAssertionBundle::export()`'s `Arr::only()`
allowlist omits `ignored_lines`, `structuralReasons()` therefore builds an
`OosEmailItemExtractionResult` with none, and `processing_metadata.parsing` does not persist them in
the first place. Closing it means persisting ignored lines, bumping the bundle format and
regenerating the bundle and all its hashes — a change to a hash-bound portable contract, so it was
**not** made here. The 24 figure in the recovered baseline is not reproducible and should be treated
as withdrawn pending that work.

**Superseded numbers (2026-08-21).** The `~20 identity + ~33 content-invalid` shape and the
`~14 manual adjudications` handed to the operator are **legacy v12 estimates and must not be carried
forward as facts**. They were produced by the deleted legacy extractor. The semantic parser routes
differently — the 38-source truth corpus scores 36 `review_required`, 2 `invalid_extraction` and 0
`auto_importable`, against the legacy baseline's 4/31/3 — and the semantic cache namespace prevents
any legacy raw-cache reuse. The recovered corrected semantic run above is now the operational
baseline. Reuse its hash-bound cache/assertion artifacts; do not re-run the whole corpus merely to
reproduce evidence that now exists again.

### IC2 — Incremental apply semantics — **CORE LANDED; COMMAND BINDINGS STILL OPEN**

Re-scope `ConvergeHistoricChurchService`'s batch admission from "whole approved corpus applicable
or refuse" to "apply every applicable service; report the rest" — per-service lock, classification
and transaction unchanged. Change `ImportOosArchiveCommand`/converge-command exit contracts (was
F32): exit non-zero only for processing *errors*; held/pending residue is reported state in the
audit report. Re-scope `HistoricImportProductionGuard` from one-shot GO to per-round approval
(named round operation + manifest/plan hashes + backup receipt). Keep `HistoricImportJournal`/
checkpoint tooling for long passes; drop their exactness assertions from acceptance.

**Correction 2026-08-24.** The convergence core and canonical `oos:import-archive --import` path
meet that contract. The portable Email bundle path, OpenLP apply and historic-video command still
call `HistoricImportProductionGuard` without all round corpus/plan bindings. Historic video also
does not pass a `HistoricImportOperation` into `HistoricVideoImporter`, although downstream
notification isolation requires it. Treat §0 slice 2 as an IC2 completion prerequisite; do not
authorise production mutation from the earlier “implemented” label.

### IC3 — Item-level ground truth (queued parser plan item 0)

Carried by `docs/reports/historic-import-f64-f65-parser-follow-up-2026-08-14.md` ("Parsing
improvement plan, queued 2026-08-14"). Seed the rehearsal song catalogue first (2,580 extracted song
items become item-level checks). Ground truth is derived from corroboration before it is hand-built;
hand-verify only what corroboration cannot reach. Its output decides HIR-D8 (§10) and calibrates
§8's era accuracy figures.

**Status 2026-08-16.** Item 0 is complete. The census that item 2 made a precondition has been run
(`storage/scratch/archive-v13-attribution-20260816.json`, 554 entries, replayed from cache with zero
model calls) and the producing defects behind it fixed (`9f55f13d2`). Read "Revision 2026-08-16" in
that report before starting anything here — it withdraws item 2's premise and adds items 5–7.

**Authority refresh completed 2026-08-19.** The August hymn snapshot is now authoritative for 2025
and 2026 (§6 IC6), so the earlier 2026-08-16 ground-truth artifacts are superseded. The refreshed
item truth is `storage/scratch/item-ground-truth-2026-08-19-authority-refreshed.json` (canonical artifact hash
`8c87a18889e9ed5dc97088a886113d0c14842d02dc6ef55eb59e69eb72284645`), generated against the
recovered 606-identity / 5,661-active-item rehearsal corpus and the 16 August workbook. This closes
the IC3 authority-refresh prerequisite for later HIR-D8 measurement; final-corpus regeneration and
hymn mutation still wait for IC6.

**Model-evaluation closure 2026-08-18.** The archived
[nano/Luna non-inferiority evaluation](../archived-plans/OOS-PARSER-MODEL-EVALUATION-2026-08-17.md)
ran both 554-source arms and closed without a model verdict. Its corrected same-source diagnostic
found material source-exact self-disagreement in both `effort=none` arms (nano 24/30; Luna 19/30),
with item structure alone above the declared 10% threshold in both (21/30; 8/30). Nano remains
configured only as the unchanged status quo; Luna was neither adopted nor rejected. Do not label the
536 model-discordant sources or run full replicates for that closed comparison. IC3's ground-truth
and release-accuracy obligations remain unchanged.

**Permanent parser handoff completed 2026-08-21.** The
[archived OoS email parser redesign](../archived-plans/ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md)
delivered the executable parser sequence: lossless source annotation, deterministic compilation,
narrow repair, objective evaluation and shared weekly/historic cutover. Delivery 7 acceptance
passed, the semantic parser is the sole path, and the legacy whole-document path is deleted; its
remaining evaluation surfaces have IC8 retirement ownership. The report's queued implementation
order `5, 1, 7, 3` remains evidence, not an executable backlog. IC3 retains the authoritative item
truth, HIR-D8 corroboration and all historic staging/round evidence. **HIR-D8's current projector
implementation is complete**; its accepted Email RG-A corpus certificate remains. The earlier ~14
identity/date adjudications is a superseded legacy-v12 estimate. The recovered 2026-08-21 rehearsal
now supplies the first-pass measurement (§6 IC1), but not the
catalogued, projection-complete certificate required to accept HIR-D8. The parser cannot change
evidence admission, finalisation or publication policy.

**IC3 item 8 — song identity resolution at normalisation (decided 2026-08-21, §2.5; steps 1, 2 and
6 IMPLEMENTED 2026-08-21, steps 3–5 outstanding).**
Implements the maintainer decision and the invariant 4 amendment (§3.2). Scope, in order:

1. Resolve `song_canonical_key` in `ChurchServiceAssertionNormalizer` for song assertions from
   both the Email and OpenLP adapters, from the source title plus the catalogue. The resolver must
   be deterministic and re-derivable; record the catalogue version it resolved against. Unresolved
   titles stay null and fall through to the existing tiers — resolution fills gaps, it never
   overrules (carried from the gate 9 parity rule).
2. Bump `ChurchServiceProjector::$policyVersion`, so existing bundles and proposals are refused
   rather than silently re-interpreted, and reprojection is explicit.
3. Re-run the catalogued RG-A replay from the recovered cache with zero model calls, and record
   against the pre-change baseline: 756 services, 627 Email identities, 427 OpenLP, 298 overlaps,
   531 pending proposals, 158 finalised, 264/269 services with surplus song items, 1,024 surplus
   song items corpus-wide.
4. Assert the projection defect is actually gone, not merely the conflicts: surplus song items on
   dual-source services must reach zero or be enumerated by reason, and service 297 must project
   six song items with correct titles. **A drop in conflicts alone does not satisfy this step** —
   that is exactly the outcome the rejected placement would have produced.
5. Report corroboration agreement split by how it was reached — resolved key versus literal title —
   per §2.5 accepted cost 2, so §8 era accuracy never presents key-resolved agreement as
   independent corroboration.
6. Regression cover for the amendment's boundary: `normalized_title` and `source_title` unchanged
   by catalogue state; a re-derivation test proving the key is reproducible from snapshot plus
   catalogue version.

Expected direction from the §2.5 measurement, to be confirmed rather than assumed: membership
agreement 0/298 → 162/298 and order 0/298 → 154/298. That still leaves roughly 136 overlaps routed
to review on genuine cross-source disagreement, which is designed residue, not a shortfall.

**Implementation status 2026-08-21 (steps 1, 2, 6).** `ChurchServiceAssertionNormalizer` resolves
song identity for planned evidence only, so Email and OpenLP resolve while Livestream (stronger
lyric/OCR evidence) and Manual (invariant 5) do not — the existing `ChurchServiceEvidenceKind`
argument already separates exactly those sets, so no new plumbing was added. The rule was not
copied: `ServiceItemCatalogueSongResolver` already existed for the live item-merge lane with this
same rationale in its docblock, so its per-item resolution was extracted as `resolveItems()` and is
now called by both lanes. The divergence this closes is that the live lane has resolved song
identity through the catalogue for some time while the evidence lane compared raw strings.
`PROJECTION_POLICY_VERSION` is 4. Pint, PHPStan and the full suite (7,044 tests) are green; the two
Dusk failures seen on the first run were browser-transport timeouts and pass on re-run.

**Out-of-scope fix taken deliberately: `Praise!` decoration.** `SongTitleResolver::stripEmailDecoration()`
stripped `Praise 873` but not `Praise! 873`, so the hymnbook's own punctuation left songs unlinked.
It was first assessed as not worth fixing on historic prevalence (8 of 4,341 song assertions) and
that assessment was **wrong**: the resolver is shared with the live lane, so the miss recurs on every
weekly order of service that spells the hymnbook's name naturally, and historic prevalence was the
wrong denominator. Fixed, with the hymn-number forms covered as a set so a later edit cannot repair
one spelling and drop another.

**Replay completed 2026-08-21 (steps 3, 4, 5) — the defect is fixed and corroboration finalises.**
Replayed on `crockenhill_rehearsal_catalogued_v4`, provisioned certified-clean, catalogue seeded
(1,173 source songs → 1,159 canonical, `songs.sqlite` hash unchanged), recovered parse cache seeded
(554 sources), OoS plan hash `2c139a880b78…` and OpenLP plan hash `7acb266f52c3…` both matching the
recipe. `--cache-only`, so **zero model calls**. The v3 database is retained, and both sides were
measured with one script rather than assembled by hand
(`storage/scratch/ic3-item8-replay-20260821/`).

Admission is undisturbed: 438 created, 74 evidence-retained, 26 held, 16 merged; 756 services, 627
Email identities, 427 OpenLP archives, 298 overlaps, 0 failures.

| Measure (all 298 overlaps) | v3 baseline | v4 replay |
|---|---|---|
| Song assertions carrying a catalogue key | 0 / 4,341 | **4,094 / 4,341** |
| Services with surplus song items | 264 | **88** |
| Surplus song items | 1,024 | **128** |
| Pending proposals (corpus-wide) | 531 | **395** |
| Finalised services (corpus-wide) | 158 | **294** |
| `corroboration_mismatch` membership / order / count | 269 / 269 / 55 | **121 / 128 / 55** |

**The residue is enumerated, as step 4 requires.** All 88 services and all 128 surplus items are
attributed, none unexplained: **49 services / 84 items** carry a title the catalogue does not hold,
so the key stays null and matching falls through to the existing tiers by design; **39 services /
44 items** have both sources resolving to *different* catalogue entries, where tier 1 correctly
refuses to merge two non-identical strong identities and routes the disagreement to review. Service
297 is the worked example: 11 song items → 7, the position mis-pairing gone (*"The Best Book To
Read"* now sits under its own identity), and the one surviving duplicate is Email resolving to
`the lords my shepherd 23b` against OpenLP's `the lords my shepherd i will trust in you alone`.

**That second class is correct behaviour, not a backlog (checked 2026-08-21).** Matching first lines
were initially read as evidence that such pairs are one song in two settings, and that reading is
**withdrawn** — the catalogue's own authorship disproves it. `The Lord's My Shepherd #23B` is
William Whittingham (1524–79) against Stuart Townend's `(I Will Trust In You Alone)`;
`Bless the Lord, O my soul (10000 reasons)` is Jonas Myrin / Matt Redman against an unattributed
`Bless the Lord, O My Soul`; `Come O Fount Of Every Blessing (plus extra verse)` is Bob Kauflin's
added verse over Robert Robinson, so its words genuinely differ. Identical opening lines therefore
do not establish identity, and a first-line merge rule would have collapsed distinct songs into one
another. Tier 1's refusal to merge two non-identical strong identities is the correct outcome. Do
not open a catalogue de-duplication work package on the strength of first-line similarity;
`song_authors` is the far better discriminator and is already populated (595 authors).

**But the residue is not therefore all correct — `NIP` is a discarded constraint (2026-08-21).**
The conclusion that these disagreements simply belong in review is **withdrawn**. `NIP` means
*not in Praise!*: it asserts the song is absent from the hymnbook, so it is evidence about *which*
catalogue row is meant, not decoration. `SongTitleResolver::stripEmailDecoration()` removes it. The
corpus bears the meaning out — NIP-prefixed Email lines resolve to songs with no Praise! number 626
times against 44 with one, while Praise/number lines resolve to numbered songs 1,041 times against
19 without.

Those **44 NIP lines resolved to a Praise!-numbered song contradict their own source**, and **23 of
them have a NIP-consistent alternative already in the catalogue** — `NIP 'beneath the cross of
jesus'` resolved to `#699` while an unnumbered *Beneath the Cross of Jesus* exists; `NIP 'facing a
task unfinished' (getty version)` resolved to `#618` while the unnumbered Getty rewrite exists. The
remaining 21 have no unnumbered alternative and are genuinely ambiguous: either the operator's NIP
was loose or the catalogue lacks the row.

Service 297 is one of the 23. Email's `NIP 'The Lord's my Shepherd'` cannot be Whittingham's
Praise! 23B, so it is Townend's setting — which is what OpenLP said. The two sources agreed and the
resolver manufactured the conflict. So both readings hold at once: the catalogue rows are genuinely
distinct songs, *and* the resolver picked the wrong one of them by discarding what the source said.

**Resolved 2026-08-22 — NIP now selects the row (`SongTitleMatch::TYPE_HYMNBOOK_ABSENT`).** A line
marked NIP prefers a catalogue song the hymnbook does not number, on an **exact or word-boundary
prefix match only**. Prefix carries information — an email writes `my hope is built` for the
catalogue's *My hope is built on nothing less* (Cornerstone), and `man of sorrows` for *Man of
sorrows, Lamb of God*. Fuzzy resemblance does not, and the corpus proves it: at a 0.75 cutoff it
pairs `to God be the glory` with *Thine Be The Glory* and `tell all the world of Jesus` with *Tell
Me The Stories Of Jesus* — different hymns whose numbered rows were already correct. Both near
misses are permanent regression tests, so no later widening can reintroduce them. Ambiguous prefixes
resolve to nothing, as everywhere else in this resolver.

A number written behind a NIP marker is a supplement number rather than a Praise! one — already
documented on {@see SongTitleResolver::leadingPraiseNumber()} — so it is deliberately not treated as
positive evidence that would override the marker. The type is registered as an audited match
everywhere inferred links are audited, so the link records that the marker drove the choice.

Expected effect, to be confirmed by the replay rather than assumed: **28 of the 44** NIP-contradicting
resolutions become correct (23 exact twins plus 5 prefixes), and 16 keep their numbered row. Under
the maintainer's rule that **a different tune does not make a different hymn, while different or
added words do**, most of those 16 are already right — `when i survey (modern tune)` is Watts #453
and `yes finished… new version` is Wesley #452. `PROJECTION_POLICY_VERSION` is 5; the replay is
**pending** and should be batched with the extraction work below rather than run twice.

**A catalogue-coverage claim made here on 2026-08-21 is withdrawn.** The modern settings were
reported as absent from the catalogue; they are present — *My hope is built on nothing less*
(Mote / Liljero / Myrin / Morgan / Bradbury) and *Man of sorrows, Lamb of God* (Ligertwood /
Crocker). The test that reported them missing required exact bare-title equality and never reached
a longer catalogue title. There is no coverage gap here; there was a matching gap.

**Cross-source corroboration now finalises, measured the way the withdrawn claim should have been.**
Rather than reading `payload_complete` — which is absent from every one of the 650 Email records in
both databases, so it cannot discriminate — this counts merged song items whose
`metadata.source_assertion_sources` carries *both* sources:

| | v3 baseline | v4 replay |
|---|---|---|
| Finalised overlaps | 29 | **165** |
| …with a song item corroborated by both sources | **0** | **136** |
| …whose song items are OpenLP-only | 29 | 29 |
| Song items carrying both sources | 0 | **565** |

This independently reconfirms the withdrawal of the original "29 automatically converged": those
same 29 are still OpenLP-only in v4 and still are not corroboration. The **136** services are new,
and are the first services on this corpus that HIR-D8's corroboration path has actually finalised.

**Step 5 — agreement split, and it is the uncomfortable number.** Of 1,340 Email song assertions on
overlapping services: 1,170 (87.3%) agree with OpenLP *only* after both sides resolve through the
catalogue, 6 (0.4%) agree on literal title, 103 (7.7%) are unresolved, 61 (4.6%) disagree. So of the
1,176 agreements, **99.5% rest on the catalogue rather than on matching text**. Accepted cost 2 is
therefore not a theoretical caveat on this corpus but the dominant case: §8 era accuracy reporting
must present these as catalogue-resolved agreement, never as two independent authors writing the
same thing. The catalogue is a legitimate anchor — it is the only vocabulary both sources reach —
but it was built partly from these sources, and the reporting must keep that visible.

**What this does not yet certify.** RG-A requires the full audit-report reconciliation, not only the
song-identity figures. This run shows `Auto-import precision (identity) 77.6%`, well under the FR-D4
floor of 0.98, and `Item counts reconciled 0/1`; the 26 holds still await the maintainer's
`--accepted-holds` ruling (§10). Song identity was one gate condition among several, and the others
are unchanged by this work.

**IC3 item 9 — Email song-reference extraction (BUILT 2026-08-22; replay pending with item 8).**
The residue after item 8 is almost entirely Email-side: of 247 unresolved song assertions in the
catalogued replay, **247 are Email** and OpenLP resolves against the same catalogue nearly
perfectly. The catalogue is therefore not the limiting factor — reading the Email line is. At least
**85 (34%)** name a song the catalogue already holds, and that is a floor, because the estimate used
a strict similarity cutoff and left obvious cases out (`nip 'jesus is lord, the cry that echoes'`
and `nip 'jesus is lord – the cry'` are the same song written three ways). About **15 (6%)** name no
specific song at all — `hymn - gareth to choose`, `2 songs from holiday club (to follow)` — and must
keep resolving to nothing.

The classes are named, and each is a separate decision rather than one regex:

1. **Prose wrappers** — `final hymn for evening – 313 'let us love and sing and wonder'`,
   `welcome sheet: 'see what a morning'`, `my final hymn for the morning is 427 '…'`. The title is
   present, wrapped in scheduling prose. Largest class.
2. **Mojibake** — `nip â€˜behold the lambâ€™` is UTF-8 read as Latin-1. This is an encoding defect
   in the email path, worth fixing on its own merits and not by teaching the matcher to read it.
3. **Other hymnals** — `mp196 'good christian men rejoice'`. Only `Praise` and `NIP` are handled;
   Mission Praise numbering is not.
4. **Contractions and spelling variants** — `there's a hope` against the catalogue's
   `there is a hope`.
5. **Hymn numbers in unhandled line shapes** — 35 lines carry a number the catalogue holds.

**The trap this work must not fall into.** A naive number pass matches
`songs 2+3 - who, o lord, could save themselves?` to hymn **#3, *O Lord How Many Enemies*** — the
`2+3` is a list position, not a hymn reference. A number must be in hymn-reference position to
count. In this corpus a wrong link is worse than a null one, so every class above needs the same
treatment NIP got: an exact/prefix rung with the near misses pinned as regression tests, never a
widened fuzzy cutoff.

**Variant parentheticals are the same discarded-constraint problem as NIP, and are harder.**
`(cornerstone)`, `(getty version)`, `(modern tune)` and `(new version)` name which setting is meant,
while `(v1 only remaining seated)` is a performance note; `buildProbes()` strips both alike. Under
the maintainer's tune-versus-words rule a `(modern tune)` variant is the *same* hymn and needs no
new row, whereas added words — Kauflin's extra verse on *Come O Fount* — make a different one. Do
not attempt this class without that rule in hand.

Sequencing: land with item 8's pending replay so `PROJECTION_POLICY_VERSION` moves once and the
catalogued RG-A replay runs once, not twice.

**Built 2026-08-22, and measured against the corpus rather than against its examples.** Every
change was scored by resolving all 2,569 Email song assertions in `crockenhill_rehearsal_catalogued_v4`
through the working tree's resolver and diffing against the same run before the change
(`storage/scratch/ic3-item9-20260822/`), so the evidence is gains, *changes* and *losses* — not
gains alone. Result: **71 newly resolved, 16 re-pointed at a different song, 3 links removed**;
2,339 → 2,407 of 2,569 (93.7%). Every one of the 16 changes and all 3 removals were inspected
individually and each is a correction.

**The plan's named trap was not hypothetical — it was already live.** `stripLeadingLabel()`
stripped `Song ` from `Song 1 - Oceans` and handed the `1` to the Praise!-number rung, so twelve
corpus lines were linked to *Happy The People Who Refuse #1* and *O Lord How Many Enemies #3* —
songs nobody had written down. Those are the 16 changes and 3 removals. The rule now consumes a
**single digit** after the role word as a list position: services number their songs in single
digits, while the corpus writes every real reference with the book's printed two- or three-digit
form (`Song 187 'O God beyond all praising'`), so the digit count separates them without reading
the rest of the line. `songs 2+3` still resolves to nothing, and a number the writer marked as a
reference — `(#894)`, `Praise no 618` — is read by its own rung and is unaffected.

**What each class needed.**

1. **Prose wrappers** are read off the *quotes*, not by enumerating the prose: the writer already
   said where the title stops. One quoted run per line only — `either 'great is thy faithfulness',
   'amazing grace' or 'it is well with my soul'` offers a choice, and resolving the first would
   pick a winner the source never picked. The run is taken from the raw line because
   `stripEmailDecoration()` trims a leading or trailing quote and would leave the other half
   unpaired. This is the largest single contributor.
2. **Mojibake** is repaired at ingest, in `App\Support\MojibakeRepair`, not in the matcher. The
   transform is applied only when it is **reversible** — re-damaging the candidate must reproduce
   the input exactly — so accented prose and text that was never damaged are returned untouched.
   It is wired at three boundaries: the Mailgun request (body, HTML and subject), the OoS archive
   entry factory (after digest verification, since the approved bytes stay hashed as approved),
   and `ChurchServiceAssertionNormalizer`. The last is required, not belt-and-braces: the archive
   parse cache is keyed on the source file's **digest**, not its body, so results banked before
   the ingest fix still arrive damaged — which is also why repairing the body costs the replay no
   model calls.
3. **Other hymnals** needed no rung of their own. `MP196 'Good Christian Men Rejoice'` resolves
   through the quoted run, and `(p315)`/`(#894)` through the parenthesised book-number rung, which
   requires the marker to *open* the parenthetical so `(Crockenhill Praise 47)` — a different
   book — is not read as a Praise! number.
4. **Contractions and `&`** are expanded per probe. Only contractions that cannot also be
   possessives: expanding `'s` generally would turn `God's love` into `God is love` and hand a
   different catalogued song a false match.
5. **Hymn numbers in unhandled line shapes** are reached by three narrow additions: a full stop
   counts as a label separator (`Hymn. 96`), a leading `4. ` enumerator is stripped (without it
   `4. Praise no 618 - Facing a task unfinished` resolved to the *unnumbered* Getty rewrite
   instead of #618, because the enumerator hid the explicit reference), and a dash-separated tail
   is probed as an attribution (`Creator God - Ben Slee`).

**NIP became position-independent, and that was a second discarded constraint.** Item 8 anchored
the marker to the start of the line, so `Communion hymn – NIP 'Beneath the cross of Jesus'`,
`Beneath the cross of Jesus NIP` and `Song In Christ Alone (NIP)` all had their marker ignored and
kept resolving to the numbered twin. The assertion is the same wherever the operator wrote it.
A role word standing immediately before the marker is now recognised as a label on that evidence
(`Hymn NIP Creator God`), and only on NIP lines is a bare role word stripped — the marker has
already narrowed the answer to an unnumbered song, so a title that genuinely opens with "Song" has
to be unnumbered *and* match the remainder exactly before this can mislead.

**Nothing was widened fuzzily.** Every addition is an exact or word-boundary rung, and the near
misses are pinned: `tests/Unit/Services/Song/SongTitleResolverEmailReferenceTest.php` holds the
list positions, the three-song choice and the lines that name no song, all verbatim from the
corpus.

**The residue is enumerated, not estimated.** 162 assertions remain unresolved: **83 (51%)** name a
song the catalogue does not hold, **58 (36%)** name no specific song and must keep resolving to
nothing (`hymn - gareth to choose`), and **21 (13%)** are still matcher gaps — almost all typos
(`come o found of every blessing`, `when i fear my fail with fail`) which only a widened fuzzy
cutoff would reach, plus `NIP 'On the cross'`, which is genuinely ambiguous between two catalogue
songs and correctly refuses. Item 8's ceiling estimate of 85 reachable was a floor, as it said.

`PROJECTION_POLICY_VERSION` stays at **5** — item 8's replay has not run yet, so both land under
one version and one replay, exactly as the sequencing note requires. Pint, PHPStan, the full suite
(7,091 tests) and Dusk (55 tests) are green.

### Items 8 and 9 — the catalogued replay, run 2026-08-22

`crockenhill_rehearsal_catalogued_v6`, provisioned certified-clean, catalogue seeded (1,173 source
songs → 1,159 canonical, `songs.sqlite` hash unchanged), recovered parse cache seeded (554
sources), OoS plan hash `2c139a880b78…` and OpenLP plan hash `7acb266f52c3…` both matching the
recipe. `--cache-only`, and **all 554 entries reused their cache: zero model calls**. None of the
changed files is in `OosParserSurfaceFingerprint::Files`, so the parser version did not move.
Artifacts in `storage/scratch/ic3-item9-20260822/`.

**Admission is undisturbed, again:** 438 created, 74 evidence-retained, 26 held, 16 merged; 756
services, 627 Email identities, 427 OpenLP archives, 298 overlaps, 0 failures.

| Measure | v4 (item 8 baseline) | v6 replay |
|---|---|---|
| Song assertions carrying a catalogue key (all sources) | 4,094 / 4,341 | **4,179 / 4,341** |
| …Email only | 2,322 / 2,569 | **2,407 / 2,569** |
| Services with surplus song items | 88 | **73** |
| Surplus song items | 128 | **98** |
| Pending proposals | 395 | **385** |
| Finalised services | 294 | **304** |
| Finalised overlaps | 165 | **175** |
| …with a song item corroborated by both sources | 136 | **146** |
| Song items carrying both sources | 565 | **613** |
| `corroboration_mismatch` membership / order | 121 / 128 | **108 / 117** |
| `field_conflict` | 4 | **0** |

Assertion-level, joined on source key and position so a repaired title still pairs with its
predecessor: **88 gained, 44 changed, 3 lost**. The 44 split into item 8's NIP work and item 9's
list-position fix; the 3 losses are all removals of a wrong `Song N` → hymn *N* link where no real
song was reachable.

**The replay found a defect the tests had not.** The encoding repair sat inside
`ChurchServiceAssertionNormalizer`'s per-item loop, while `resolveSongIdentity()` runs *ahead* of
it — so four mojibake titles were stored repaired and had already failed to match. Fixed by
repairing before identity resolution (`26d5ee820`); the regression test asserts the resolved song
key rather than the stored title, so it fails on the old ordering. Email keys 2,403 → **2,407**,
which is exactly what the offline harness predicted: the two now agree on all 2,569 assertions.

**Item 8's NIP prediction is superseded by measurement, as it asked to be.** It predicted 28 of 44
NIP-contradicting resolutions corrected and 16 retained. Measured: the denominator is **52**, not
44 — item 9 made the marker position-independent, so eight more lines carry it — and the split is
**32 corrected, 20 retained, 0 lost to nothing**. The 20 retained hold up against the maintainer's
tune-versus-words rule: four are explicit tune variants (`when i survey (modern tune)` → #453),
two carry an explicit number alongside the marker, and the rest have no unnumbered twin in the
catalogue, so the resolver correctly declines to invent one. `man of sorrows what a name` → #433
and `man of sorrows (oh, that rugged cross)` → the unnumbered Hillsong row is the discrimination
working.

**Agreement split, restated on the new corpus.** Of 1,340 Email song assertions on overlapping
services: 1,214 (90.6%, was 87.3%) agree only after both sides resolve through the catalogue, 6
(0.4%) agree on literal title, 69 (5.1%, was 7.7%) are unresolved, 51 (3.8%, was 4.6%) disagree.
**99.5% of agreement still rests on the catalogue rather than on matching text** — unchanged, and
§8 era accuracy reporting must keep presenting it that way.

**Residue after both items: 162 Email assertions and 98 surplus items, all attributed.** The
surplus is 38 services / 59 items whose title the catalogue does not resolve and 35 services / 39
items where both sources resolve to *different* catalogue entries — the second class is still
correct behaviour per the 2026-08-21 finding, not a backlog.

**RG-A is unchanged and still fails.** `Auto-import precision (identity)` is **77.6%** against the
FR-D4 floor of 0.98 and `Item counts reconciled` is **0 / 1** — both identical to the v4 run.
Song identity was one gate condition among several and this work moved only that one
(`Song-link hit rate` 93.6%, `Plans with every song resolved` 528 / 657). The blocker remains the
date/identity resolver and the maintainer's `--accepted-holds` ruling on the 26 holds (§10), not
song matching. Items 8 and 9 are closed; RG-A is not.

### IC3 item 10 — `services_present` was a scalar (2026-08-22)

**The RG-A identity blocker was never extraction, and never the date resolver.** Date accuracy in
the v6 run is 553/554 (99.8%). All 132 `auto_import_precision` failures carry the correct date and
non-empty items and fail on the *service slot* alone; all 132 sit inside one of the 138 entries the
pipeline already flags `service_beyond_manifest`. Four were checked against verbatim source and are
correct extractions of a real second service — `2015-06-14` literally reads
`Evening Family/Youth Service:`, `2015-08-09` carries an `Evening Service:` section at line 66.

**Root cause.** `OosCurationEntryFactory` built `servicesPresent: [$include['resolved_service']]`
from a **scalar** manifest field. 138 of 554 entries (25%) are one Sunday email carrying both that
morning's and that evening's orders, so a correctly-extracted second service scored as an identity
error against the FR-D4 floor. `services_present` reads as a list at every consuming site —
`ChurchServiceCorpusExpectation` validates against it, `autoImportPrecision()` scores against it —
and the only thing that ever wrote it could write one element. This is F66's "validator without a
producer" in its purest form: a single-element list is indistinguishable from a correct one at
every reading site, so the defect was visible only where the value is produced.

**Why the fix is additive rather than plural.** `resolved_service` is identity-bearing: it is half
the source key (`OosCurationEntryFactory::sourceKey()`), it keys the one-active-leaf-per-service
guard (§7.2 lineage), and `OosApprovedCorpus` derives F1 expected membership from it. Widening it
would re-identify every staged revision in the corpus to fix a measurement. Schema **v2** therefore
keeps `resolved_service` untouched and adds `additional_services` (plus `additional_service_labels`
and `curation_note`), which feed `servicesPresent` only. `OosApprovedCorpus` is deliberately not
changed: the hash-covered `service_beyond_manifest` rule still admits the extra identities, so
expected membership is unmoved. The version bump is the load-bearing half — the field is additive
and its absence unambiguous, but a v1 reader handed a v2 manifest would silently drop a declared
second service, which is exactly the failure the field exists to end.

**Adjudication, produced not asserted.** Artifacts in `storage/scratch/`, and the producer →
rulings chain re-runs to a byte-identical artifact:

| Artifact | SHA-256 |
|---|---|
| `services-present-adjudication-20260822.json` | `1c3546e59190730c4aab5eae9b7eb1446633508e043689492d1482fa937e0468` |
| `services-present-adjudication-producer-20260822.py` (rule `oos-services-present-producer-v4`) | `0aa5dc0cb7826c4ba209c596c51f9a7b8c121371515fced5c4751117e1ccfc18` |
| `services-present-rulings-20260822.py` (the maintainer's 2026-08-22 rulings) | `13098acd24994c6b94b6f9610fe6ef73a6388be68272a8ea3ef902223b35cab3` |

**The rule is positional, not lexical:** a line counts as a service heading only when it *starts* with the service
name, which is what separates `Evening Service – communion – 6pm` (a section) from
`Final hymn for evening service – 641 '…'` (an item belonging to a service that is not in this
document). Calibrated by negative control — the same rule against the service the manifest already
declares finds it in **129/138 (93.5%)**, so the rule under-detects and routes to a human, which is
the only direction it can err.

| Decision | Count |
|---|---|
| `confirmed_present` (standalone heading in the body) | 118 |
| `confirmed_present_afternoon_slot` | 8 |
| `confirmed_second_service_slot_unresolved` | 3 |
| `human_adjudication` | 11 |

**Maintainer rulings, 2026-08-22.** The 118 accepted as a batch. Four afternoon/evening headings
(`2015-11-08`, `2016-03-13`, `2016-06-12`, `2017-06-11`) are genuine evening services. The five
2022 building-work Sundays (`2022-02-27`, `2022-03-06/13/20/27`) met at 10am in the rear hall and
2pm in the village hall; the 2pm carried the children's talk and main sermon and was functioning as
the main service, but is stored as **evening** because `SermonService` and the `{service}:{date}`
plan key cannot hold two morning services on one date — recorded verbatim in `curation_note` on all
five. Fragments are **preserved**: a stray hymn belonging to a service whose order is elsewhere
keeps its evidence rather than being discarded. `2023-12-24`'s second full order is the Christmas
*morning* service — a different date, not a slot — declared `other` with the required label.

**Measured.** `Auto-import precision (identity)` 456/588 = 77.6% → **585/588 = 99.49%**, which
clears the FR-D4 floor of 0.98 on the manifest change alone. The residual 3 are the `other`-slotted
plans in point 1 below, held for review by design; 585/585 was considered and rejected as not worth
a corpus re-parse. Curation plan
hash `2c139a880b78…` → **`5a6bef7cf376…`**; the pinned rehearsal-recipe hash is stale by
design and must be updated before the next replay so the mismatch is not read as a fault. The
extraction cache is untouched — curation is not in `rawCacheKey()` — so the replay stays **zero
model calls**. `entryAuthorityHash()` includes `services_present`, so the portable bundle needs
regenerating; that is already the open §10 bundle item and the two should land together.

Pint, PHPStan, the full suite (7,102 tests, 84,128 assertions) and Dusk (55 tests) are green.

**Outstanding, and why each is not this item's work.**

1. **Three `other`-slotted plans stay held for review — deliberately not a parser change.**
   `2015-12-20` (3 stray songs), `2016-02-07` (4 stray songs) and `2022-02-27` (a complete 12-item
   order whose source reads `Afternoon meeting (2pm) in village hall`) are slotted `other` by the
   extractor and refused by the validator — *"An other service requires explicit special-service
   evidence"*. All three appear in `held_plan_keys`: they are held and enumerated for review, not
   dropped, which is the designed outcome rather than a defect.

   **Do not "fix the parser" for these.** The slot is the extractor's own output
   (`"service":"morning|evening|other|unknown"` in `OosEmailExtractionPrompt`), so there is no
   file-specific lever, and a general one would be a prompt change to correct 3 documents out of
   554. `ParserVersion` is part of `rawCacheKey`, so any parser change — prompt or deterministic
   guard — re-parses the whole corpus with model calls. Worse, the archived model evaluation
   measured source-exact self-disagreement of 24/30 at `effort=none` with item structure alone above
   the 10% threshold, so a bump perturbs the corpus measurement *even with no prompt change*: the
   re-run is itself a change to the thing being measured. Identity precision is **585/588 = 99.49%**
   without any of it, clear of the 0.98 floor; the fixes were buying a nicer number, not a passing
   one. Revisit only if review load justifies it, and then as a general slotting rule measured
   across the corpus, never as a per-document correction.
2. **`Item counts reconciled` 0 / 1** — one entry, `2026-02-22-am-revised`, asserted 13 items and
   parsed 14. It needs adjudicating, but a one-plan denominator should probably not gate RG-A at all.
3. **The 26 held semantic Email sources** still need the `--accepted-holds` ruling (§10). Unchanged
   by this item.

**A naming defect found on the way.** `corroborated_plan_keys` in the run report is
`array_unique($eligiblePlanKeys)` (`OosArchiveEvaluator.php:69`) — eligibility, not corroboration.
Any figure quoted from that field as independent corroboration needs re-reading; the genuine
cross-source corroboration measure is the census, not this.

### IC3 item 11 — the portable bundle dropped its ignored lines (2026-08-22)

**`preflightPortable()`'s 403 refusals were a bundle-format defect, not 403 bad documents.**
Splitting `rga-catalogued-portable-structural-holds-20260821.json` by reason class:

| | Entries |
|---|---|
| Refused **solely** for unclassified source lines | 373 |
| Refused for at least one other reason as well | 30 |
| Valid | 151 |

**Root cause, and it is the same seam class as item 10.** `OosEmailExtractionValidator` requires
every source line to be classified as service evidence, an item, or ignored context. The extractor
produces those `ignored_lines` and `OosEmailParserService` carried them through the evening-evidence
pass — then dropped them at the `OosEmailParseResult` boundary, which had no such field. They never
reached `processing_metadata.parsing`, so `export()`'s `Arr::only` could not ship them, so
`structuralReasons()` re-validated every shipped entry against an extraction that declared nothing
ignored. Every greeting and signature in the corpus came back as an unaccounted line: 2,121 reason
instances of one shape. Item 10 was a value that could only ever be written singular; this is a
value that was produced and then discarded in transit. Both were invisible at every reading site.

**Not derived, persisted.** Reconstructing "ignored = any line no item claimed" was rejected: it
makes the rule vacuously true, and the rule's whole value is that the extractor *declared* each
line's disposition. The field is threaded through the parse result, its encode/decode pair, the four
`OosArchiveIdentityResolver` rebuilds that already carry `extractionAttempts` the same way, and the
bundle's export and rebuild.

**Bundle `VERSION` 1 → 2**, on item 10's reasoning: a v1 bundle is refused outright rather than read
leniently, because a lenient read turns a check that cannot run into a silent pass. Malformed
entries are dropped on restore but refused on import — a bad shape in the database is legacy
residue, a bad shape in a bundle means the bundle is untrustworthy.

**Reproduced before it was fixed.** With the fix stashed, a three-line source whose third line the
extraction ignores stages as
`"Source line 3 was not classified as evidence, an item, or ignored context."` and `--apply-bundle`
refuses the bundle. Both tests are retained.

Pint, PHPStan, the full suite (7,106 tests, 84,149 assertions) and Dusk (55 tests) are green.

**Outstanding.** The bundle has not been regenerated — that is the §10 item, and it now folds
together with item 10's `entryAuthorityHash()` move: one replay covers both. The expected outcome is
portable-valid 151 → ~524 of 554, with ~30 genuine structural holds remaining (16 evening-boundary,
13 non-existent service evidence lines, 6 non-existent item lines, the 3 `other`-slotted plans ruled
not-queued in item 10, 2 duplicate plans; the classes overlap, so do not sum them). Those 30 are
real findings about documents and need dispositioning, not a format change.

**Before the replay:** the curation plan hash is
`5a6bef7cf3769f534de1ebee869b92a026c11d83777e25ab8f3d0e254fef3460` (recomputed 2026-08-22, matching
item 10's `5a6bef7cf376…`). The rehearsal recipe's pinned `2c139a88…` is stale and would report a
plan-hash mismatch that reads like a fault.

### IC3 item 11 replay — the bundle regenerated, the refusals did not move (2026-08-22)

`crockenhill_rehearsal_catalogued_v7`, provisioned certified-clean, catalogue seeded (1,159
canonical), recovered parse cache seeded (554 sources, all with a cached binding). Plan hash
`5a6bef7cf3769f534de1ebee869b92a026c11d83777e25ab8f3d0e254fef3460` accepted. `--cache-only`, **zero
model calls**.

| Measure | v6 | v7 |
|---|---|---|
| Date accuracy | 99.8% | 99.8% |
| Auto-import precision (identity) | 99.5% | 99.5% |
| Held for review | 26 | 26 |
| created / evidence_retained | 438 / 74 | 441 / 71 |

**The three that moved are item 10's effect, not item 11's.** `2015-06-14`, `2016-05-01` and
`2016-06-26` imported *identical* plan keys in both runs; what changed is that the manifest now
declares the second service the document carries, so the entry is fully explained and reads
`created` rather than `evidence_retained`. `2015-06-14` is one of the four item 10 checked against
verbatim source.

**The bundle regenerated cleanly and the refusals are unchanged: still 151 valid / 403 invalid, with
byte-identical reason counts.**

| Artifact | SHA-256 |
|---|---|
| `rga-portable-assertions-20260822.json` (format v2, 554 entries) | `ba2ae5a69e8117d23366c7210b84f3c0921e0d358ab6760d8b2370e31f6dfc5b` |
| bundle_hash | `371b262f3b3138e0c19974ff1b5021a90de716638f4166ecd18dec8898a22d34` |
| `rga-portable-structural-holds-20260822.json` | `6e4166078bd5b4a1de527890fc4382124c491e45077d951555a6eddd4b396183` |

**Why, and it corrects a claim made when item 11 was scoped.** `archive_parse_cache.raw_result` is
not raw model output despite the name — it is an `InboundEmailImportService::encodeParseResult()`
payload, as that method's own docblock says ("the archive's raw-extraction cache is the one caller
that does"). Its keys are the encoded parse-result keys, and it was written before `ignored_lines`
existed, so a `--cache-only` replay decodes an empty list for all 554. Every exported entry carries
`"ignored_lines": []`. The code path is correct — the synthetic reproduction exercises a real parse
and passes — but the banked corpus has nothing to put through it.

**The data is recoverable, free.** `extraction_attempts[].final_annotations` survives in the same
cache with every line's `role`, `service_group_id` and `shared_service_group_ids` — exactly what
`CompileOosSemanticAnnotations::ignoredLines()` consumes. The two compile rules also partition the
document completely: a non-item line *inside* a service group becomes `service_evidence_line_ids`
(`evidenceLineIds()`), one outside every group becomes an ignored line. So a recompile closes the
coverage rule rather than merely reducing it.

**The precedent exists in the evaluation lane and not in the import lane.**
`oos:recompile-semantic-candidate-evidence` was built for exactly this move — its docblock reads
"Candidate artifacts retain `attempts[].final_annotations` precisely so this is possible" — but it
replays banked *candidate* artifacts, not the archive parse cache. The import lane has no
equivalent.

**Next step, and it is a decision, not a task.** Backfilling `ignored_lines` into banked evidence is
a mutation of the cache the whole lane is replayed from. It needs a maintainer ruling before it is
built, sized as: recompile `ignored_lines` from `final_annotations` into the stored parse payload
and the cached `raw_result`, re-export, re-enumerate. Zero model spend either way. Until then the
portable path stays at 151 of 554 and the §10 bundle item stays open.

### IC3 item 12 — the ignored-line backfill: 151 → 510 of 554 (2026-08-22)

`oos:backfill-archive-ignored-lines` recovers `ignored_lines` from the annotations the parse cache
already holds. **Zero model calls.** Run on `crockenhill_rehearsal_catalogued_v7`: 554 sources
examined, **544 backfilled, 1,823 ignored lines recovered, 10 skipped and named.**

| Portable preflight | Before | After |
|---|---|---|
| Valid | 151 | **510** |
| Invalid | 403 | **44** |

| Artifact | SHA-256 |
|---|---|
| `rga-portable-assertions-20260822b.json` (format v2, 554 entries) | `83f550f7769faad43c48c8cd0a55da5d4f7428440a3df4c46566b5f9ea8ec85c` |
| bundle_hash | `c3e4f57361e83c23c90719dc5a691cbcd2d8ce90d253c98bb879000bd3603211` |
| `rga-portable-structural-holds-20260822.json` | `14f36118fb2bac18c481f012af8fb522e7ce95ff0d8b7362b27d7a382341c86b` |

**A replay, not a repair.** No model output is rewritten: every annotation, patch and telemetry
field is left byte-identical, and the one field written is derived from those annotations by
`OosSemanticIgnoredLines` — the object the compiler itself now uses. The rule was *extracted*
rather than copied, on the §6 lesson recorded for the primary comparator: two copies agree on the
corpus that was checked and diverge later, and here the divergence would surface as the validator
blaming a document for an unclassified line. `OosSemanticIgnoredLines.php` is registered in
`OosParserSurfaceFingerprint::Files` — the rule moved out of a covered file, so the surface must
follow it or an edit to what counts as an ignored line stops moving the fingerprint.

Both halves are written — `parsing` for the export, `archive_parse_cache.raw_result` for the next
`--cache-only` replay — and `raw_result_hash` is recomputed over what was written. Leaving it would
have reproduced the `CanonicalJson` self-hash defect: a hash that no longer describes its payload
verifies nothing while looking like it does.

**The 10 skipped cost nothing.** Each has no single `selected` attempt because its parse failed
validation outright, and all 10 are already `held_for_review` with `no_eligible_plan` — a subset of
the standing 26 holds. They are skipped and enumerated rather than guessed at: inferring
"ignored = every line no item claimed" would satisfy the coverage rule with no evidence the model
ever saw those lines, which is a silent pass in place of an honest refusal.

**The 44 survivors, and none of them is a regression.** A new reason appears —
`An ignored-line reference does not exist in the source email` (11) — but **zero entries fail on it
alone**: all 11 already carried `Service evidence line N does not exist` or
`Item source line N does not exist`, so it is the same pre-existing line-numbering defect surfacing
on the ignored-line half rather than a new fault.

| Survivor | Count | What it is |
|---|---|---|
| Unclassified lines only | 12 | 10 are the skipped sources above; 2 (`2026-02-15`, `2026-07-05`) are annotation gaps — the model annotated nothing at all for a trailing block, correctly refused |
| Evening boundary absent | 16 | Genuine finding |
| Line reference does not exist | 13 + 11 + 6 | One numbering defect, three surfaces |
| `other` slot without special-service evidence | 3 | Ruled not-queued in item 10 |
| Duplicate service plan | 2 | Genuine finding |

Classes overlap; do not sum. These are findings about documents and need dispositioning, not
another format change.

Pint, PHPStan, the full suite (7,113 tests, 84,169 assertions) and Dusk (55 tests) are green.

### IC3 item 13 — disposition of the 44 portable structural holds (2026-08-22)

Walked all 44 entries in `rga-portable-structural-holds-20260822.json` against the full bundle
(`rga-portable-assertions-20260822b.json`) rather than the reason strings alone — one real defect
throws a dozen line-level reasons, so grouping by root cause is what produces the item-12 class
counts (12/16/2/3), not `reason_shape_counts`.

**`other` slot (3) — reversed same day: folded into the item-13 batch.** `2016-02-07`,
`2022-02-27`, `2022-04-14-maundy-thursday`. Item 10 declined a re-parse for these 3 alone; with a
10-source batch already queued for other reasons, the operator chose 2026-08-22 to add these 3 to
it rather than run a second standalone re-parse later.

**Duplicate service plan (2) — decided: targeted re-parse, not manual entry.**
- `2015-12-20`: one email covers four services across three dates (20th morning, 20th evening
  carol service, "Christmas morning" [25th], "Sunday 27th"), but the parser dated all four to
  2015-12-20 — a genuine date-resolution miss, producing two competing `morning:2015-12-20` plans.
- `2025-11-30`: the source itself resolves the ambiguity — *"I've got a couple of versions… My
  preferred order of service is: […] The alternate would be: […]"*, confirmed by a same-day reply,
  *"Your preferred order of service is fine."* The parser has no mechanism to read that reply.

**Missing evening service (2, from the "unclassified lines only" class) — decided: re-parse.**
`2026-02-15` and `2026-07-05` each have an entire second (evening, including communion) service
that the model never attempted to extract — not a formatting slip, a whole absent plan;
`plan_identities` confirms only the morning plan exists. `2026-07-05`'s "Sunday Evening
(communion)" block (9 lines: welcome/prayer/songs/reading/sermon/communion/closing prayer) has
zero annotation.

**Combined re-parse, run 2026-08-22: 13 sources, `--fresh-parse`, real spend.** Duplicate-plan
(`2015-12-20`, `2025-11-30`) + missing-evening (`2026-02-15`, `2026-07-05`) + evening-boundary
citation-gap (`2014-08-31-pm`, `2016-02-21`, `2016-06-12`, `2017-11-26`, `2026-08-09`) +
evening-boundary mislabel (`2022-04-17-am`) + `other`-slot (`2016-02-07`, `2022-02-27`,
`2022-04-14-maundy-thursday`, folded in same-day per the reversal above), against
`DB_DATABASE=crockenhill_rehearsal_catalogued_v7` with a freshly recomputed plan hash (not trusted
from a prior note). Dry-run reconciled all 13 first, at zero cost. **Result: 5 of 13 cleared
outright** (`2025-11-30`, `2016-02-07`, `2016-06-12`, `2017-11-26`, `2026-08-09`); **2 reproduced
their exact original defect on a second, independent model call** (`2015-12-20`'s date
misattribution, `2026-02-15`/`2026-07-05`'s missing evening service — the latter two never even
attempted an evening plan a second time, so this looks like a stable model blind spot for these
documents, not noise); **2 came back genuinely worse than before** (below); the remaining 2
(`2022-02-27`, `2022-04-14-maundy-thursday`, `2022-04-17-am`) stayed held on their original reason.

**Non-idempotency, observed rather than theoretical.** `2014-08-31-pm` (evening, confidence 0.75,
5 items) and `2016-02-21` (morning+evening, 1 item each, confidence 0.75) both came back from the
fresh model call as `confidence 0`, zero items, "no extractable items" — strictly worse than
before, exactly the risk item 10's 24/30 self-disagreement figure was warning about, now landed on
2 of 13 rather than staying hypothetical. Net count impact was zero (both were already held and
stayed held), but a human recovering these by hand later would have had materially less to work
from. Restored deliberately, per operator decision: not a repair in the item-12 sense of inventing
content, but restoring a strictly-better *prior* model output for the same document, which
`crockenhill_rehearsal_recovered`'s untouched `inbound_emails` copy still held (its
`raw_cache_key_hash` matched the live row exactly, confirming it is the same document under the
same parser version). **First restoration attempt was wrong**: that recovered copy predates item
12's `ignored_lines` backfill, so pasting it in verbatim reintroduced item 12's exact defect for
these 2 sources alone (10 and 2 lines respectively came back "not classified" that item 12 had
already resolved). Corrected by merging bundle `…b.json`'s already-backfilled `parse` fields (item
12's true output) onto the recovered copy's fuller schema, then recomputing `raw_result_hash`.

**Second defect, wider blast radius: staging wiped the cache for all 554 rows, not just these
two.** Running `--import-bundle` to preflight a freshly exported bundle does not just report — it
calls `OosArchiveAssertionBundle::stage()`, which replaces `processing_metadata` wholesale via
`fill()`. That is correct for its intended field (`parsing`), but it silently discarded
`archive_parse_cache` for every one of the 554 rows, not only the source being inspected — the next
`--cache-only` run would have found no reusable cache anywhere and, absent `--cache-only`, silently
re-parsed the entire corpus for real money. Caught before that happened. Repaired for all 554 in
one pass: `crockenhill_rehearsal_recovered`'s copy supplied the full binding schema as a template,
overlaid per-item with that item's correct `parse` fields from the most current bundle export, and
`raw_result_hash` recomputed. Verified with a full-corpus `--cache-only` run (zero cost, no errors)
before trusting it further. **Operational lesson for next use of this recipe: never run
`--import-bundle` (or `--apply-bundle`) as an "is it clean?" check — it stages/applies on success by
design; use `preflightPortable()` directly via tinker, which is pure, for a read-only preflight.**

**Regex fix (zero spend) and a free re-derivation bonus (also zero spend, unrelated to any of
today's work).** The `\bp\.\s*m\.?` fix (below) cleared `2021-12-19-carols`. Separately, exporting
a fresh bundle against current code — rather than reusing the morning's `…b.json` snapshot — picked
up an already-committed line-numbering fix (the `normaliseBody()` blank-line-collapse work,
committed before this session) that `…b.json` predated. That shifted cited `source_line_ids` back
into correct alignment for 12 more sources whose raw parse content never changed at all (confirmed
byte-identical `items`/`ignored_lines` between the stale and fresh bundle for every one of them,
and confirmed zero raw-content drift anywhere else in the 554-source corpus): `2015-06-21`,
`2015-09-27`, `2015-12-06-am`, `2016-02-21` (folding into the restoration above), `2016-04-24`,
`2016-06-26`, `2017-10-15`, `2018-03-11-am`, `2018-04-15-am`, `2019-10-06-details`,
`2021-04-11-details`, `2025-12-28-hymns`. Some of the "citation gap" diagnosis below may
overstate what the model actually got wrong — at least `2016-02-21`'s citation was fine all along;
it was the exported bundle's line numbering that was stale.

**Final state, verified 2026-08-22: 530 valid / 24 held (was 510 / 44).** Superseded once more by
the candlelight fix below, after the plan hash moved again for the `2018-07-01` scope correction
(item 14). Bundle `rga-portable-assertions-20260822f.json` (SHA-256
`cf3454551fc30adeb517c7a812e760992a8f1eace6931796f23800547031df44`, `bundle_hash`
`a35ed179b39c36d3600876e463d74834979acc940554fe2b1e6c47209bde211e`), holds
`rga-portable-structural-holds-20260822f.json` (SHA-256
`01cfbeb97ecc9cbc354d87be161b3703540826ed28f50f9738dc2791e3ebf6a4`). Full test suite green (7,115
tests, no failures, notices only), PHPStan clean, Pint clean. (Intermediate bundle `…e.json`,
528/554, is superseded — kept only as the checkpoint before item 14's manifest edits and the
candlelight fix.)

**Evening boundary absent (16 as first read, 11 remaining) — read all 16 against the code, not
just the reason string.**
`hasEveningServiceEvidence()` only scans the lines the extraction cited as evidence for that plan,
against `EVENING_SERVICE_PATTERN`; it never sees the rest of the document. That single mechanism
produces four genuinely different findings, not one:

1. **Regex bug, fixed 2026-08-22, zero spend.** `EVENING_SERVICE_PATTERN`'s `\bpm\b` cannot match
   "p.m." — the period breaks the word boundary the token relies on. `2021-12-19-carols`'s cited
   evidence line is literally `"6:00 p.m."`; the boundary was stated and the regex still missed it.
   Fixed in `OosEmailExtractionValidator::EVENING_SERVICE_PATTERN` (added a `\bp\.\s*m\.?` branch)
   with a regression test, `a_dotted_pm_time_satisfies_the_evening_boundary`. Pint/this test green.
   This is a validation-time fix — no re-parse needed, no model call — so re-running the portable
   preflight against the existing cache should clear this source without touching
   `archive_parse_cache` at all.
2. **Extraction citation gap, as first diagnosed (5) — turned out to be two different things once
   re-parsed and re-derived.** `2016-02-21` and `2017-11-26` **cleared for free**: their "missing"
   `"Evening:"` header citation was never actually missing — the exported bundle's cited
   `source_line_ids` were stale against a line-numbering fix already committed before this session,
   and a fresh export re-aligned them with no model call. `2016-06-12` and `2026-08-09` cleared via
   the paid re-parse (the model cited the header this time). `2014-08-31-pm` did **not** clear —
   its Subject-line signal ("hymns for tomorrow evening") still isn't cited on either parse, and
   restoring its better prior extraction (above) didn't touch that; it remains held on this reason
   alone.
3. **Contextual but not literal (2), decided and fixed 2026-08-22.** `2018-12-23-carols` and
   `2020-12-20-carols` cite "Carols by Candlelight" as evidence, which contains neither "evening"
   nor a PM token. **Maintainer ruling: stand-alone carol services are always evening at this
   church.** Added `\bcandlelight\b` to `EVENING_SERVICE_PATTERN`, not `\bcarols?\b` — checked the
   corpus first, and bare "carol" is not evening-specific: it is also used generically for "hymn"
   ("the first hymn (or carol)", `2021-08-29`) and as the given name "Carole" elsewhere. Every one
   of the corpus's 7 occurrences of "candlelight" names this one annual service, none in a
   morning context, so the narrower keyword carries no false-positive risk the wider one would
   have. Two regression tests lock in both halves: `candlelight_evidence_satisfies_the_evening_boundary`
   and `bare_carol_does_not_satisfy_the_evening_boundary` (the second guards against a future
   "helpful" broadening to `carols?`). Zero-cost, validation-time only — re-exporting the portable
   bundle cleared both sources with no other change: 528/554 → **530/554**, exactly the 2 expected,
   nothing else moved.
4. **Mislabelled service direction (1): `2022-04-17-am` — the source literally opens "Easter
   Sunday morning," and the only plan the parser produced for it is labelled `service: evening`.**
   Re-parsed; reproduced the identical mislabel a second time. Still held — this looks like a stable
   model behaviour on this specific document, not a one-off.
5. **No signal anywhere in the source (7): `2017-07-23-pm`, `2017-08-27-pm`, `2017-10-08-pm`,
   `2018-10-14-pm`, `2022-05-15-pm`, `2022-09-18-pm`, `2026-04-05-pm`.** The only evening marker
   for any of these is the curator-assigned item-key suffix; the validator is correctly refusing to
   assert a boundary the document never states. No fix exists here — these are legitimate holds,
   disposition is an operator call (trust the curator suffix out-of-band, or leave unimported), not
   engineering work. Untouched by the re-parse batch; still held.

Net for this class: 16 → 9 held. 3 cleared by regex fixes (the p.m. fix and the candlelight
ruling), 4 cleared by re-parse or free re-derivation, 2 stayed held on a stable mislabel/citation
gap that re-parsing did not fix, 7 have no fix and are accepted holds for the operator.

### IC3 item 14 — the 26 held Email sources: read against actual content, not category labels
(2026-08-22)

**This is a different pipeline from items 11–13.** The portable-bundle validator (item 13) checks
an exported bundle's structural shape before staging. This item is the canonical import's own
completeness check — `oos:generate-corpus-expectation` derives the expected staged set from the
approved manifest, `ChurchServiceCorpusExpectation::certify()` reconciles it against
`church_service_source_records`, and the gap is what `expectation_mismatch` blocks on (§9.4.6,
F1). A fresh `certify()` run (not the stale 2026-08-21 file) reproduced the documented **30
approved sources / 29 identities unstaged** exactly — that figure has not drifted. Of the 30, **2
now evaluate as `eligible`** (`2015-12-20`, `2025-11-30` — both cleared by item 13's re-parse) but
remain unstaged because nothing has actually run `--import`/`--apply` yet; **24 are genuinely
still held**, down from the plan's documented 26 for the same reason.

**Reading the 24 against their actual source text, not their hold-reason category, split them
three ways — a category label alone cannot tell these apart, and treating all 24 as one
`--accepted-holds` batch would have buried two different real problems under a document whose
purpose is to prove nothing was buried.**

1. **9 sources genuinely have nothing more to capture** (was 10 — `2019-10-13-songs` moved to
   class 3 below, see item 15). `2016-11-27-songs` (a parent's new-song suggestion, not an order),
   `2018-09-30` (the song list it references lives in an attachment not present in the captured
   plaintext), `2024-11-03` (PDF attachment lost; only one fact survives, already extensively
   documented in the manifest), `2016-06-26` (morning is sermon notes, evening was an external
   joint service at another venue), `2019-04-28-details` / `2019-10-06-details`
   (preacher-arrangement correspondence, confirmed no complete order exists),
   `2020-08-16` / `2018-09-16` / `2018-05-27` (notice-only, no hymn list for the date). Verified
   against the model's own annotations, not just the source text (item 15): each is a single,
   unanchored service group with **zero item-role annotations anywhere in the document** — there is
   nothing a compiler fix could recover here either. Draft accepted-holds reasons for these are in
   `storage/scratch/oos-accepted-holds-20260822-draft.json`.
2. **9 more read exactly like the sermon-outline-only sources already correctly tagged
   `content_scope: partial` elsewhere in the same manifest, but were still tagged `full`** — no
   hymns, no welcome, no liturgy, just a preacher's title/text/points for the news sheet.
   Re-curated 2026-08-22 (`oos-curation-partial-scope-fix-2026-08-22`, backed up to
   `oos-curation-manifest.json.bak-2026-08-22-pre-partial-scope-fix`, plan hash moved
   `5a6bef7c…` → `aa0920b5…` after the first 9, then → `45336353fa1f57972527500a3a4057da8a9ad5b74c06d5dd2e06be63ee54b2dd`
   after the tenth found below): `2015-06-21`, `2015-07-12`, `2015-07-26`, `2015-09-06`,
   `2015-09-27`, `2016-05-29`, `2016-08-07`, `2016-08-14`, `2017-12-10`. **Re-scoping did not clear
   the hold** — `content_scope: partial` doesn't waive the "at least one item" validator check,
   only what counts as a complete order. Dry-run confirmed 554 approved entries intact,
   full/partial moved 475/79 → 465/89 exactly as expected, nothing else touched. These 9 are also
   in the accepted-holds draft, now with a defensible reason the manifest actually supports.
3. **6 are genuine extraction misses, not holds to accept** (was 5 — `2019-10-13-songs` added,
   see item 15). Each has a *named* hymn sitting in the plaintext that should have produced at
   least one item and produced zero: `2018-01-07` ("NIP 'A new commandment'"), `2019-11-17-details`
   ("NIP 'All I have is Christ'", already correctly scoped `partial` — scope was never the problem
   here), `2018-02-04-details` ("875 'Begone, unbelief'"), `2018-07-01` ("697 'Above the voices'",
   re-curated to `partial` above for its evening-hymns-deferred half but still missing its own
   named morning hymn), `2019-10-13-songs` ("NIP 'On the cross he took what I deserved'" — first
   read as an undecided discussion with nothing to capture; a real, anchored item was sitting in
   the model's own annotations the whole time), and **`2018-08-12`** — a fully structured order
   (welcome, 2 numbered hymns, prayer, interview, reading, sermon, closing hymn, plus an evening
   fragment) that should have produced roughly 8 items and produced zero, the clearest case in the
   set. **Caught mid-draft, twice**: `2018-02-04-details` and `2018-07-01` were first read as
   already-clean partial sources and nearly left out of every group; `2019-10-13-songs` was in the
   accepted-holds draft until item 15's annotation check pulled it back out. Not in the
   accepted-holds draft — an operator ruling here would sign off on a real defect as correct
   behaviour. 4 of 6 fixed and recovered at zero cost in item 15; `2018-01-07`, `2018-02-04-details`
   and `2018-08-12`'s remaining defect stay open.

**Draft, not a ruling.** `storage/scratch/oos-accepted-holds-20260822-draft.json` (18 entries: the
9 from class 1 plus the 9 re-curated in class 2) is a starting point for the operator to review and
edit, not something to run through `--accepted-holds` unexamined — see item 10 for why an invented
hold defeats the whole point of the mechanism.

### IC3 item 15 — the compile-time all-or-nothing failure: two root causes, one fixed
(2026-08-22)

Reading item 14's 6 genuine extraction misses against the model's own cached annotations (not just
the source text) found the actual mechanism: `OosSemanticParserCandidate::parse()` fails the
**entire document** on any remaining validator finding after repair, discarding every service
group in it — even a fully clean, anchored group sitting right next to the broken one. Two
unrelated root causes produce that same empty-result shape.

**Cause A — a validator false positive (fixed, 4 of 6 recovered, zero spend).**
`OosSemanticAnnotationValidator::validateContinuation()` required a continuation's target line to
have `role: Item` or `Continuation`. A service-boundary line can also *be* the item
(`boundary_also_item`) — the single line naming both "Final hymn for the morning" and the hymn
itself — and a continuation wrapping that hymn's title onto the next physical line targets exactly
that boundary line. The check rejected it; the repairer's only recourse was stripping the boundary
role to satisfy the check, which deleted the group's one piece of boundary evidence and traded a
false `continuation_target_invalid` for a real `service_boundary_missing` — refused as "introduced
a new rule family," correctly, since that guard exists to stop exactly this kind of trade. **Fixed**
by recognising `boundary_also_item` as an accepted continuation-target condition, with two
regression tests (`a_continuation_may_target_a_boundary_line_that_is_also_the_item`,
`a_continuation_may_not_target_a_boundary_line_that_is_not_also_an_item` — the second locks in that
an *ordinary* boundary heading, without `boundary_also_item`, still correctly cannot be a
continuation target).

**A recompile tool was required to reach the already-cached corpus.** The live import path's
`--cache-only` replay reuses the frozen `service_plans`/`items` from `archive_parse_cache.raw_result`
verbatim — it never re-derives from the banked `extraction_attempts[0].initial_annotations`, unlike
`RecompileOosSemanticCandidateEvidence` (a different, evaluation-only tool this validator fix has no
access to). Built `oos:recompile-archive-parse-cache --item-key=<each>` — narrower than that
existing tool but the same principle: only the model-calling half of the pipeline
(`OosSemanticAnnotator`) is stubbed to replay the banked `initial_annotations`; validation, repair,
compilation and encoding all run as the live `OosEmailParserService::parse()` would, so the result is
exactly what a fresh parse would produce if the model's answer were unchanged. Refuses to write
anything for a source whose replay does not change, and `--dry-run` reports without writing.

Dry-run then apply against `2018-07-01`, `2018-08-12`, `2019-11-17-details`, `2019-10-13-songs`:
**3 of 4 fully cleared** — `2018-07-01`, `2019-11-17-details`, `2019-10-13-songs` now
`evidence_eligible`, 1 real recovered song item each (verified against the source text: 697 "Above
the voices", NIP "All I have is Christ", NIP "On the cross he took what I deserved" — all match).
`2018-08-12` improved (`continuation_target_invalid` cleared) but stayed held on a second,
unrelated `shared_boundary_role_invalid` finding this fix did not touch (Cause C below, since fixed). Full-corpus `--cache-only`
sanity check completed cleanly before and after (no errors, 554/554 reusable) — the write did not
corrupt anything else. Full suite green, Pint clean, PHPStan clean (842 files, +1 for the new
command).

**Cause B — FIXED 2026-08-23 (`cbc54b054`).** `2018-01-07` and `2018-02-04-details` are
two-group documents (one clean and anchored, one genuinely empty and unanchored —
`service_boundary_missing`, zero items, the deferred "evening hymns to follow" half) where the
all-or-nothing failure discards the clean group along with the broken one. Built as
`CompileOosSemanticAnnotations::salvageEmptyUnanchoredGroups()`: when a
group's only finding is `service_boundary_missing` and it has zero item annotations, and at least
one sibling group in the document validates cleanly, drop that group and compile the rest — checked
against the corpus first, and this is not a general salvage architecture: the 4 confirmed
`service_boundary_missing` sources elsewhere in the corpus (`2016-11-27-songs`, `2019-04-28-details`,
`2018-09-16`, `2018-05-27`) are single-group documents with nothing to salvage either way, so a
narrow rule covers every case found. The real gate proved to be
`OosSemanticParserCandidate::parse()`'s own early return, not `compile()`; both now enter recovery
through one shared door.

**Cause C — `shared_boundary_role_invalid`, FIXED 2026-08-23 (`d583576ea`).** `2018-08-12`'s third
defect is the model annotating a document-level header (`# Sunday 12 August 2018`) as
`notice_context` *shared* by both service groups. Sharing is a boundary mechanism, so the validator
is right to reject it; a paid repair call was the only thing that had ever cleared it.
`CompileOosSemanticAnnotations::normaliseNonBoundarySharedGroups()` now drops the IDs from exactly
the lines the validator named, moving the line from `evidenceLineIds()` to
`OosSemanticIgnoredLines` — the two halves of the coverage partition, so every line stays accounted
for. Sequenced with the Cause B salvage by a new `recoverFromFindings()` that re-validates between
passes, which is what recovers `2018-02-04-details` (three findings, three unrelated causes, no
single recovery could clear it).

**The full-corpus sweep is DONE (2026-08-23, zero cost, all 554).** It first appeared to surface a
26-source `shared_boundary_role_invalid` defect class; that reading was wrong and is corrected here.
`RecompileOosArchiveParseCacheCommand::replay()` passes `'repairer' => null` by design, so *any*
source whose findings were originally cleared by a repair call necessarily replays as a failure. All
26 were healthy in the cache (1–15 items, no final rule codes); only `2018-08-12` and
`2018-02-04-details` were genuinely held. The same is true of the 2 `item_semantics_incomplete`
sources (`2020-03-29`, `2025-07-27`): both carry a banked repair patch supplying an `item_kind` the
model had to *judge* (`benediction`, flagged `ambiguous_item_kind`), which no deterministic rule can
invent — correctly left to the repairer.

**A real hazard the sweep exposed, now closed.** The replay command wrote on hash inequality alone,
which every source trips because the hash covers `parser_surface_hash`. A non-`--dry-run` sweep
would have overwritten those 26 good caches with zero-item failures, destroying **299 items**;
only `--dry-run` discipline prevented it. `isRegression()` now refuses any write that loses items or
gains a rule code the cache does not carry, reports the row, and exits non-zero.
`--allow-regression` is the deliberate override.

**Applied to `crockenhill_rehearsal_catalogued_v7` 2026-08-23** (552 written, 2 refused by the new
guard, zero model spend). Email-lane held sources **7 → 4**: `2018-08-12` 0 → 15 items,
`2018-01-07` 0 → 1, `2018-02-04-details` 0 → 1. Corpus item total reconciles exactly, 5826 → 5843
(+17, fully accounted for by those three). The remaining four are exactly the single-group
`service_boundary_missing` sources named above as unsalvageable. Full suite green (7,157 tests),
Pint clean, PHPStan clean, Dusk green.

**Portable preflight re-checked 2026-08-23, zero spend: 530/554 → 540/554** (bundle regenerated as
`storage/scratch/rga-portable-assertions-20260823.json`). Holds 24 → 14, no new holds. Verified
zero-spend up front with `--evaluate --cache-only`, which refuses the run unless every one of the
554 sources has a reusable cached payload — the replay preserves `version` and
`raw_cache_key_hash`, so reusability is intact and a stale parser surface only warns.

**Operational trap found the hard way:** `oos:import-archive --import-bundle` is *not* a read-only
preflight. It calls `OosArchiveAssertionBundle::stage()`, which `firstOrNew()`s each
`inbound_emails` row and overwrites `processing_metadata` — running it against the *source*
environment wiped `archive_parse_cache` from all 554 v7 rows. It is built for a fresh target
environment in a portable transfer, not for reading a number. Recovered from the pre-apply snapshot
plus a re-run of the replay (zero spend). To read the figure without writing, call
`preflightPortable()` directly: it is pure (hashing plus `structuralReasons()`), and `stage()` is
the only writer.

### IC4 — Current-era evidence back-fill (drive-free; any time)

Production holds 3 services with 32 canonical items and zero source records (measured 2026-08-09;
decision: back-fill, never manufacture evidence). Recover each service's authoritative source
material, ingest through the normal source-revision path with provenance and hash, then run
`service-tracking:reproject-current-era` over the three-service corpus and audit item-level.
The B13 false-acceptance reversal semantics are already implemented (PR16).

### IC5 — Video and OpenLP completion (starts on the §2.4 trigger)

1. Reconcile the era-table denominators (§5) so release decisions use one accounting.
2. **OpenLP manifest and expectation producer done 2026-08-23.** The v2 curation fields
   (`item_key`, `source_kind`, `parse_decision`, `concatenation_decision`, `expected_item_count`,
   `decided_by`/`decision_rule_version`) are populated for all 614 includes of batch
   `openlp-curated-2026-08-23` against the local corpus, approved through `plan()` and
   `validateIncludesForDryRun()`, and applied. No symlink-only paths remain: the inventory is 655
   real files. Reading the 2016-2017 filename grammars needed a parser change (`177ccd4f1`),
   because `validateIncludesForDryRun()` throws unconditionally when the parsed date disagrees with
   the approved `resolved_date` — `manifest-authoritative` covers only the embedded-`.osj`
   mismatch, so an operator cannot assert a date the parser cannot derive.
   `OpenLpApprovedCorpus` now derives `batch_hash`, `input_hash` and the source key from the
   approved manifest alone, and `openlp:generate-corpus-expectation` produces the lane artifact
   consumed alongside Email by an `email,openlp` census.
3. Video worksheet → exception review → freeze: `historic-import:draft-video-curation` (cheap,
   repeatable), operator review of anomalous grades/grouping and written reasons for exclusions
   (ordinary includes are approved by the recorded rule version, not 462 individual clicks), then
   `historic-import:capture-video-curation` (hashes once at freeze; note
   `HistoricVideoCurationManifest::verifiedPath()` re-hashes the 1.0 TB corpus — ~3 h at
   87.7 MB/s — on every `plan()` call; schedule around it). The graded corroboration field and
   headerless-WebM packet-count duration recovery are already implemented and tested.
4. Before paid/bulk work, finish §0 slice 2. In particular, the command must accept and bind the
   immutable operation used by notification suppression, and bounded dispatch must resume against
   the **same full manifest**. Do not implement “checkpoint manifests”: different plan hashes create
   different staging roots and cannot be exported as one Bundle A.
5. **Before calibration, carry the historic corroboration grade into the source revision.** The
   manifest work item already has `manifest_corroboration`; include it in hash-bound processing
   metadata and in `LivestreamSourceAdapter`'s processing fingerprint. Update
   `ChurchServiceProjector::sourceProvesDimension()` so a historic Livestream proves song
   membership, count and order only when its grade is `full`. Historic `short_partial`,
   `fragmented`, `unknown`, and missing grades prove none of those dimensions: source absence is
   neutral, so they must neither finalise nor create a mismatch. Keep weekly Livestream behaviour
   unchanged. Required tests: full historic recording finalises matching Email evidence; a full
   disagreement raises all three facets; each non-full grade and a missing historic grade leaves the
   Email dimensions uncorroborated; the grade survives importer → processing log → source revision
   and changes the revision fingerprint/hash.
6. Run a representative calibration through the real weekly livestream job chain (short/long,
   codec families, single/concat/re-encode and era spread). Record p50/p95 wall time, failures,
   model cost and the accepted worker width. This is evidence for choosing the dispatch throttle,
   not a new performance framework.
7. Run the definitive corpus in bounded resumable passes through
   `sermons:import-historic-videos`. Exact completed runs belonging to the same manifest item are
   successful prior work; unrelated same-date runs, pending review, failures and changed source
   hashes remain explicit dispositions.
8. Stage results, regenerate membership and the proposal census, then export Bundle A per era as
   eras complete. The 223 services currently lacking any corroborating source may finalise only
   where a `full` recording agrees. Re-evaluate the 135 pre-video disagreements against the added
   evidence before declaring any surviving class irreducible. Include per-era accuracy and its
   evidence basis (derived-from-corroboration or hand-verified) in the round reports.

### IC6 — Hymn lane

The hymn workbook has two deliberately different roles. Its human-curated `Known Usage` data is
used early, before this package, as IC3 corroboration for Email song membership and HIR-D8
finalisation; it must not wait for the hymn mutation lane. What waits until after Email/OpenLP/video
convergence is applying hymn occurrences to the service graph: regenerate and hash-bind the hymn
reconciliation against the exact converged corpus (F60), disposition all 5,759 known-service rows including
cross-source duplicates, then apply through the landed operation-owned controls (F61) with the
proven no-op/reconciliation semantics (F62). The date-only lane stays quarantined until its signed
release. The workbook's `a`/`p`/`●` markers carry the service attribution; the contract tolerates
the known phantom row.

**New source snapshot (received 2026-08-16).**
`storage/scratch/Hymn database @ 16.08.2026.xlsx` (SHA-256
`0a6ac2043138c2e0abbf3fdcf314220d2d567a0ec812c3504d49e300213296c6`) contains the `2025` and
`2026` year sheets and supersedes `Hymn database @ 15.03.2026.xlsx` as their authority.
`HistoricHymnReconciliation::SheetAuthority` and its regression tests name the August snapshot so
all further IC3 corroboration uses it; earlier supplied snapshots remain hash-bound, superseded
sources. After the preceding convergence work, regenerate the apply artifact against that exact
rehearsal corpus and review its anomalies, duplicates, unresolved titles and fuzzy candidates;
do not carry forward the old `1,941 / 1,867 / 74` dry-run contract unless the new result reproduces
it and it is approved again. F60 must also close the current artifact gap: the retained builder
emits JSON, whereas `service-tracking:import-historic-song-usage-reports` consumes the derived XLSX
date-only lane and does not apply the JSON's known-service rows. Define and test the bound
reconciliation-to-apply artifact before either lane is imported through a new operation manifest.

### IC7 — Production rounds

The §7 procedure, per lane per round: Email+OpenLP evidence first (drive-free), then video/media
Bundle A rounds per era, then hymn. Each round is RG-B-gated and ends with the audit report. No
round publishes anything.

### IC8 — Retirement (at historic closeout)

As the archived WP10: record every one-shot's deletion trigger; remove spent commands, temporary
compatibility writers/readers and the historic-only guard/ledger surface in R8/R12 ownership
order; contract schema in a later release than reader removal; retain the exact auditors, portable
contract tests, §3.2 canaries and public read-side tests permanently; archive this plan and
reconcile the plans index. The retirement surface is *larger* than WP10 anticipated — it now also
includes the ingress-freeze tooling and one-shot ceremony left unused by REV-D1 — and that is the
point.

## 7. Production round procedure

Replaces WP9 and the archived runbook (`docs/operations/r8-data-convergence-runbook.md` carries a
supersession banner; do not execute it).

### 7.1 Preconditions (RG-B)

- The lane's RG-A staging evidence exists for the same parser/code version.
- Approved manifest + plan hash for exactly this round's corpus subset.
- Code deployed; four quality checks green on that commit.
- Pre-round production database backup taken and verified restorable (the spatie backup plus an
  explicit round dump; verification = a test restore of the dump, not a size check).
- The FR-D7 log-only operation record exists (architecture plan AM3 Delivery 1 — rotating logs
  retained long enough to cover a round and its review). FR-D7 accepted logs *instead of* Sentry;
  what it accepted must actually be running before the first round.
- `HistoricImportProductionGuard` presented with the named round approval.
- Optional: brief `import:ingress block` if the round runs at a time weekly work could interleave
  (Sundays); otherwise unnecessary — there is no freeze requirement.

### 7.2 Apply

Run the lane's apply command with the round's operation id. Per-service transaction, lock and
classification as landed. First hard error stops the round (residue is fine; errors are not);
fix forward and re-run — re-runs classify completed services `already_present`.

### 7.3 Round evidence pack (replaces exact closeout)

The earlier plan promised one aggregate command that does not exist. Do not build a general audit
framework for a one-off import. Assemble and hash the existing lane report, manifest expectation,
combined membership/census, asset audit (for media), Scripture settlement, operation notification/
nested-job ledger and cost/duration output. Add a short cover record naming the round, commit,
operation id, manifest/plan hashes, backup receipt and every non-zero residue. Store it in the
private batch ledger and have the maintainer review it. An unexplained identity, changed input hash,
unowned asset or external historic notification is a defect; an enumerated hold/pending proposal is
reported recoverable residue.

## 8. Release policy

1. **Unit: era slice** (§5 table). Services release by moving `church.services.public_from`
   backwards and/or releasing the era's sermon batch via `historic-import:release-batch`; media
   below the publication threshold is withheld from listing via the existing exposure inputs
   (`SermonVideoQualityStatus`, `SermonVideoVisibilityOverride` in `SermonExposurePolicy`) while
   the service page stays complete.
2. **Evidence per era before release (RG-C):** accuracy at the FR-D4 floors on IC3's measure,
   stated with its evidence basis; the 2019→2020-03-21 sub-era is hand-verified-or-unpublished by
   construction. Spot-check the acceptance journeys: browse year/date/service/occasion; find by
   preacher/scripture/song; planned vs observed items distinguished honestly; no misleading auth
   bounce; honest 404/private responses; stable URLs; feeds/sitemap/caches correct; no
   notification/analytics flood. Editorial QA for titles/slugs/occasions/unknown speakers.
3. **Every release is a controlled batch** with owner and observation, never an import side
   effect (carried §F.6). Corrections and unpublishing use the ordinary application tools without
   deleting provenance.
4. **Deferred policy, carried unresolved:** editorial, copyright/CCLI, consent, personal-data and
   safeguarding policy for publishing a decade of services must be decided by the maintainer/church
   before large releases. Nothing in this plan clears it.

## 9. Review-load design (carried)

The archived §9.4 automate-first loop is unchanged and now serves staging rounds: run the proposal
census per round (`services:proposal-census --gate` semantics with corpus-completeness evidence);
classify proposal *classes*; automate a class away where a deterministic rule exists (rule-level
dispositions with `decision_rule_version`); hand humans only irreducible ambiguity. Evidence-tier
imports (IC1) enter the census, not the weekly attention inbox. The weekly inbox keeps its
narrower `needs_review` semantics on purpose.

**Nonterminal evidence dependencies (2026-08-25).** The persisted class-review statuses
`automated` and `irreducible` are terminal. A class waiting for a named, approved later source is
neither. Record that dependency in this plan and leave the class unclassified until the source is
staged and the census is regenerated. Do not add an `awaiting_evidence` status merely to advance
sequencing: if such a status is later added for UI clarity, the gate must list it separately,
require a reason naming the dependency, and remain red while any such class exists. An unknown
status currently falls through the gate's terminal accounting, so adding the constant alone would
be unsafe. REV-D4 independently permits the named next source to stage while this gate is red.

## 10. Open decisions and operator inputs

Only these rows are open. The larger table below is the detailed investigation/decision journal;
some operator reminders are repeated there, but §0 and this short table control sequencing.

**Correction to the struck 2026-08-24 RG-A row below:** its final sentence claiming 207 classes and
~63% reducible content shapes is false and retracted. The corrected census is 398 proposals in 21
dimension-labelled classes, with one proposal per service. No liturgical-framing or asset exemption
exists. The 223-service class awaits a corroborating source; the 135 current disagreements and the
small tail are re-censused after video before any terminal classification. The row remains only to
preserve the sequence of the investigation.

| Open item | Owner / next action | Blocks |
|---|---|---|
| Carry historic-video corroboration grade into the Livestream source revision and fail closed for every non-full/missing historic grade | Coding agent, focused importer/adapter/projector tests specified in IC5 item 5 | Any paid video calibration or definitive video pass |
| Re-census the 223 awaiting-video services and 135 current disagreements after full-grade video evidence is staged | Coding agent after IC5 definitive staging; operator handles only the surviving irreducible residue | Final convergence RG-A and release evidence, not the REV-D4 start of IC5 |
| ~~Re-run the combined clean-rehearsal RG-A against the current expectation and inspect only repeated/high-volume proposal classes it surfaces~~ | — | **DONE 2026-08-24** (second combined run, `crockenhill_rehearsal_rga_20260824d`, zero model spend). This row's "regenerate the expectation first: 20 → 19" instruction was already stale when written — `2016-05-01-pm` was excluded the same day, taking the draft to 18 — and the run then took it to **20** by adding two genuine holds. Do not read a hold count off this row; the current expectation is `oos-corpus-expectation-20260824e.json` at **553 approved sources / 539 identities / 20 holds**. Both lanes reconcile with 0 unstaged approved sources and 0 unstaged identities. The proposal-class review is done (207 classes / 398 proposals, ~63% reducible to two shared shapes); see the §0 slice-1 row for the full result. |
| Bind portable Email, OpenLP and video mutations to exact round authority; make video operation-bound and resumable | Coding agent, focused command/integration tests | First production round and definitive video |
| Re-run current-era evidence coverage and recover any still-missing sources | Operator supplies source material; agent uses ordinary ingest/reprojection | IC4 only |
| Review video worksheet exceptions and freeze manifest | Operator | Definitive video |
| Approve each era release and §8.4 publication policy | Maintainer/church | RG-C only |

| Item | Blocks | State |
|---|---|---|
| ~~**Song identity for HIR-D8 corroboration**~~ | — | **DECIDED 2026-08-21: resolve in `ChurchServiceAssertionNormalizer`** (§2.5), invariant 4 amended (§3.2), implementation queued as IC3 item 8. Comparison-time resolution was considered and rejected: it would have auto-applied 264 services' duplicated song items unattended |
| ~~**Portable bundle ignored-line provenance**~~ | — | **CODE DONE 2026-08-22** (IC3 item 11): `ignored_lines` persisted, bundle format v2, both tests retained. 373 of the 403 refusals were this defect alone. What remains is regenerating the bundle, which folds into the item-10 `entryAuthorityHash()` replay below |
| ~~Regenerate the portable bundle on the moved `entryAuthorityHash()` and format v2~~ | — | **DONE 2026-08-22**, v7 replay, zero model calls. The bundle is current; the refusals are not fixed by it |
| ~~Rule on backfilling `ignored_lines` into the banked parse cache~~ | — | **DONE 2026-08-22** (IC3 item 12): approved and run, 544 backfilled at zero model spend, portable preflight 151 → 510 of 554 |
| ~~**Disposition the 44 remaining portable structural holds**~~ | — | **DONE 2026-08-22** (IC3 item 13): 13-source `--fresh-parse` run (5 cleared, 2 reproduced their original defect, 2 came back worse and were restored, 3 unchanged) + zero-spend regex fix + a zero-spend re-derivation bonus (stale line-numbering in the morning's bundle snapshot, unrelated to today's work) together took the portable preflight from 510/554 to **528/554**. 26 remain held: 7 "no evening signal at all" + 2 "candlelight" judgement call + 1 Subject-line citation gap + 1 stable mislabel + 2 `other`-slot + 1 duplicate-plan (reproduced twice) + 2 missing-evening (reproduced twice) + 10 overlapping the standing 26 held sources. Full suite green (7,115 tests), PHPStan clean |
| ~~Rule on "candlelight" as evening evidence~~ | — | **DECIDED AND FIXED 2026-08-22** (IC3 item 13): stand-alone carol services are always evening (maintainer ruling). `\bcandlelight\b` added to `EVENING_SERVICE_PATTERN`, not `\bcarols?\b` (corpus-checked false-positive risk: "carol" is also generic for "hymn" and the name "Carole"). Cleared `2018-12-23-carols` and `2020-12-20-carols` at zero cost: 528/554 → 530/554 |
| ~~**Enumerate the 14-source portable residual**~~ (9 evening-boundary, 2 `other`-slot, 1 duplicate-plan, 2 coverage) | — | Re-parsing again is unlikely to change these (IC3 items 13–14). Portable preflight 530/554 → 540/554, holds 24 → 14, with 0 new holds (2026-08-23). **DONE 2026-08-24** (`cfe190069`): the portable-subset apply now proves the required behaviour directly — `bundle_apply_imports_valid_entries_and_retains_structurally_invalid_entries_for_review` stages every independently valid entry (exit 0) and leaves invalid/held entries `Pending` for review rather than failing the whole batch. Full suite (7,166 tests) and PHPStan green. The 2 remaining coverage holds (`2026-02-15`, `2026-07-05`) were held identically before this work |
| ~~Read the 26 held semantic Email sources against actual content~~ | — | **DONE 2026-08-22** (IC3 item 14): 24 genuinely held (2 cleared by item 13's re-parse but remain unstaged). 10 have nothing more to capture, 9 re-curated `full`→`partial` in the manifest (didn't clear the hold, but now correctly explained), 5 are genuine extraction misses masquerading as holds — not ruling material |
| ~~Review and run the accepted-holds draft~~ (`storage/scratch/oos-accepted-holds-20260822-draft.json`, 18 entries) via `--accepted-holds=` | — | **DONE 2026-08-24**: operator approved all 18 entries as written (9 "no OoS content exists", 9 "sermon-outline-only, no hymns" — the latter all carrying the `content_scope` re-scope caveat from IC3 item 14). `oos:generate-corpus-expectation` re-run with the file; 554 approved sources, 540 identities, 18 holds folded in |
| ~~Investigate the extraction misses~~ | — | **DONE 2026-08-22** (IC3 item 15): root cause found (compile-time all-or-nothing failure, two unrelated causes). Cause A fixed at zero spend via a new recompile tool — 4 of 6 recovered (`2018-07-01`, `2019-11-17-details`, `2019-10-13-songs` fully cleared; `2018-08-12` improved but has a second defect). Cause B (`2018-01-07`, `2018-02-04-details`) scoped, not yet built. Prior ~14, RG0A's 19 and the recovered run's 41 are all superseded by the 30/24 figures above |
| ~~Build the Cause B compiler fix~~ | — | **DONE 2026-08-23** (`cbc54b054`): `salvageEmptyUnanchoredGroups()`. The real gate was `OosSemanticParserCandidate::parse()`'s own early return, not `compile()` — fixing only the compiler changed nothing in production. Both beneficiaries recovered |
| ~~Investigate `2018-08-12`'s `shared_boundary_role_invalid` finding~~ | — | **DONE AND FIXED 2026-08-23** (`d583576ea`): a document-level date header annotated `notice_context` *shared* by both groups. `normaliseNonBoundarySharedGroups()` drops the illegal IDs so the line falls through to `OosSemanticIgnoredLines`. Cleared 0 → 15 items. Chained with Cause B via `recoverFromFindings()`, which also recovered `2018-02-04-details` |
| ~~Sweep the remaining ~530 cached sources for the Cause A/B signature~~ | — | **DONE 2026-08-23**, all 554, zero cost. 6 was *not* the ceiling but the excess was measurement error: the replay omits the repairer, so every repair-cleared source reads as a failure. 26 apparent `shared_boundary_role_invalid` + 2 `item_semantics_incomplete` were all healthy in cache. Exposed a real hazard — the command wrote on hash inequality alone and a non-dry-run sweep would have destroyed 299 items; now guarded by `isRegression()` |
| ~~**Second services the manifest could not declare**~~ | — | **DECIDED 2026-08-22** (IC3 item 10): schema v2 `additional_services`, 137 entries re-curated, identity precision 77.6% → 99.49%. Plural `resolved_service` was considered and rejected: it is half the source key, so widening it would re-identify every staged revision |
| ~~Three parser slot fixes for `other`-slotted plans~~ | — | **NOT QUEUED 2026-08-22 in item 10, REVERSED same day in item 13**: item 10 declined a re-parse for these 3 alone (non-idempotent, 24/30 source-exact self-disagreement, to fix 3 of 554). Operator chose 2026-08-22 to fold `2016-02-07`, `2022-02-27` and `2022-04-14-maundy-thursday` into the item-13 batch re-parse instead — the marginal cost of 3 more sources in a run that is re-parsing 10 others anyway is small, which is a different calculation than a standalone re-parse for 3 |
| ~~Apply the recovered replays to the rehearsal cache~~ | — | **DONE 2026-08-23**: 552 written, 2 refused by the new regression guard, zero model spend. Email-lane holds **7 → 4**; corpus item total 5826 → 5843, reconciled exactly |
| ~~Retry `2023-01-01`, `2015-12-13` and `2024-10-06`~~ | — | **DONE 2026-08-24**, `--fresh-parse --item-key=` against `crockenhill_rehearsal` (the DB of record, not local dev), 3 sources, real spend, zero surprises. `2015-12-13` **cleared**: re-ran clean, 12 items, 4/4 songs resolved, `eligible` — its earlier "failed" disposition was purely the prior run's rate limit. `2023-01-01` **reproduced its exact original defect**: `morning:2022-01-01` again, content still 5/5 resolved — confirms the "stable model blind spot" pattern rather than noise, exactly as `2015-12-20` did in item 13. `2024-10-06` **reproduced the same defect class**: `morning:2024-10-04` against expected `2024-10-06`, 13 items but only 4/5 songs resolved (a second, smaller content gap riding along with the date miss). Both date-miss sources are now confirmed, not merely suspected, and ready for the accepted-holds draft pending operator sign-off (§10) |
| ~~`2023-01-01`'s date miss — found the root cause and fixed it~~ | — | **FIXED 2026-08-24** (`app/Services/Email/OosServiceDateResolver.php`). Not a model blind spot after all — the date comes from a deterministic PHP resolver over model-selected evidence lines, and this specific miss was traced to isolation and reproduced with no model call involved. Subject *"Hymns for **1st January** am"* matches the resolver's yearless day-month regex; with no year stated it fell back to `contextYear()`, which returned the *received* date's calendar year (2022, the email was sent 2022-12-31) — a full year early, because the code never accounted for a yearless month name crossing the December→January boundary into next year. Fixed: `contextYear()` now takes the parsed month and rolls the year forward when that month is chronologically before the received month. Reproduced red with a unit test first (`2022-01-01` observed, `2023-01-01` expected), then green after the fix; a same-year case (`10th October` received in the same October) added alongside to guard the non-rollover path. `tests/Unit/Services/Email/OosServiceDateResolverTest.php` (14 tests), full Email suite (364 tests, 1317 assertions), Pint, PHPStan all green. Verified end-to-end against the live pipeline (not just the unit test): a fresh `--fresh-parse --item-key=2023-01-01` now resolves `morning:2023-01-01` and the source moves from `held_for_review` to `evidence_eligible`. ~~**`2024-10-06` was investigated alongside but is NOT the same bug** — replaying the resolver by hand against its cached evidence lines gave a *different* wrong date (`2024-10-05`, from an explicit date in a quoted reply) than what the live pipeline twice produced (`2024-10-04`), meaning the model's per-run choice of evidence lines isn't stable for this source (the same non-determinism item 10 already measured at 24/30 self-disagreement) — no single deterministic cause to fix, so it stays open in §10 for an operator ruling, not bundled into this fix~~ **This ruling was WRONG and is superseded by the row below.** The replay was reading the wrong field, and `2024-10-06` does have a single deterministic cause |
| ~~Add `2024-10-06` and `2016-05-01-pm` to the accepted-holds draft and re-run~~ | — | **DONE 2026-08-24.** `2024-10-06` added with the traced `boundary_line_ids` mechanism as its reason (see the item above — not a generic date-mismatch label); `2016-05-01-pm` added as class 1, same shape as `2016-11-27-songs`. `oos:generate-corpus-expectation --accepted-holds=` re-run against `oos-curation-manifest.json`: **554 approved sources, 540 identities, 20 accepted holds** (up from 18), written to `storage/scratch/oos-corpus-expectation-20260824b.json` (gitignored scratch, not committed — a fresh filename rather than overwriting the 18-entry artifact from earlier the same day). Confirmed via direct code trace before running, not assumed: `GenerateOosCorpusExpectationCommand` and everything it touches (`OosCurationManifest`, `OosApprovedCorpus`, `ChurchServiceCorpusExpectation`, `EmailSourceAdapter`) import no OpenAI/HTTP client and never instantiate `OosArchiveEvaluator` (the one class in this area that does make model calls) — it only hash-reconciles local files against the manifest and folds the accepted-holds JSON in before hashing. Zero model spend, confirmed both by the trace and by the run itself completing in under a second with no log activity |
| ~~Rule on the 5 named unstaged Email sources~~ (`2015-06-14`, `2016-05-01`, `2020-01-05`, `2015-12-20`, `2023-01-01`) | — | **RULED 2026-08-24, then CORRECTED the same day — read the correction first.** The original ruling below concluded that 3 of the 5 (`2015-06-14`, `2016-05-01`, `2020-01-05`) plus `2015-12-20` were "already `eligible`". **That was wrong.** It was measured with a scoped `--evaluate --date=` against the **local dev DB**, which this plan elsewhere records as not the DB of record; the second combined clean rehearsal found all five still unstaged. The true disposition, from `crockenhill_rehearsal_rga_20260824d`: **three were stale-cache defects** — `2023-01-01` (cached date `2022-01-01`), `2015-12-20` (cached duplicate `morning:` plan invalidating all four plans, including a 9-carol evening carol service) and `2020-01-05` (empty morning) — all cleared by a single-item `--fresh-parse`, because a **code** fix cannot invalidate a cached extraction (see the cache row below). **Two are genuine holds**, reproducing across cached and fresh draws and operator-approved 2026-08-24: `2015-06-14` and `2016-05-01`, both morning, both sermon-outline-only with no order of service — `2016-05-01` says so in the body ("Sunday morning order of service to follow soon"; no follow-up exists in the corpus). `2015-06-14` also **regressed** on its fresh parse (both plans empty, losing the evening sermon item) and was restored from `…rga_20260824`, then re-verified with `--cache-only`. Accepted-holds file 18 → **20**. The superseded original reasoning is retained below for provenance. ~~A scoped `--evaluate --date=` re-check against the current manifest (not the stale note) found the finding text had already gone stale: **3 of the 5 are now `eligible`, not held** — `2015-06-14`, `2016-05-01`, `2020-01-05` all stage cleanly (later commits, chiefly item 10's `additional_services` v2 and item 13's disposition work, cleared them after the note was written). `2015-12-20` is likewise `eligible` (item 13's re-parse held, contra that item's own "reproduced its defect" line — current live state is authoritative over either doc claim). **Only 2 are genuinely held**, and one of the 5 named dates (`2016-05-01`) turned out to hide a *second*, separate source under a different `item_key`: 1) **`2023-01-01`** ("Hymns for 1st January am") — 5 named hymns, all 5 resolved against the song catalogue; held solely because the model attached the plan to `morning:2022-01-01` instead of `morning:2023-01-01` (a year-arithmetic slip on "tomorrow morning" sent 2022-12-31). Same defect class as item 13's `2015-12-20` date-resolution miss, which reproduced on a fresh model call ("a stable model blind spot"). **Ruling: try one cheap `--fresh-parse --item-key=2023-01-01` retry; if it reproduces, accept as a hold with the date-arithmetic reason recorded** — not worth a corpus-wide re-parse for one source. 2) **`2016-05-01-pm`** — a second, separate email for the same date ("I'm planning to have 'A Debtor to Mercy Alone' on Sunday evening... The music is attached"), correctly extracting zero items: a leader proposing a hymn/tune change, not a definitive order, same shape as item 14's `2016-11-27-songs` ("a parent's new-song suggestion, not an order"). The date's real evening content is already fully captured by the separate `2016-05-01` source (hymn 844, staged and eligible). **Ruling: accept as a hold, class 1.** A follow-up full-corpus `--evaluate` (554 sources, run against the local dev DB, not the curated rehearsal DB) surfaced two more items in the same vein, not yet ruled: a second date-mismatch (`2024-10-06` detected as `2024-10-04`) and a transient rate-limit failure on `2015-12-13` that is not a corpus defect and just needs a re-run. It also confirmed the local dev DB's cache lacks item 13's hand-restorations (e.g. `2016-02-21` reproduces its known non-idempotent "worse" draw locally) — the rehearsal DB remains the DB of record for the corpus's true current state, not local dev. **That run cost ~532 live OpenAI calls, not the zero the 2026-08-16 stall memory promised** — ~~`parser_surface_commit` is part of the semantic-annotation cache key, and it had just moved (today's `additional_services` v2 landing), invalidating annotations corpus-wide even though the raw parse cache was untouched~~ **this attribution was WRONG (corrected 2026-08-24, see the `2024-10-06` row below).** `parser_surface_commit` is not a cache key and invalidates nothing: `OosArchiveParseCacheBinding::reusableRawPayload()` gates reuse on `version` and `raw_cache_key_hash` alone, and that key is `{input_hash, parser_version, received_date}` with no commit in it. The field's only consumer is `warnWhenParserSurfaceIsStale()`, which emits one `Log::warning` and returns — the class docblock states outright that "nothing is reused or discarded on account of it". The probable real cause is finding 1 of this same item: `approved_source_input_hash_mismatch` fired for **531/554** sources because the live `--import` path never threaded the manifest's verbatim sha256 into `EmailSourceAdapter::adapt()`, and `input_hash` *is* in the raw cache key, so exactly those rows missed the cache. 531 against ~532 is the match. That bug is fixed (`2e29d50d1`), so the mechanism that actually charged for this run is closed~~ |
| **The parse cache masks parser code fixes, and nothing records when an extraction was made** | Coding agent, after any parser code fix | **FOUND 2026-08-24.** `OosArchiveParseCacheBinding::rawCacheKey()` is `{input_hash, parser_version, received_date}` — no commit, no fix marker. A **code** fix to date resolution or plan splitting therefore invalidates nothing: cached rows keep serving the buggy extraction until `parser_version` moves or `--fresh-parse` is passed. Two confirmed instances this day, both invisible under `--cache-only`: `2023-01-01` served a `2022-01-01` date although `06ee54191`/`e067f8e57` were already committed, and `2015-12-20` served a duplicate-`morning:` collapse. **Do not use `parser_surface_commit` to find rows predating a fix** — `binding()` is rewritten on every *resolve*, so after a 554-source import every row reports the same commit and the same timestamp; `extraction_reused` (550/554) says a payload was carried forward but not from when. Nothing in the binding dates the raw extraction. **Detect exposure behaviourally from the run report** rather than re-parsing (a full re-parse is ~553 paid calls): scan for `date.matches == false`, a repeated `plan_key` within one entry, and a plan whose `plan_key` date differs from the entry's expected date. That scan over the 2026-08-24 report found exactly one instance of each, both since fixed. A stale extraction producing plausible output with no flag stays invisible to it. Fresh parses are also **non-idempotent** — `2015-06-14` came back worse and had to be restored. |
| **Song-link residue is catalogue curation, not matcher work** | Operator (catalogue), not slice 1 | **MEASURED 2026-08-24, zero model spend.** Song-link hit rate sits at 93.7% and the residue was assumed to be a fuzzy-matching gap. It is not. All **138** distinct unmatched titles were re-run through `SongTitleResolver` and traced against its real 4,553-entry candidate set (alternate titles and first lines, not just the 1,159 song titles): **121** fall below the fuzzy threshold or are too short a probe, **15** tie between genuinely different songs and are correctly rejected, and only **2** are recoverable. The recoverable ceiling from any matcher change is ~2-4 titles of 138. The resolver already strips email decoration, extracts quoted runs, scores containment at 0.98 and matches hymnbook-absent markers (669 successful `hymnbook_absent` matches in the same run); the probes it builds are correct — `NIP 'Come O found of every blessing'` yields exactly `come o found of every blessing`. **Operator ruling 2026-08-24:** the unmatched titles are *alternative titles people used for songs that are in the catalogue* (e.g. "What a faithful God have I" against the catalogued `Faithful God #252`), so this is catalogue curation. 798 of 1,159 songs already carry an `alternate_title`, so the mechanism exists and only the specific alternates are missing. **It is not straightforward**: `alternate_title` is upserted from the OpenLP row on every sync (`SongCatalogSyncService.php:312`), so an app-side alternate is overwritten by the next `service-tracking:sync-songs` — the curation has to happen in OpenLP itself, or the sync needs a merge rule that preserves locally-added alternates. A further **22** unmatched titles are placeholders naming no song at all (`*Song*`, `Closing hymn - Mark to choose`, `Carol – chosen by Gareth`) and are permanently unmatchable by design. **Separate real defect found**: the fuzzy ambiguity margin rejects a match when both top candidates are the *same song duplicated in the catalogue* — `Come O Fount Of Every Blessing (plus extra verse)` #189 against `Come O Fount Of Every Blessing #894` #1112 tie at 0.967, and `May the grace of Christ, our Saviour` #624 against `#717` #1113 tie at 0.986; two `<copy>`-suffixed rows exist too. The sync merged only 13 duplicate groups from 1,173 source songs. Worth fixing for song identity generally, but it recovers 2 titles here, not 39. **Do not reopen this as a matcher task.** |
| ~~`2024-10-06`'s date miss — ruling reversed, root cause found and fixed~~ | — | **FIXED 2026-08-24** (`app/Services/Email/OosServiceDateResolver.php`), at **zero model spend**. The "evidence-line non-determinism" ruling two rows above was wrong, and so was the hand-replay it rested on. **Why the replay lied:** `CompileOosSemanticAnnotations` resolves a plan's date from `$service->boundaryLineIds`, but the only line list retained in the archive cache is `service_evidence_line_ids`, which `evidenceLineIds()` builds as that list *plus* every other non-item annotation line for the group. It is a **superset**, so replaying through it feeds the resolver lines the real call never saw. For this source the true boundary list is `[3]`; the superset is `[3, 24, 26]`, and line 24 is a quoted follow-up header containing the literal string `2024-10-05` — which the ISO branch, tried first, matches instantly. Replaying with the *true* `boundaryLineIds` (recovered from the retained `extraction_attempts[0].final_annotations`) reproduces the live pipeline's `2024-10-04` **exactly**. There is no non-determinism here: corpus-wide, a true-boundary replay of all 712 plans reproduces the stored date for **693 (97.3%)**, and every one of the 19 residual divergences is a plan whose date `OosEmailParserService::validateDatePlausibility()` nulls *downstream* of the resolver (all `*:unknown` plan keys), not a resolver disagreement. **The actual defect:** `relativeDate()` tested `\btomorrow\b` first and returned `received + 1 day` unconditionally, matching the word anywhere in the flattened evidence. Line 3 reads *"Here is the running order for **Sunday morning**. I'll send the PPT … **tomorrow** or Saturday"* — the "tomorrow" is about the PowerPoint, not the service — so a Thursday-received email resolved to Friday `2024-10-04`. The `sundayOnOrAfter()` fallback already at the bottom of the same ladder gives the correct `2024-10-06`. **Fix:** honour `tomorrow` only where `received + 1` actually lands on a Sunday, otherwise fall through. Reproduced red first (`2024-10-04` observed, `2024-10-06` expected), green after; a Saturday case added to pin the non-regression path. **Blast radius measured, not assumed:** 36 of 712 plans have "tomorrow" in their evidence, and in **35 the email arrived on a Saturday**, so `received + 1` was already the Sunday — those are untouched. A full offline differential of both of today's resolver fixes against the pre-fix code moves exactly **2 plans of 712**: `2023-01-01` (`2022-01-01` → `2023-01-01`) and this one (`2024-10-04` → `2024-10-06`). Zero collateral. **Consequence for §10:** `2024-10-06` should come *off* the accepted-holds draft — it is a fixed defect, not an accepted hold — taking the draft from 20 back to **19** and requiring `oos:generate-corpus-expectation --accepted-holds=` to be re-run. ~~**Operator action required; not done here.**~~ **DONE, see the row after `low_confidence` below.** **Replay-tooling trap worth recording:** the superset/`boundaryLineIds` mismatch is not a production bug — the live path always passes the right field — but anyone replaying from `service_evidence_line_ids` will keep rediscovering phantom divergences, exactly as happened here. Replay from `final_annotations` instead |
| ~~Check whether non-Sunday (Christmas/Holy Week) services are being systematically held~~ | — | **INVESTIGATED 2026-08-24, NOT A DEFECT** — zero model spend, rehearsal DB read-only. The concern arose from the `2024-10-06` replay above: `validateDatePlausibility()` (`OosEmailParserService.php:793-795`) marks **any** non-Sunday date implausible unconditionally, and 17 of the 19 residual replay divergences were Christmas-shaped plans whose date it had nulled. **The inference was wrong**: that null lands in the *raw* parse only, and `OosArchiveIdentityResolver` then repairs the date from the manifest's ground truth. Final state is correctly dated in every case (e.g. email 146 → `morning:2018-12-25`). Reading a pre-identity-resolution artifact and inferring a post-resolution consequence is the trap here; check `processing_metadata.parsing`, not `archive_parse_cache.raw_result`. **The 13 non-Sunday plans are all genuine special services and parse better than average**: Christmas Day 2015/2018/2020/2023/2024/2025, Holy Week `2017-04-14`, `2018-03-30`, `2022-04-14`, `2023-04-06`, `2023-04-07` ×2, plus `2024-10-04` (the source fixed in the row above, still showing its pre-fix cached date). Item counts 10–17, i.e. full running orders, with **zero** `invalid_extraction` and **zero** `no_items` against 5.2% and 4.6% respectively across the 697 Sunday plans. **What the survey did surface** is the `low_confidence` constant now recorded as an open item in §10 |
| ~~`low_confidence` is a constant, not a signal~~ | — | **FIXED 2026-08-24 in two commits, zero model spend.** Closed out of §10; the recommendation this row previously carried (drop the constant) would have caused a 90% regression, so the order of the two steps is the point. **Step 1, `61e0894c0` — provenance made explicit, behaviour-preserving.** `OosEmailServicePlan::isEvidenceImportable()` required `holdReasons !== []` to mean "this disposition was recorded rather than defaulted", which held only because the compiler's fixed 0.75 sat below the 0.90 threshold and gave every plan a `LowConfidence` reason. Replaced with an explicit `dispositionRecorded` field, defaulting **false** so unconsidered construction sites fail closed — that default was load-bearing as a *review* device: it turned 16 fixture sites red and those red tests were the inventory of everything depending on the old coupling. Both decode paths prefer an explicit `disposition_recorded` key and fall back to whether the stored disposition is recognisable; all 710 banked plans carry one, checked before relying on it. Proved on the corpus: 670 evidence-importable before, 670 after, **zero disagreements**. **Step 2, `96ec922cd` — the flag repointed, and `auto_import_threshold` deliberately NOT retired.** Checking the "dead config" premise reversed it: confidence is a *ceiling* system, not a constant — the compiler sets 0.75 and `OosEmailParserService` caps downwards per finding (0.74 missing identity/implausible date, 0.40 no items), measuring 605/73/28/4 across the 710 plans. Only the *comparison* was wrong, so `low_confidence` now tests `review_threshold` (0.75) and fires on the **105** plans a finding genuinely reduced instead of all 710 — repointing keeps the signal where deleting would have discarded it. The threshold stays because `ChurchServiceReviewSynchronizer::hasImportReviewSignal()` uses it as the upper bound of a `[0.75, 0.90)` band over `importMetadata['confidence_score']` on existing services, which can hold legacy scores above 0.90, and because deleting it would make auto-import impossible by construction rather than by arithmetic — a policy change, not a cleanup. **Why the order mattered, measured:** repointing empties the hold list of **601** plans, and under the old predicate an empty hold list meant ejection from the evidence tier. Simulated against both predicates the repointed rule yields **670 evidence-importable under the new predicate and 69 under the old** — step 2 alone would have collapsed the tier by 90%, silently, since no test covered the boundary. Two tests now pin it (uncapped 0.75 unflagged, capped 0.74 flagged) plus `tests/Unit/Data/OosEmailServicePlanTest.php` for the predicate itself. **Nothing re-parses**: identity resolution re-applies every run, so corrected hold reasons appear at the next `--import`/`--evaluate` at zero cost — expect `low_confidence` on ~105 plans and ~601 with clean hold lists at the next RG-A. **Also settled while measuring:** the 4 plans at confidence `0` are `OosSemanticParserCandidate::failedExtraction()`, the designed total-failure path (zero items, `invalid_extraction`, unknown scope) — the same 4 `unknown:unknown` sources from the replay divergence list, correctly held and correctly still flagged under the new rule. Not a defect |
| ~~Take `2024-10-06` off the accepted-holds draft and regenerate the expectation; confirm `low_confidence` at the next RG-A~~ | — | **DONE 2026-08-24, zero model spend.** `2024-10-06` removed from `storage/scratch/oos-accepted-holds-20260822-draft.json` (20 → **19**, leaving `2016-05-01-pm` as the only entry added same-day pending operator sign-off). `oos:generate-corpus-expectation` re-run against the 19-entry draft: **554 approved sources / 540 identities / 19 accepted holds**. Confirmed zero-cost first by tracing `GenerateOosCorpusExpectationCommand`'s dependencies for OpenAI/HTTP imports (none) before running, per the established pattern. A fresh `oos:import-archive --evaluate --cache-only` (a cache miss fails closed, so completing at all is itself proof of zero spend) gives the predicted RG-A read: of 710 dated plans, **107 low_confidence, 599 with a completely clean hold list** — matching the ~105/~601 estimate (small drift from the holds-draft edit and corpus movement since). **Trap found while measuring this:** the persisted `InboundEmail.processing_metadata.parsing.hold_reasons` column is a snapshot from whenever a row was last imported under whatever rule was live *then* — it does not recompute on its own, so reading it here (the pattern every script under `storage/app/private/replay/` uses) would have shown the stale pre-fix 710/710. Only a fresh `--evaluate`'s JSON report recomputes `hold_reasons` live from the cached raw parse. Remaining: this was run against `crockenhill_rehearsal_rga_20260824`, an **Email-only** rehearsal DB (0/614 OpenLP staged) — the *combined* RG-A gate this slice needs still has to be re-run once a fresh combined rehearsal exists |
| ~~`2016-05-01-pm` mischaracterised — its plan is not the accepted "zero items"~~ | — | **FOUND AND FIXED 2026-08-24, zero model spend.** Re-checking the accepted-holds draft's own reasoning against the live corpus (prompted by a direct challenge — this is the only failure of its kind found, not a pattern assumed from one example) showed `2016-05-01-pm`'s plan has never extracted zero items: `archive_parse_cache.raw_result` (unchanged since original import, so this predates today) has one `songs` item whose `title` is the entire announcement paragraph — *"I'm planning to have 'A Debtor to Mercy Alone' on Sunday evening. Last time Mark picked it he suggested we learn a new tune..."* — unresolved against the song catalogue (`song_link.hits: 0`). `OosEmailExtractionValidator` checks only structural provenance (every item cites a real, ordered, unique source line) and has no title-plausibility check at all, so this passes cleanly: one line, no duplicates, in order — `contentReasons` stays empty. **Why this is only visible now**: under the pre-fix constant `low_confidence`, every `ReviewRequired` plan had a non-empty hold list by construction, so this plan was always fronted to a human regardless. Under the corrected rule (row above) an uncapped-confidence plan with no other finding has a genuinely **empty** hold list, and this plan has none — it grades straight through to disposition `eligible`, the top tier, and would stage the garbage item on the next real `--import`. The accepted-holds draft's reasoning for this entry ("correctly extracting zero items, same shape as `2016-11-27-songs`") was simply wrong, and the `accepted_holds` mechanism can't fix it anyway — it waives corpus-*completeness* expectations, not parser disposition or import staging, so approving the hold would not have stopped the bad item. **Considered and rejected: a general title-plausibility validator.** A corpus-wide zero-cost scan of all 6,908 banked item titles across all 710 dated plans for the same shape (word count > 12, or ≥ 2 sentence-terminators) flagged **264 items (3.8%)**, and inspection showed nearly all are legitimate hymn/reading entries carrying ordinary parenthetical asides in this corpus's email style — e.g. `"Hymn: 666 'Come you souls by sin afflicted' (seems to go well to Praise! tune 511)"`. A heuristic built to catch one confirmed instance would misclassify hundreds of correct items as `content_invalid`; no safe general rule was found, matching the reasoning already used elsewhere in this document for declining a corpus-wide regex tightening over a single-source win. **Fix applied, scoped to exactly this one source**: moved `2016-05-01-pm` from `disposition: include` to `disposition: exclude` in `storage/scratch/oos-curation-manifest.json` (an existing, already-used lever — 3 other entries were already excluded this way), with the exclusion reason recorded in the manifest itself; removed its now-stale leftover formatted copy (`storage/scratch/oos/2016-05-01-pm.md` — excluded entries carry no `formatted_*` fields under this manifest's schema, and the verbatim copy is retained); removed it from the accepted-holds draft. It was one of the two entries added on top of the original 18 the same day as `2024-10-06` (both now gone for their own reasons — one fixed, one excluded), so the draft is back to exactly the **18** entries approved 2026-08-24. Nothing is lost from the corpus: 2016-05-01's real evening content (hymn 844) is already captured by the separate `2016-05-01` source's `additional_services`, unaffected by this exclusion. `oos:generate-corpus-expectation` re-run: **553 approved sources / 539 identities / 18 accepted holds** (identity count 540 → 539 because the excluded entry's own `resolved_service: evening` was a second, now-removed identity distinct from `2016-05-01`'s own `resolved_service: morning` — the evening coverage via `additional_services` was never counted in the expectation's identity total to begin with, only in the beyond-manifest reconciliation). A fresh `--evaluate --cache-only` confirms: 553 approved/selected (was 554), the source no longer appears at all, 709 dated plans (was 710), "defective" title hygiene 28 → 27 |
| Source recovery for the 3 unevidenced current-era services | IC4 | Operator |
| Video curation worksheet adjudication + freeze | IC5 bulk pass | Operator, on the §2.4 trigger |
| Era release sign-offs and the §8.4 policy decision | Each RG-C release | Maintainer/church |
| Denominator reconciliation (193/220, 538/662) | Era release accounting | IC5 prep |

## 11. Permanent vs disposable surface

Permanent (invest freely): shared parsers/projector/review, §3.2 invariant enforcement, the
canary/red-test suites, the public archive, the audit-report command, quarantine/release seam.
Disposable at closeout (name the trigger in every new addition, per the carried safety invariant):
historic commands, staging guards/contexts, bundles and transfer, checkpoints/journal, ingress
locks, release ledger, production guard. Do not grow the disposable surface except where an IC
package requires it.
