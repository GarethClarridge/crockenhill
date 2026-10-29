# Historic video post-round audit

**Status — 2026-10-24: initial 70-service discovery completed; luna-batch50-03 at 24/50 reviewed, stopped_at_usage_limit. See live checkpoint below.**
**Reconciliation required before the next audit batch (2026-10-29):** the discovery report is a
dated lead ledger, not a current defect count. Its run 1011 `supported_material` song-opening
omission was superseded by the 10-24 source-level check: the earlier apparent sung lines came
from near-silent bleed, and the clip starts when usable sound returns. Record an accepted source
limitation in the findings/aggregate, preserving the original claim and its correction. The 13
stale `song_videos` bindings found in the 10-24 class scan were cleared after a backed-up repair;
do not count the old bindings as current defects. The 10-24 re-cuts changed song clips on 17 runs
and re-encoded 16 sermon videos; their saved packets/reviews require output-hash comparison and
affected re-review before they can support current-output acceptance. Do not infer that the other
audit packets remain current without checking their hashes against later processing.

The next audit checkpoint should state, separately: services reviewed; material findings still
supported on the present artifact; accepted source limitations; unresolved media questions;
packets invalidated by changed hashes; and apparent passes awaiting independent sampling.
Preserve the 94 completed discovery records and resume only the 26 unfinished members of
`luna-batch50-03` where their input hashes remain valid. The missing-sermon-opening class check,
disjoint follow-up, full decode and assembled-join checks remain open. For final audible edge
decisions use normal speed and identify whether the verdict came from operator listening or text.
The operator approves wider Luna lead discovery with evidence-based verification despite the
failed calibration gate for cheap-only clearance. Pass A checked the expected files, streams and
recorded durations, but full decode validation remains undone. The pilot produced five supported
material findings: four surfaced by Luna and one found by independent review. This is an execution work package under the
[historic video plan](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md) §4.3–4.6,
not a new release authority. It adds agent-led semantic inspection of the settled results and
supplies evidence to the existing acceptance work. It does not replace the song protocol,
human listening requirements already agreed, or production delivery checks.

**Outcome:** find consequential mistakes in what was retained, omitted, transcribed, labelled
or assembled, with minimal additional operator listening. Deliver an evidenced exception list,
coverage record and repair recommendations. This planning task authorises no processing,
publication, production writes or new provider calls.

## 1. Timing and frozen inputs

Start after Claude records closure and the final backup. Do not query mutable processing state
or compete for media I/O during closure. Work from a consistent database snapshot and immutable
artifacts; use a restored audit copy if application code is needed. Planner resolution can write
back, so do not use it as a supposedly read-only inventory against the working database.

The audit copy is its own MySQL schema (for example `crockenhill_audit`), never `crockenhill` and
never the testing schemas. Run no PHPUnit or Dusk against the environment holding it: Dusk swaps
`.env` and the suites refresh whichever database they are pointed at (the 10-15 wipe of
`crockenhill` happened this way). Point audit tooling at the schema explicitly, and run nothing
from the retired round clone.

- Freeze all current eligible historic runs, not only the final round's members. Inventory
  excluded, retired, superseded, held and failed runs separately with their dispositions.
- Record the snapshot, code revision, policy/rulings versions, membership hash and every
  source/output/transcript hash used. Use actual recorded extraction segments, not section bounds
  or a new planner prediction. Outputs may have different producing revisions.
- Give each service one packet: source transcript with timestamps; ordered output segments and
  source-to-output time mapping; final output transcript where available; metadata; sections;
  catalogue candidates; email/OpenLP/workbook evidence with provenance; and applicable rulings.
- Preserve the difference between planned service content and what the recording contains.
  An email or hymn list is corroboration, not proof that something was recorded.
- Missing evidence means **unassessable**, never a clean result. Record which texts share a
  transcription ancestor: agreement between derivatives is not independent confirmation.
- Keep raw packets and detailed results in backed-up private storage; commit reusable tests and
  small, scoped fixtures rather than whole-corpus packets. Services are livestreamed and already
  public (register G6), so transcript content is a size question, not a privacy one.

## 2. Coverage and work order

| Pass | Coverage | Work |
|---|---|---|
| A: inventory and media | Every eligible output and every excluded/held disposition | Start from the close's reconciliation (693 outputs, 0 missing, 0 live duplicate pointers, 0 failed jobs; hashes in `storage/scratch/close-final-round-20261022/`) and re-check only what it did not cover: duration versus executed spans, streams, partial-recording flags and late approvals. Decode-check all final outputs with throttled I/O, never during a cut; count failures/unreadable files explicitly. |
| B: semantic review | Every eligible service with usable text; all its talk outputs and song bindings | Source-first outline, output reconciliation, whole-sermon coherence, metadata grounding, omitted-content review and song identity review. |
| C: independent verification | Every material finding, every assembled join, and a fixed random sample of apparent passes | Verify against the actual output and source using bounded fresh transcription or supported media inspection. Inspect source windows selected before seeing detector labels. **Fresh transcription is processing:** report the window count and cost, then wait for the operator's word before any decode or provider call. |
| D: adjudication and handoff | Every finding and every unassessable unit | Evidence-based routing (§5), targeted stronger review where useful, deduplication by cause, applicable rulings, severity, evidence and proposed next action. |

Reuse existing inventory/readiness, recorded-cut evidence, song measurement and regression tools
after checking their contracts. Avoid a second general processing framework. Build the per-service
packet from data every run records, not from historic-only staging, so the same packet and rubric
can check weekly runs after the take-over (§4.2); historic work exists to improve routine processing.

This audit and the ruled-evidence capture (historic plan §4.6: 883 rulings, 168 captured, fixtures
in `storage/scratch/rulings-capture-20261022/`) are siblings. The capture scores *where edges fall*
against rulings; this audit judges *whether the result makes sense*. Share their inputs, and do
not re-derive an edge question the capture already answers. Any new extractor or
validator needs tests for timestamp mapping, missing evidence, duplicated outputs and stale hashes;
use Sail and the normal project quality gates for code changes. Model judgement is evaluated by
calibration, not proved by mocked-response tests.

### Execution units and checkpoints

The 20-service batch was a pilot, not a throughput limit. Schedule **50–100 services per batch**,
with a smaller final remainder. A scheduling batch is a queue, not one agent's context: keep at
most three workers active, assign one or two services per fresh worker, and preserve the source-first
outline before opening delivered evidence for each service. Split long sources with the coverage
and synthesis rules in §3. A batch may span several usage windows.

Freeze batch membership and packet hashes before dispatch. Save each completed service result
immediately, with model/settings, rubric hash, coverage and any protocol deviation. Keep distinct
queue states for pending, running, reviewed, awaiting adjudication, and evidence-unresolved work.
On interruption, preserve completed results and explicit partial coverage; resume only unfinished
work after the allowance resets. A partial review never counts as complete. Batching changes
throughput and coordination, not the review units or evidence standard.

## 3. Semantic review instructions

Each cheap reviewer performs two stages for one service, preserving the first answer before
receiving pipeline labels:

1. **Source first:** read the complete available source transcript and identify the service's
   meaningful content, sermon argument, readings, songs, other talks and uncertain passages.
   Do not infer from silence in the text that no speech exists in the recording.
2. **Compare the delivered result:** inspect final metadata, transcripts, segment mapping and
   omissions. Obtain applicable rulings before classifying an exception as a defect.

Check explicitly:

- Is the sermon's argument understandable from opening through conclusion? Are premises,
  referenced examples or conclusions lost at an edit? Do joins repeat or skip meaningful material?
- Does an apparent interruption belong to the sermon, or is it another item? Inspect omitted
  source passages as well as retained passages, including substantial material labelled `other`.
- Do titles, summaries, points, Bible references, dates, service labels and series have support?
  Identify invented claims and changed negations; do not rewrite the speaker's theology or style.
- Do transcript repetitions represent repeated speech, lyrics, or a probable decoding fault?
  A fluent sentence can still be wrong; contradictory or implausible text is a lead, not a verdict.
- Do song lyrics/first lines support the bound identity? Keep catalogue twins, different settings,
  repeated performances, introductions and missing recordings distinct. Preserve the existing
  song protocol's units, denominators and adjudication requirements.
- Are partial recordings honestly presented? Honour accepted incomplete sources, soft misses,
  intentional holds and the operator's boundary rulings; do not reopen them as taste preferences.

For long services, split at timestamped boundaries with overlapping context and explicit coverage
accounting. Every chunk is reviewed, followed by a service-level synthesis. Never silently truncate
input or assess whole-sermon coherence from a summary alone. Song-only material need not be
repeated in every sermon packet.

## 4. Cheap subagent strategy

**Default: Luna for lead discovery, with evidence-based routing for verification.** An unflagged
service is an apparent pass, not certified clean. The operator approved this use after the pilot;
it does not mean Luna passed the original clearance gate. This session
exposes `gpt-6-luna`; it does not expose Haiku. Claude can run the same packet/rubric contract with
an available Haiku model in its own environment. Record the exact model/version and settings;
do not assume either family is sufficiently accurate or cheaper per useful result in advance.

- Use fresh, bounded agents with no inherited chat history. Supply only the rubric and packet
  paths, not the months-long processing conversation. Under this session's delegation interface,
  an explicit model override requires `fork_turns="none"`.
- Start Luna at medium reasoning. Haiku settings depend on the model Claude actually has.
  No recursive delegation, database writes, repairs or web research by reviewers.
- At most three worker agents alongside the coordinator here. Assign a few service packets
  sequentially per worker, preserving one structured result per service; recycle before context
  accumulates unrelated material. Do not run competing jobs on the same packet.
- Use scripts for mechanical checks. Do not spend model tokens recounting rows or checking hashes.
- One cheap semantic pass by default. A stronger model checks text-resolvable material flags,
  grouped minor leads where useful, and the corpus-level independent sample (§5). Missing audio
  evidence goes to the media-verification queue, not repeated model reviews of unchanged text.
- Different model families can expose different mistakes, but two agreeing models do not create
  independent source evidence. A model verdict cannot release a hold or override a human ruling.

Official Codex guidance supports model-specific subagents and bounded delegation:
[Subagents](https://learn.chatgpt.com/docs/agent-configuration/subagents).
Actual session capabilities above are the execution constraint; no Haiku integration is presumed.

### Calibration evidence and scope of wider use

The initial 24-case calibration is retained below as the clearance test, not a gate that the wider
discovery workflow has passed. Luna/medium detected 5 of 8 registered material defects. A two-case
Sol/high pilot did not resolve the two selected song-boundary misses: the text did not establish
the actual audible content. Repeated model escalation cannot reconstruct missing evidence.

The subsequent 20-service batch supports narrower use: Luna raised 30 flags on 17 services;
Sol supported 4 material and 16 minor findings, left 8 unresolved, rejected 1 and honoured 1 accepted
exception. Independent source-first reviews of all 3 apparent passes found run 1027's omitted
sermon opening. Its affected-class check covered only those 20 services. Run 1167's independent
sentence-omission finding was downgraded to a minor thematic song handover after severity review.
Results and hashes: `storage/scratch/overnight-20261023/audit/luna-batch20/batch-report.json`;
the [historic plan's execution record](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md)
contains the calibration and pilot history. These observations establish useful lead discovery,
not a corpus defect rate or reliable cheap-only clearance.

**Next batch completed — 2026-10-23:** `luna-batch50-02` froze 50 previously unpacketed
services from Pass A, with membership seed `20261023`, packet hashes and the unchanged v2 rubric.
Twenty-five fresh Luna/medium workers reviewed two services each, at most three active together,
preserving source-first outlines before opening delivered evidence. Coverage: **54,658 source
cues, 213 outputs**. Luna raised **39 flags on 29 services**; the other **21 are apparent passes**,
not certified clean. Targeted Sol/high verification and coordinator editorial grouping produced
**5 supported material, 16 minor, 13 evidence-unresolved, 3 rejected and 2 accepted-exception**
dispositions. Four unresolved leads remain potentially material; the other nine are minor.
Thirty-nine services have unassessable notes, including withheld items and transcription gaps;
this is not a count of defective services.

The five supported material findings are wrong named-preacher attribution on **967 and 975**,
omitted foundational readings on **1066 (2 Chronicles 1) and 1148 (Revelation 5)**, and a final
song retaining complete sentences of the following prayer on **932**. Exact replacement identities
and repair edges still need their normal evidence checks. Potentially consequential transcript
leads on **991, 1280, 1283 and 1323** await bounded new media evidence; repeated review of their
correlated text cannot settle the spoken words. Existing start/closing-prayer acceptances on
932 and 1379 were retained. A proposed cropped song opening on 1199 remains unresolved because
the excluded words may be a permitted spoken introduction rather than singing.

Artifacts: `storage/scratch/overnight-20261023/audit/luna-batch50-02/batch-report.json`,
`coverage.json`, `findings.json`, `sol-verification.json`, effective citation checks, input/result
hashes and the resumable queue. Citation corrections preserve raw answers separately; all effective
citations validate. The corpus apparent-pass ledger is
`storage/scratch/overnight-20261023/audit/apparent-pass-ledger.json`: population and hash records
are saved, but final strata, selection and blind review remain pending wider coverage. No per-batch
sample or automatic credit for pilot checks is claimed. All 50 service reviews explicitly screened sermon
openings; this does not complete the independent corpus-wide affected-class check or its disjoint
follow-up. Separate pending media records enumerate **213 decode checks and 48 assembled joins**
for this batch. No decode, fresh transcription, repair, publication or production write occurred.
Account usage and execution timestamps are recorded with the batch; no reset was redeemed.

**Original calibration contract (retain for future model/rubric comparisons):**

Prepare 24 blinded service/case packets: 8 confirmed material defects using preserved pre-fix
evidence, 8 accepted controls (including partial recordings and intentional joins), and 8 fresh
stratified services. Include omission, wrong identity, semantic discontinuity and transcript
corruption; do not use only easy boundary examples. Ground truth must be supported by recorded
rulings/source evidence, not solely another model's answer. Do not show verdicts to cheap reviewers.

Many pre-fix outputs were re-cut or retired, so check that a defect's evidence still exists before
choosing it. Draw first on what is preserved with hashes: the ruled-evidence capture's fixtures,
the 72 listening answers in `storage/scratch/close-final-round-20261022/listening-evidence.json`,
and the rulings register. If fewer than 8 positives survive, say so and do not substitute
model-made ground truth.

Run Luna on all 24. If Haiku is readily available through Claude, run it independently on the
same packets. A strong reviewer assesses all results, including apparent passes, against evidence.
This is a routing calibration, not a corpus error-rate estimate or a new held-out acceptance set.

Proposed scaling criteria, fixed before scoring: detect all material known defects in the eight
positive cases; at most two material false alarms among the eight accepted controls; every material
claim must have a resolvable passage/timestamp citation. Unsupported claims are rejected. One prompt
revision is allowed, then use additional blinded cases to recheck; retesting the same answers does
not establish generalisation. If a defect class is missed, route that class to stronger review.
If performance remains poor, stop cheap-only routing rather than repeatedly tuning this sample.

Record elapsed time, input/output tokens where available, retries and adjudication effort. Compare
total work per verified finding, including false alarms. Estimate the full run from actual packet
sizes and measured rates, with a stated contingency, before scaling. Subscription usage is not a
known cash price; no speculative dollar estimate. For this operator-approved wider discovery run,
there is **no additional allowance budget**: the account's five-hour usage limit is the execution
stop. Record usage where exposed, checkpoint and report remaining coverage when the limit is hit,
and resume after reset when instructed. Do not redeem resets or buy credits automatically. Keep
the worker concurrency bound and finite batch membership; no unbounded overnight fan-out.

## 5. Verification that does not ask the operator to listen again

- Give every flag a recorded disposition, using the routing table below. Reuse applicable rulings
  first. Validate citations mechanically, then inspect the cited context; reject invented quotations
  and correct mismapped times. A stronger model adjudicates only questions the supplied evidence
  can answer; it does not certify actual speech or singing from coarse cue durations.
- For every multi-part output, compare the actual file around each join with its intended source
  spans. Start with 30 seconds each side and expand where an argument requires more context.
- Draw a reproducible **corpus-level** sample of cheap-agent apparent passes: for `P` eligible
  apparent passes, review `min(P, max(30, ceil(0.10 * P)))`. This is not a minimum of 30 per batch.
  Freeze the apparent-pass population and hashes, stratify by era, repaired/never-repaired status
  and presence/absence of independent service evidence, and record seed `20261022`, allocation,
  selection logic and denominators before independent review. As the pool grows, extend a seeded
  sampling ledger to the final required count without discretionary substitutions; retain and
  report any extra earlier reviews. The pilot's three checks are not automatic credit against the
  wider sample: count them only if they meet the final selection and unchanged-evidence contract.
- Strong-review those services blind to the cheap verdict. For each select three 45-second source
  interior windows before consulting detector labels, including omitted material where present,
  and compare applicable output windows. Report challenge cases separately from random results.
- Use targeted fresh transcription of the **actual output audio** and source where needed, with
  no existing transcript supplied as a prompt. Prefer existing local services after closure;
  a different decoder/backend gives stronger corroboration where available. Same-backend repeats
  remain correlated. Explicitly record the backend and whether it processed source or output.
  Local Whisper is the host whisper.cpp the pipeline itself uses, so it is the same backend.
  Every fresh transcription waits for the operator's word (Pass C).
- Text reviewers must not claim to have heard audio. If a tool cannot inspect audio directly,
  label its evidence as transcription. Silence, music quality and uncertain words may remain
  unassessable despite several agreeing transcripts. Frames do not prove continuous video quality.
- A missed material defect in the clean sample triggers review of its affected class across the
  corpus and another disjoint clean sample for that class. If no safe class boundary exists,
  escalate the remaining cheap-only semantic passes. Record the affected population and use the
  same sample-size rule for the disjoint follow-up pool; these targeted checks are additional to
  the baseline corpus sample. Report expected work/usage before expansion without inventing a
  subscription-to-cash conversion. **Already triggered:** run 1027 requires a corpus-wide check
  for missing sermon openings, including introductions before readings or songs; the 20-service
  class check does not close that obligation. Record exclusions supported by existing rulings.

### Flag routing

| Evidence/question | Route and recorded outcome |
|---|---|
| Applicable existing operator ruling | Check that it covers the same output, cut and question; retain an accepted exception when it does. Do not reopen it as a preference. |
| Text-supported omission, contradictory metadata, wrong title or song binding | Targeted Sol review of source context, executed spans and stored text; retrieve frozen catalogue/other evidence where needed. Preserve supported material findings and exact correction leads; no repair is implied. |
| Minor transcript spelling, repetition or wording | Group into an editorial queue. Mechanically deduplicate and use grouped stronger review only where meaning or disposition needs judgment. Do not spend a full-service Sol review on each typo or guess unheard replacement words. |
| Garbled/missing text, coarse boundary cues, singing-versus-recitation ambiguity | Record `unassessable` or an unresolved lead with the missing evidence, affected interval and consequence. Route consequential questions to bounded Pass C media verification; defer cosmetic questions explicitly. Do not keep escalating models on the same inadequate text. |
| Conflicting interpretations with adequate evidence | Strong-model adjudication against the cited evidence and rulings; preserve the reasoning and uncertainty rather than voting between models. |

Every material flag is accounted for, but not every flag needs another model call. An unresolved
media question remains unresolved until actual evidence settles it. Catalogue metadata and
transcription agreement do not prove continuous audio quality. The independent sample and all
assembled-join checks remain separate obligations; neither is replaced by flag adjudication.

Only send the operator questions that remain consequential after evidence retrieval and strong
review. Supply the exact ambiguity, why automation could not settle it, and the shortest useful
source/output excerpts. Reuse existing answers first. Do not send a bulk listening page by default.

## 6. Findings, completion and links to existing work

Each result records: packet/hash, run and output IDs, check coverage, model/settings/prompt hash,
status (`no_issue_found`, `suspected`, `confirmed`, `accepted_exception`, `unassessable`), severity,
source and output timestamps, short quotations, evidence lineage, relevant ruling, reasoning,
proposed disposition and reviewer. “No issue found” is not a guarantee of correctness.

Classify confirmed findings as:

- **Release-critical:** wrong/private material exposed, wrong recording/person attribution,
  substantial missing content or meaning reversal. Identify exactly which release candidates
  are affected; unrelated accepted outputs need not be reprocessed.
- **Material quality:** incoherent join, wrong song/reference/title, consequential transcription
  or summary error. Recommend the existing repair or editorial route.
- **Minor/accepted:** cosmetic wording, known source limitations and already accepted soft misses.
  Record without turning them into another processing round.

Audit completion means every frozen member has a coverage/disposition record, all material findings
are adjudicated or explicitly unresolved, the clean-sample review is finished, and evidence is
backed up. It does **not** require repairing every finding to finish the audit. Repairs are separate,
test-first work through the standard pipeline; changed hashes invalidate affected audit results.

Deliver:

1. A concise operator summary: checked counts, unassessable counts, confirmed problems by class,
   accepted exceptions, costs and recommended next actions.
2. Machine-readable coverage and findings with reproducible packet/sample identities.
3. Small regression fixtures for newly confirmed classes when repairs are undertaken.

Feed results into historic-plan §4.3's coverage/alignment matrix and §4.5's editorial/acceptance
work; preserve rulings under §4.6. The song protocol remains authoritative for its measurement.
Browser/access checks use the existing staging and production acceptance lane. This audit's source
sample supplements, and does not silently replace, the predeclared release-membership sample.

**Next execution slice:** schedule the remaining frozen services in queues of 50–100, reusing
hash-valid completed reviews rather than repeating the pilot. Route flags by available evidence,
maintain the corpus-level apparent-pass sampling ledger, and complete the triggered sermon-opening
class check plus its disjoint follow-up sample. Keep a separate pending record for full decode,
every assembled join and Pass C media verification; text-only review does not complete those checks.
Back up the evidence before audit closeout. Repairs to the five pilot findings remain separate,
test-first work; this plan update performs no repair, publication or fresh transcription.

<!-- LUNA-CONTINUATION:START -->
### Continuing Luna execution — limit-stop handoff

Updated 2026-10-24T11:35:58.022450+00:00. **luna-batch50-03: 24/50 services reviewed;
26 unfinished; execution state `stopped_at_usage_limit`.** The earlier pilot and
50-service batch cover 70 services; cumulative discovery coverage is now **94**.
This batch has 16,496 reviewed source cues, 27 raw flags on
16 services and 8 apparent passes. Current dispositions:
`{"accepted_exception": 1, "evidence_unresolved": 3, "rejected": 3, "supported_material": 4, "supported_minor": 16}`. Raw flags are not confirmed findings.

Membership, packet hashes, source-first outlines, per-service results, coverage and resumable queue:
`storage/scratch/overnight-20261023/audit/luna-batch50-03/`. Live aggregate:
`storage/scratch/overnight-20261023/audit/discovery-report.json`. Start allowance consumed:
71%; latest observed consumption:
100%. No reset is redeemed automatically. Resume only
unfinished reviews, preserving separate awaiting-adjudication and evidence-unresolved work.
Corpus sampling and the missing-sermon-opening affected-class check/disjoint follow-up remain pending;
these are not replaced by per-batch passes. Full decode, actual-media join checks and bounded Pass C
remain separate. No repair, fresh transcription, publication or production write is performed.

The five-hour limit reached **100%**, confirmed by the account limit response and the interrupted
worker. Allowances subsequently refreshed; the operator requested wrap-up on 2026-10-24, without
starting more work. Run **1197** retains a source-first outline only and is not counted complete.
The four supported material findings are **1011** (cropped hymn), **1096** (omitted sermon
conclusion and mismatched song binding) and **1147** (unrelated prayer-request discussion).
All 27 flags now have a recorded disposition; unresolved evidence remains unresolved. Protocol
deviations in 1147/1152 (packet metadata opened before outline preservation, delivered data afterward)
are retained in coverage, rather than represented as fully blind source-first reviews.
<!-- LUNA-CONTINUATION:END -->
