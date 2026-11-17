# Historic video post-round audit

**Current acceptance protocol and ledger — reconciled 2026-11-06.** Content acceptance,
published delivery and release remain incomplete. This cleanup reconciles recorded evidence;
it does not run new checks or clear outputs. The [video worklist](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md)
owns repairs; this document owns quality coverage, sampling, escalation and acceptance.

Detailed discovery reports, interrupted-batch memberships, intermediate interpretations and
check receipts are preserved in the [11-06 evidence snapshot](../archived-plans/HISTORIC-POST-ROUND-AUDIT-EVIDENCE-2026-11-06.md).
Its historical “resume”, “next” and “not started” instructions are superseded by this status.

| Lane | Latest recorded result | Work still owed |
|---|---|---|
| Pre-round class discovery | 45 services, stopped after two consecutive clean batches on 11-05; two classes became scans/fixes | Verify fixes on final outputs; retain the missing-opening class check and disjoint follow-up. No automatic restart of discovery. |
| Supplemental boundary leads | 100 services / 1,116 contexts reviewed, no new confirmed boundary class | Operator stopped round-4 A/B on 11-06 for no new leads. Its 20 unreviewed services get no coverage credit and are not a pending resume task. |
| October discovery | 94 completed records; 26 unfinished in `luna-batch50-03` | Per-service sweep superseded by the 11-05 sampling scope. Retain findings and valid evidence; do not schedule the unfinished batch from its old handoff. |
| Full decode | 2,127 files, zero errors, recorded in `storage/scratch/output-review-20261105/STATUS.md` | Bind membership/hashes to current outputs, cover any omissions, and decode changed bytes. Decode success does not establish semantic/audio quality. |
| Output generations / access | Ten stale main generations remain; promotion cause fixed. 21 host-valid clips readable after app-container recreation | Verify recovered video/audio/text generations and fresh-container access after processing. Repairs belong to video §4.1b. |
| Independent media/identity checks | 442 frozen packets; 26 text-overlap pairs triaged; 18 source/output windows over six services; three retained-transcription comparisons | No new confirmed processing defect in this scope. Semantic listening, full output/join coverage and named residual leads remain open. |
| Delivery | Six local HTTP probes; quarantine correctly denied. Staging URL guard blocked listing/published-media rehearsal | Use an isolated published-media rehearsal with correct storage context, then authorised destination checks; keep quarantine protected. No production-outage claim. |
| Pre-freeze acceptance (11-10) | Not started (checked 11-17) | §0 "Before the freeze": the reprocessing-sensitive lanes run before the last round's freeze, on a declared sample of historic services (video §4.1f row 2; work package W9). The fresh-services trial moved after the round as the take-over validation (register R16). |
| Release | No batch authorised | Complete §7 and pass exact accepted membership to programme §§7–8. Weekly remains paused. |

### 2026-11-06 supplemental Luna boundary reviews

Completed memberships, lead dispositions and declared blind spots are in the
[archived checkpoint](../archived-plans/HISTORIC-POST-ROUND-AUDIT-EVIDENCE-2026-11-06.md#2026-11-06-supplemental-luna-boundary-reviews).
The later 11-06 operator instruction in video §4.1b stopped A/B; do not follow the snapshot's
older resume instruction. Classification leads on 1208/1245, 1259 and 18426 were not established
output loss. 1294's one-song ruling is settled; verify its eventual recomposed clip.

### Transcription gaps: proposed follow-up

Keep the source-audio follow-up; stopping text discovery does not close missing evidence.
Use the [archived interval inventory](../archived-plans/HISTORIC-POST-ROUND-AUDIT-EVIDENCE-2026-11-06.md#transcription-gaps-proposed-follow-up)
and frozen packet metadata rather than guessing from silence in text.

- [ ] Deduplicate declared windows by source identity and interval, recording affected outputs,
  edge/join consequence, current hashes, existing ruling and disposition. Keep garbled-text leads
  separate from no-cue intervals; neither alone proves missing audio.
- [ ] Reuse settled source rulings (including 1294 continuity), prioritise consequential speech,
  edges and joins, and establish the content with audio-capable evidence.
- [ ] Where useful, propose bounded fresh transcription under Pass C's existing authority and
  cost requirements. Record decoded source/output and evidence lineage; do not repeatedly vote
  on inadequate text or treat an ASR result as listening.
- [ ] Keep unresolved cases unassessable, source limitations explicit, and proposed repairs separate.

## 0. Wider release confidence and who does the checks (2026-10-29)

**Outcome review, agreed 2026-11-06:** minimise manual review (G7), with clean, complete talk/song
videos as the outcome. This strengthens the agent-first direction below. D24 requires checks of
unanimous/unflagged results against source evidence; agreement between models reading the same
transcript cannot establish completeness. K20 distinguishes introduction content from the current
passage-name recognition mechanism. E27 supplies the priority when speech/music boundaries conflict.
These rulings do not make prior outputs accepted or reopen completed checks without an evidence reason.

Use the existing sampling and acceptance ledger to report Q15's measures: substantive omissions,
contaminated clips, usable outputs, operator questions and review minutes per service. State the
eligible/checked/unassessable denominators and separate pre-review results from repaired results,
material defects from optional preferences, and source limitations from processing errors. Where
time was not captured, report it unknown rather than zero. Keep known-case regression results
separate from fresh-source generalisation evidence; no new numeric pass bar is set here.

**Operator direction:** prefer Codex/Claude doing the checks over manual operator work wherever
the available tools and evidence support it. Completing the processing checklist produces a
release candidate, not accepted content. The following are investigation questions, not seven
newly confirmed defect classes. They organise the existing acceptance work; they do not create
another audit framework or replace the song protocol's measurements.

| Question | Agent-led check and evidence required | Existing owner |
|---|---|---|
| What did we fail to produce? | Make the source-first outline before opening pipeline labels, reconcile expected talks, readings, songs and sermon parts with actual outputs, and inspect omitted intervals and substantial `other` sections. Include apparently clean services and the no-independent-OoS group. A file inventory derived from detected sections cannot establish that detection was complete; a planned item is not proof it was performed. | Passes B/C; §7 completeness/alignment |
| Does the assembled sermon preserve its argument? | Check every assembled join against source and actual output for skipped premises, missing introductions, repetition and incorrect ordering. Assess the complete argument, not just fluent local transitions. Complete the existing five-service whole-output sample across eras, including repaired/previously held content, with both MP3 and video covered. | Passes B/C; §7 complete media |
| Are agreeing checks repeating one inherited error? | Record the ancestry of service transcript, derived sermon text, structure and analysis. Independently select source interior windows before seeing labels; compare with final outputs, including omitted material. Use existing independent evidence first and authorised fresh media verification where needed. A second model reading the same transcript is not independent evidence of what was spoken. | Pass C and §5 apparent-pass sample |
| Are descriptions true of the final recording? | Ground displayed titles, references, series, summaries and points in source evidence; distinguish a quoted objection from the preacher's claim and an incidental reference from the main passage. Verify named speakers or honest unknown wording, partial-recording disclosure, and thumbnail identity. Compare final stored/displayed fields with final media: preserved incumbent metadata and fresh banked analysis can differ after a rerun. Format validation, a valid Bible reference and analysis completion do not establish truth. | Pass B; §7 editorial fields |
| Are the complete files sound, and are those the files delivered? | Fully decode final outputs; compare source channels with MP3/video audio, check synchronisation and inspect interiors as well as edges. Exercise playback, seeking, joins, resume, download and replacement freshness through the real server, including representative mobile/browser coverage. Verify destination object identity after promotion. Hashes and plausible durations do not prove content quality; local acceptance alone does not prove destination delivery. | Passes A/C; §7 delivery; programme §8 observation |
| Do the outputs fit production's existing archive? | Compare production-only and local identities, including duplicate content under different dates/titles, morning/evening conflicts, existing richer metadata and service/media relationships. Produce explicit create/enrich/leave-alone dispositions and rehearse on a production copy. Local completeness cannot establish production convergence. | Programme plan §0.4 production census and §7 rounds |
| Does release behave correctly on every surface? | Exercise the decided audience policy for sermons, songs, talks and services through listings, search, sitemaps, feeds, downloads and direct media URLs. Test bulk-release chronology, feed episode identity, old links, archive navigation and partial-recording wording outside the page. Record environment and access context; confirm destination behaviour in the authorised release window. These bulk effects are hypotheses to test, not confirmed bugs. | §7 delivery; programme §8 release policy |

### Agent-first execution and escalation

- Codex/Claude own evidence retrieval, inventories, source-first semantic review, metadata comparisons,
  mechanical media checks, browser journeys, reconciliation and the concise findings report. Use
  existing tools, fixtures and reports; no new general audit subsystem. Do not turn an automatable
  comparison into an operator checklist or send a bulk listening page by default.
- Reuse applicable operator rulings and hash-valid evidence. Inspect apparently clean cases as well
  as flagged ones, under §5's declared sampling rules. Keep challenge cases separate from population
  estimates. Changed artifacts invalidate only dependent checks, but those checks must be repeated.
- State the modality actually used: text, fresh transcription, decoded media, frames, browser or
  operator hearing. An agent may settle an audible question only with a capability that actually
  examines the relevant audio; text-only tools must leave it unassessable. Normal-speed audible
  judgment remains required by Q14. Existing expressly human listening gates are retained; agents
  prepare their evidence and minimise the unanswered questions rather than silently waiving them.
- Escalate only consequential uncertainty left after evidence retrieval and supported verification,
  plus decisions reserved to the operator. Supply the precise question, why it remains unresolved,
  shortest useful source/output excerpts, existing ruling if any, and a recommended disposition.
  Cosmetic issues and accepted source limitations are recorded without manufacturing another round.
- This assigns responsibility, not new execution authority: fresh transcription/provider work and
  production mutations retain their existing authorisation rules. Signed release remains an operator
  act. No findings are accepted or holds cleared by this documentation update.

### Sequence and confidence report

**Before the freeze (added 11-10, video plan §4.1f row 2).** The next round is meant to be the
last corpus-wide one, so a check whose failure can only be repaired by re-transcribing, re-detecting
or re-cutting runs before the freeze, on current outputs and the final-code replay's planned cuts
(rendered audio where the question is audible):

- source-first completeness on a fresh declared sample, including apparently clean services;
- the transcription-gap follow-up above and H10's transcript-negative runs;
- every assembled sermon join;
- the detector/transcript evaluation;
- song protocol stage 2/M2/M4.

**Added 11-16 (register R13, R14; video plan §4.1f rows 9 and 10); amended 11-17 (R16):** the
services recorded from 2026-08-30 onward are no longer part of this pre-freeze sample. They run
after the round, on production, as the take-over validation and the independent check. Weekly runs on production with the OpenAI Whisper API, so
where a check depends on transcription or edge evidence, report it on that backend as well as
the local one.

Declare the sample, seed and denominators first, as §5 requires. Each finding is fixed and
measured, held, or recorded as residue before the freeze. Checks that need the final bytes (full
decode, hashes, delivery, thumbnails, editorial fields) stay after the round, where the same lanes
are confirmed on the round's outputs rather than examined for the first time. After the last
round a finding is repaired on the named run through the single-run routes (runbook §4.2).

Keep the pre-freeze checklist bounded to that list. During the authorised staged canary, take one representative
service through the complete local acceptance journey above before bulk expansion; mark production
and unavailable checks pending rather than calling the journey complete. Prepare the production
census early, independently of local cut repair. After the round, finish current-output checks and
editorial/song acceptance, reconcile production identities and rehearse a small era release before
the separately authorised destination release and observation window.

Report four distinct results: processing complete, content accepted, delivery verified, and release
authorised. `HistoricProcessingResultReadinessService` checks operational/structural conditions and
inventory consistency; it is not a semantic, audible or browser acceptance certificate. For each
dimension give eligible/checked/unassessable counts, current artifact hashes, residual limitations
and disposition. A green regression suite, empty queue or absence of alerts alone cannot establish
release confidence. This section records a review direction, not a fresh corpus or production census.

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
| A: inventory and media | Every eligible output and every excluded/held disposition | Reconcile current eligible membership and actual output generations, then reuse hash-valid checks. Cover duration versus executed spans, streams, partial-recording flags and late approvals; old close totals are not current membership. Decode-check all final outputs with throttled I/O, never during a cut; count failures/unreadable files explicitly. |
| B: semantic review | **Amended 11-05:** a seeded, stratified discovery sample read until it stops yielding new classes, plus scripted class scans over every eligible service ([revised scope](#2026-11-05-revised-scope-sample-to-discover-scan-to-count)); not a read of every service | Source-first outline, output reconciliation, whole-sermon coherence, metadata grounding, omitted-content review and song identity review. |
| C: independent verification | Every material finding, every assembled join, and a fixed random sample of apparent passes | Verify against the actual output and source using bounded fresh transcription or supported media inspection. Inspect source windows selected before seeing detector labels. **Fresh transcription is processing:** report the window count and cost, then wait for the operator's word before any decode or provider call. |
| D: adjudication and handoff | Every finding and every unassessable unit | Evidence-based routing (§5), targeted stronger review where useful, deduplication by cause, applicable rulings, severity, evidence and proposed next action. |

Reuse existing inventory/readiness, recorded-cut evidence, song measurement and regression tools
after checking their contracts. Avoid a second general processing framework. Build the per-service
packet from data every run records, not from historic-only staging, so the same packet and rubric
can check weekly runs after the take-over (§4.2); historic work exists to improve routine processing.

This audit and the maintained ruled-evidence capture (video plan §4.6) are siblings. The old
883-ruling/168-capture counts were superseded by later full capture and scoring. The capture scores *where edges fall*
against rulings; this audit judges *whether the result makes sense*. Share their inputs, and do
not re-derive an edge question the capture already answers. Any new extractor or
validator needs tests for timestamp mapping, missing evidence, duplicated outputs and stale hashes;
use Sail and the normal project quality gates for code changes. Model judgement is evaluated by
calibration, not proved by mocked-response tests.

### Execution units and checkpoints

The 20-service pilot and October 50-service queues are historical. Discovery follows the
15-service batches and stopping rule below; independent acceptance follows §5's corpus-level
sample. A scheduling batch is not one agent's context: use bounded fresh workers, preserve a
source-first outline for each service and respect the current host's concurrency limit. Do not
restart a stopped discovery sample merely because an old queue has unfinished members.

Freeze batch membership and packet hashes before dispatch. Save each completed service result
immediately, with model/settings, rubric hash, coverage and any protocol deviation. Keep distinct
queue states for pending, running, reviewed, awaiting adjudication, and evidence-unresolved work.
On interruption, preserve completed results and explicit partial coverage; resume only unfinished
work after the allowance resets. A partial review never counts as complete. Batching changes
throughput and coordination, not the review units or evidence standard.

### 2026-11-05 revised scope: sample to discover, scan to count

**Operator decision (11-05):** do not read every output. October's per-service review was paused
after 94 services because it kept finding examples of classes already known, while the corpus-wide
class scans (10-24) found and counted the real classes. This scope replaces "every eligible service"
in Pass B for discovery. **Its 11-05 sample completed at 45 services after two clean batches; do
not restart it.** The method below remains for a justified new discovery scope and post-round
acceptance. It grants no processing, publication or production-write authority.

**Purpose and order.** For a justified discovery round: find defect *classes* not yet known, each with an
affected population and a mechanism, so the code is fixed before re-running (fix the mechanism, not
the instance). The 11-05 artifacts supplied discovery; later processing invalidates dependent checks.
Use G8 to decide which stages and outputs change. Acceptance is checked against the final hashes.

**Inputs.** The frozen 11-05 snapshot `crockenhill_audit_20261105` and its 442 packets in
`storage/scratch/output-quality-20261105/packets/` (about 5.8M source and 4.3M delivered tokens;
1,934 outputs, 2,350 segments ≈ 416 joins and 3,868 edges; 1,280 song sections). Revalidate packet
hashes first. Media checks on 1025, 1055, 1133, 1199, 1214, 1286, 1299, 1305, 1325 and 1349 use their
staged generation, not the stale quarantine copy. Known classes are tagged, not re-reported: K16 v3
and split-song (built, not applied), E25, E26, K6 reading groups and declined same-chapter readings,
preacher attribution (out of scope), the "Visiting Speaker" default, P2 thumbnail deferral, the 33
Scripture refreshes, 1225/1250 degraded analysis, and every register ruling.

**1. Scripted class scans over every eligible service (no model calls).** This is the whole-corpus
coverage.

- Media: reconcile current membership with the completed 2,127-file decode and its hashes;
  throttle `ffmpeg -v error` full decode for changed or uncovered outputs (two jobs, never during a
  cut). Use `historic-import:verify-output-generations --hash`; the old decode is not a new-byte pass.
- Text: sermon ends without a sentence end or Amen; repeated lines across a join; transcribed speech
  in no output and under no ruling; long `other` sections; loops in stored transcripts; title or
  reference disagreeing with the section; an order-of-service song with no section.
- Song identity: fuzzy-match each clip's first and last lines against the bound song's catalogue
  lyrics. `service-audit:packets` needs the lyrics added first.
- Scan hits are review tasks, not findings.

**2. Discovery sample, read to saturation.** Seed `20261105`, stratified by era, repaired versus never
repaired, and with versus without an independent order of service. Read in batches of 15 services.
**Stop after two consecutive batches yield no new class**; expect 30–60 services. Record membership,
seed and allocation before reading. Record the members so the post-round acceptance draw (seed
`20261022`) excludes them or reports them separately. A 50-service sample finds a class present in
5% of services with 92% probability, 2% with 64% and 1% with 39%. Common classes surface quickly;
the residual risk is a one-off that no scan detects (the 1147 shape).

- Reviewer contract: Haiku 5.5 **locates and quotes, never rates severity**. October's calibration
  showed it places defects precisely but rates boundary and identity defects as minor. Narrow
  questions per task (edge/join window, omitted interval, metadata claim support, whole-sermon
  coherence, song identity residue), JSON answers, with `check_citations.py` dropping any quotation
  or timestamp not in the source. Opus adjudicates against the register, assigns §6 severity and
  dedups by cause.
- Re-run the 24-case calibration set (`overnight-20261023/audit/cases-key.json`,
  `calibration-truth.json`) on the narrow tasks first; the gate is that all 8 positives are
  *located*. A class Haiku misses goes to Sonnet 5.5.
- Mechanics: **Haiku subagents** (operator, 11-05). At sample size the Haiku work is under 1–2% of a
  week, so a Batches harness and paid calls would save almost nothing. Pack each task's inputs into
  one bundle file. Each agent is a fresh Haiku agent that reads one bundle, writes one JSONL file and
  returns one line, within the host's available concurrency limit, with checkpoints. Keep prompts and bundles as files,
  so the same tasks re-run after the round. Reconsider Batches only if a later pass reads the whole
  corpus.

**3. Each new class becomes a scan.** Write the scan, run it on the whole corpus for the affected
population, trace the code path and record a test-first fix lead in the video plan §4.1b. Reading
stops for a class once its scan exists.

**4. Targeted reading only where a scan cannot decide** (meaning-dependent hits). Optional one-off
cover: ask one narrow question of every sermon, e.g. "does this sermon contain material that is not
the sermon?", which costs about 1–2% of a week with subagents.

**5. After the round: acceptance.** Scripted checks on every regenerated output, plus §5's
independent sample (min(P, max(30, ceil(0.10 P))), about 44 services, Sonnet or Opus, blind). Reuse
the same bundles and prompts for the changed hashes only. Not a read of every output. The missing
sermon-opening class check (1027) is carried as a scan in step 1 plus this sample.

**Out of scope here:** Pass C fresh transcription (window count and cost first, then the operator's
word), production census and rehearsal, browser and mobile delivery, speaker/thumbnail/P5 wording,
and bulk listening pages (page only what needs the operator's ear after triage).

**Work estimates:** use measured usage and operator effort for the chosen bounded scope. Historical
allowance/cost projections are in the archived snapshot and must not be used as current budgets.

**Deliverables:** an operator summary of new classes with mechanism, affected runs, fix lead and
whether each blocks the next round; machine-readable findings, coverage and sample ledger in
`storage/scratch/output-review-202611xx/`; a ruling or disposition for every flag; a readiness
statement against §0's four results; and the post-round acceptance re-run plan.

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

The 24-case calibration did **not** establish cheap-only clearance: Luna/medium found 5 of 8
registered material defects, and stronger text review could not settle two audible boundary misses.
The operator approved wider lead discovery with verification, not automatic acceptance of passes.
The 20-service pilot found four supported material issues through Luna and an independently found
missing sermon opening (1027); the affected-class check and disjoint follow-up remain open.

The later 50-service batch and partial next batch bring dated discovery coverage to 94 services.
Exact findings, flags and modality limitations are preserved in the
[audit evidence snapshot](../archived-plans/HISTORIC-POST-ROUND-AUDIT-EVIDENCE-2026-10-29.md#calibration-evidence-and-scope-of-wider-use)
and `storage/scratch/overnight-20261023/audit/`. These are discovery observations, not a corpus
failure rate. Historical unfinished membership is archived; the current status above records which
work was stopped or superseded. §7 tracks unresolved leads. Revalidate packet/output hashes before reusing any review after processing.

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
known cash price; no speculative dollar estimate. For any authorised bounded review, record usage where exposed, checkpoint at account
limits and report incomplete coverage. Resume only when instructed; the old discovery-run
authorisation is not a standing instruction to restart archived batches. Do not redeem resets or buy credits automatically. Keep
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

Update this document's §7 acceptance ledger and the video plan's §4.5 status; preserve rulings
under its §4.6. The song protocol remains authoritative for its measurement.
Browser/access checks use the existing staging and production acceptance lane. This audit's source
sample supplements, and does not silently replace, the predeclared release-membership sample.

## 7. Acceptance ledger and reconciled obligations (2026-10-29)

This is the single output-acceptance worklist, consolidating video-plan §§4.3/4.5. Procedures are
in §§0–6 above; production authority belongs to the programme. The current status above reconciles recorded checks through 11-06; this cleanup
made no fresh census. “Revalidate” means closure was not established, not that an old case remains
a confirmed defect. Recount historical populations and rebind evidence after outputs change.

| Work remaining | Completion evidence / scope |
|---|---|
| Source-to-output completeness and alignment | Every eligible MP3/video pair and clip mapped to source identity/hash and ordered executed spans, final hashes and stored object identity; expected talks/readings/songs, Scripture enrichment and downstream artifacts accounted for. Include each source audio channel and exact/near-duplicate searches by hashes, fingerprints and transcript similarity without first matching date/title/reference. |
| Independent review and populations | §5 apparent-pass sample and missing-opening class check/disjoint follow-up; fresh release-membership sample across eras, partial/composite/split sources and repaired/previously held services. Declare seed, selection probabilities, denominators and limits before inspection. Separately draw 15 held runs across all dimensions so clearing one hold does not certify the rest. Keep targeted challenge cases separate from estimates. No general historic held-out set survives (H9); the song protocol has its own frame/contamination rules. |
| Coverage and lineage | Per dimension: exact eligible membership/hash, checked/unassessable counts and reasons, instrument/version, thresholds, date, input/output hashes, lineage, limitations and disposition. Split mixed/partial populations; report no-independent-OoS services separately (152 was the old count), with era boundaries informed by observed codec/channel transitions. Distinguish intended from performed content. |
| Complete media and delivery | Pass A full decode, every assembled join, corpus alignment, §0's five complete-service journeys and representative browser/codec/channel/mobile coverage; playback, middle/end/join seeking, resume, downloads and old-cache replacement. Repeat representative delivery/object checks in the authorised destination observation window. |
| Controlled variations and recovery | Preserve the original variation contract: leading silence shifts times but not identity/order; split/rejoin preserves inventory; missing OoS increases uncertainty without invented content; wrong planned song produces disagreement; equal-duration recuts replace all dependent artifacts; one-channel/cancellation-prone audio preserves speech or flags inability. Test interruption at extraction, replacement upload, row linking and verdict preparation for consistent generations and effective holds. The 09-14 equal-duration fix and later held-reprocessed cases are completed evidence, not proof all interruption paths passed. |
| Detector/transcript evaluation | Source-adjudicated positives, negatives, false negatives and unassessable results, uncertainty and review burden against predeclared criteria. Reconcile H10's 90 transcript-negative runs and H10b's dated 208-window queue with later answers; do not repeat already valid listening. Independently verify recovered service/sermon text, short-loop candidates and fluent meaning errors. Each class in `resources/detector-classes.json` needs regression evidence and a tested response or explicit decision not to automate; mock tests do not establish model quality. |
| Song acceptance and current policy | Complete the [song protocol](HISTORIC-SONG-ACCEPTANCE-MEASUREMENT-2026-10-11.md): stage 2/M2/M4 and replacement of stale stage-1 results where bindings changed. Verify starts/tails, clean negatives, retained singing and adjoining speech against real output. Refresh existing candidates inside their owning staging context; policy decisions and holds must describe current artifacts. |
| Final editorial fields | Ground title, slug, reference, series, speaker, songs, talk type, occasion, summary and points in evidence, checking meaning-changing transcript errors. Compare displayed incumbent fields with current banked analysis and final media; a fresh analysis need not overwrite populated fields. Named speakers require verification; unresolved identities use honest wording under P3. |
| Speaker proposals and unresolved identity | Reuse the 19 confirmations in `storage/scratch/preacher-proposals-20261017/answers/rulings/`; verify which have been applied before proposing changes. Preserve three can't-tell answers and the rejected 2026-03-29 visiting-speaker inference. Verify all current named assignments, including previously unchecked sermons 845/850; old 423-default/16-named and 13-model-attribution counts are dated, not current membership. Speaker automation remains disabled; no retraining is authorised here. |
| Thumbnails and partial recordings | After editorial QA, explicitly generate deferred thumbnails for exact release membership on the correct holding disk; verify frame within current spans, image quality and current text, including existing thumbnails. P5 disclosure must work on pages, feeds and downloads as applicable. The 10-29 code review found podcast summary wording absent and MP3 tags unchecked; verify/build/test before releasing partial recordings. |
| Exposure and release handoff | Asset inventory, Scripture outcomes, quarantine visibility and notification containment verified; deliver accepted membership/holds to programme §§0.4/7/8 for production reconciliation, Bundle A, hymn convergence, signed release, observation and rollback. Do not duplicate the programme procedure here. |

### 2026-11-05 current-output investigation

The [archived inventory](../archived-plans/HISTORIC-POST-ROUND-AUDIT-EVIDENCE-2026-11-06.md#2026-11-05-current-output-investigation)
contains exact populations, hashes and initial findings. Later corrections take precedence:
container readability and audit disk resolution were repaired; the promotion fix is built but
the ten stale generations still require verification. Scripture refresh and degraded-analysis
outcomes are video §4.1b work. The 421 default-speaker / 395 blank-thumbnail counts are historical
editorial inputs, not refreshed release membership. A4 treats video peak overshoots as reported
treatment issues; 1055's measured +5.6 dBTP needs diagnosis, while MP3 and integrated-loudness
misses keep their separate policy. Do not infer audible clipping from the number alone.

### 2026-11-06 complementary media, identity and delivery checks

Evidence: `storage/scratch/output-independent-20261106/summary.json` and the
[archived checkpoint](../archived-plans/HISTORIC-POST-ROUND-AUDIT-EVIDENCE-2026-11-06.md#2026-11-06-complementary-media-identity-and-delivery-checks).
The six services were 18431/1006/1059/1015/1307/1093; recorded hashes matched their checked files.
No sampled growing drift or channel cancellation was found. Runs 1049/1205 share sermon text but
support distinct performances; a duplicate-recording defect was not established. 1006's black
interval occurs in source too. 1215's lyric disagreement remains unassessable from text.
These bounded checks do not replace semantic audio, every assembled join, the independent sample
or actual browser/destination delivery. Rebind dependent evidence after any output changes.

### Named residue requiring current-evidence reconciliation

These old explicit cases must receive a traceable current disposition before their affected items
are accepted. They are not automatic instructions to rerun old repairs or clear holds. Follow
section identity through re-detection; an old section ID may now name different content.

- **September song queue:** the 27 boundary candidates plus §3869; §988/§1475, §2897 and §1457;
  72 deterministic identity cases and three fallback suggestions; 31 pending hint disagreements;
  run 1287 §3596 / OoS item 11981 / song #797. Reconcile against later matching, accepted rulings
  and actual output, then retain only unresolved residue. Preserve the original scope in the
  [10-29 video snapshot §4.3](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md#43-acceptance-evidence-still-owed).
- **Q12 ending cohort:** reconcile later listening/hold dispositions for 1224, 1111, 1198, 962,
  1005, 1240 and 1271; the register’s original 10-16 instruction is not a claim that all remain unheard.
- **Transcript/hold cases:** 929/1008/1068/1187/1317 and §3869/§988 were contained in September;
  later recovery may have changed their disposition. Reconcile rather than assume they still need
  repair. Verify the standing hold instructions for 945 §669 and 1342 §4319 against current state.
- **Audit discovery leads:** the current hash check governs reuse of 1011's cropped hymn evidence;
  1027's missing opening triggered the corpus-wide check. Preserve 967/975 attribution leads,
  1066/1148 omitted-reading leads, 932's prayer tail, 1096's conclusion/song-binding findings and
  1147's prayer-request discussion until adjudicated against current artifacts. Media uncertainties
  on 991/1280/1283/1323 and 1199 remain unresolved by text agreement. The frozen findings JSON,
  not this abbreviated list, is the exhaustive ledger.
- **Song-end misses (closed lane 11-09):** 2922 (too long), 173622 (first breath left),
  752 (unresolved), 1340 (a line of speech left), 175597 (a spoken word that voice separation
  cannot remove) and 677 (not the song's end). They are cut as the current rules leave them; the video
  plan §4.1d RA3e "Song-end lane closed" holds the evidence. Hear each in the round's output before
  acceptance. 677 is not a song end: it is the same song as § 678 (adjacent same-song work).
  Added 11-09 (operator): 336327 (ends halfway through the last verse: steady-music singing reads as
  talking on the voice track), 1897 (keeps "Well let's turn to"), 672 and 336013 (voice removal
  leaves the talking audible under the music).
  Added 11-10: 4616 (speech over the outro: end before it at about 72:32 or remove it; the outro ends
  about 72:36). Class B ends 2303 and 3666 (speech before a fade end) stay open in video plan §4.1d
  until class B has a design.

- **11-05/06 residual media leads:** 1307 §4665's faded song end; 1215 §2643's lyric disagreement
  at source 1980–2025 s; 1006's source black interval and its presentation consequence; 1059's
  benediction/song edge and 18431's announced final hymn. These need source/output evidence,
  not assumptions from announcement text. The 1015 freeze/speaker allegations were rejected.
- **Recent transcript holds:** 962 §846 and 1197 §2476 remain review items after the 11-04 repairs.
  Check current audio/text and hold identity; repeated words can be real call and response.

### Reconciled closures and relocated work

- Three duplicate/date adoptions were completed and their identity holds released in the 10-18
  record. Video §4.4 now asks only current-membership verification; unrelated quality holds remain.
- The 883/168-ruling capture backlog was superseded by full 10-23 capture/scoring and later updates;
  video §§4.1a/4.6 own current ratchet/unassessable work. A prior failing count is not today's backlog.
- Review-state re-derive/recompute/backfill/demotion was applied 10-25. Do not repeat it from the
  superseded “apply next” instruction; current-output song policy checks remain above.
- Access/content policy was decided 10-17. Programme §8 owns production enforcement and release.
- Q1 review UI/consequences, weekly parity, weekly regression evidence and published-clip audit live
  in video §4.2; source-master custody and post-release repair in §4.6; legacy transcription in
  programme §6. None is silently closed by removing a duplicate checkbox.
- Existing “not a defect” rulings are in the register: apply them instead of asking again. Only a
  new, consequential uncovered case needs a new operator disposition.

### Stopping rule

Under G8 (amended 11-06), each round states its bounded purpose and the evidence needed to close
that work. Compare the full eligible corpus, then reuse still-valid evidence; repeat model work,
rendering or listening for affected dependencies, missing evidence or declared independent samples.
Accepted soft misses and harmless editorial alternatives do not themselves start another round.
This minimises manual review without suppressing material uncertainty or bypassing the checks below.

Close acceptance only when every applicable row has current evidence, explicit coverage and
limitations; expected omissions are reconciled; cheap instruments have covered their eligible
membership; required independent samples, whole-output/media/delivery checks and controlled
variations are complete; detector/song criteria are reported without retrospective threshold
changes; and residual material uncertainty is contained or explicitly disposed of. Deferred or
inapplicable subsets need scoped reasons. Follow §0's agent-first escalation and retain expressly
human listening requirements. This is bounded confidence in these artifacts, not proof that unknown
classes cannot remain. Record completion in place; put detailed execution results in hash-bound
artifacts or a dated archive rather than extending this document with a session diary.
