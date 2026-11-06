# Historic video post-round audit

**Supplemental checkpoint — 2026-11-06:** two operator-requested Luna boundary-review rounds
covered 60 additional services / 640 sections on the frozen 11-05 packets. No new boundary defect
was confirmed. See [the review record and gap follow-up](#2026-11-06-supplemental-luna-boundary-reviews)
below; this does not clear outputs or replace the parallel source-audio, audiovisual, identity
and delivery review. The 11-05 status paragraphs below are dated context.

**Complementary checks — 2026-11-06:** the requested source/output media, identity, transcript
and local-delivery checks are recorded in [the complementary checkpoint](#2026-11-06-complementary-media-identity-and-delivery-checks).
No new processing defect was confirmed within that scope. Semantic audio verification and
published delivery remain incomplete; neither this checkpoint nor the boundary reviews clear release.

**Next work — scoped 2026-11-05, not started:** [pre-round defect discovery](#2026-11-05-revised-scope-sample-to-discover-scan-to-count)
replaces reading every service: scripted class scans over the whole corpus, a stratified sample
read until it stops yielding new defect classes, and acceptance after the next round.

**Status — updated 2026-11-05:** fresh output inventory and bounded exploratory review found stale
quarantine generations and container-unreadable clips; the complete batch is not production-ready.
See [the current-output checkpoint](#2026-11-05-current-output-investigation). The earlier initial
70-service discovery and luna-batch50-03's 24/50 reviews remain historical evidence, not current
acceptance; the 26 unfinished services have not been silently marked reviewed.
**Reconciliation required before the next audit batch (2026-10-29):** the discovery report is a
dated lead ledger, not a current defect count. Its run 1011 `supported_material` song-opening
omission still stands: the 10-24 class scan (`storage/scratch/class-scan-20261024/summary.json`,
`song-opening-lyrics-in-gap.json`) lists it as one of three real cropped openings (1011, 1240,
1345), "From Heaven You Came" §1285 losing 36.9 s of transcribed lyrics before its 519.73 s clip
start, with the mechanism not investigated. A 10-29 note that called this near-silent bleed cited
no recorded check. **Heard 11-01:** not a source limitation — a quiet solo voice from about 459 s before the band; ruled E25 (whole song from the first sung line, flagged); 1240 and 1345 are met by the operator's 10-24 by-ear starts. Fix planned in video plan §4.1a step 5, "Quiet sung openings". The 13
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

## 2026-11-06 supplemental Luna boundary reviews

**Scope and evidence.** The operator requested Luna agents to generate section-boundary leads
for coordinator assessment without disturbing the separate processing-output review, then requested
another round. Three `gpt-6-luna` agents per round reviewed timestamped source context around section
starts and ends, with expanded context for ambiguous transitions and uncovered speech. Round 2
used ±45-second windows; truncated combined reads were recovered with bounded per-service reads.
These were boundary-window reviews, not full line-by-line transcript reads or audio listening.
Agent counts and confidence were checked by the coordinator, not accepted at face value.

Inputs: `storage/scratch/output-quality-20261105/packets/{run}/packet.json`, `delivered.json`
and `source.txt`, from `crockenhill_audit_20261105`. Selection continued the existing
`storage/scratch/output-review-20261105/sample/sample-ledger.json` after its first 45 services:
round 1 ranks 46–75, round 2 ranks 76–105. This is supplemental operator-requested lead discovery,
not a reopening of that earlier sample's stopping rule or a new acceptance sample. Section counts
were checked from the packet JSON. All 30 round-2 source/delivered file hashes matched their packet
manifests; this verifies frozen-input integrity, not correspondence to later live generations.
No code, database, media, queue, processing or publication changes were made during these reviews.
The findings below preserve the agent messages and coordinator dispositions; no separate per-service
answer files or usage measurements were captured for these two rounds.

| Round | Agent group | Runs | Sections |
|---|---|---|---:|
| 1 | A | 1232, 1108, 1131, 1356, 1026, 1271, 1216, 1105, 1367, 1031 | 88 |
| 1 | B | 18432, 1345, 1100, 997, 1298, 1292, 1332, 1170, 1094, 1281 | 121 |
| 1 | C | 996, 1294, 1175, 1253, 1368, 1023, 944, 976, 1040, 1238 | 99 |
| 2 | A | 1325, 1177, 1240, 1317, 1114, 1030, 1218, 1076, 1334, 1225 | 123 |
| 2 | B | 1316, 1049, 1162, 1254, 1029, 1122, 1083, 1303, 986, 18433 | 94 |
| 2 | C | 950, 1208, 957, 988, 1058, 1284, 1259, 1318, 1239, 1135 | 115 |

Totals: round 1 **30 services / 308 sections**; round 2 **30 / 332**; combined **60 / 640**.
These counts describe inspected section contexts, including contexts with insufficient text;
they are not counts of boundaries proven correct. No new boundary defect was confirmed, and
no service was cleared for release by this exercise.

### Assessed leads

All times below are seconds in the source recording. A section edge and an extracted output edge
are different evidence; a transcript cue spanning either edge does not prove a clipped word.

| Run / section | Evidence | Coordinator disposition |
|---|---|---|
| 1271 / song §3391 | Section starts 919.68; output starts 920.92. Text names the song at 919.26–920.92, then says “let's stand to sing” through 922.26. | Uncertain spoken-introduction overlap. Text cannot locate the musical introduction; no timing correction established. |
| 1298 / song §175943 | Section/output start 3560; hymn words appear at 3545.80–3552.28, followed by another “Let's stand and sing” at 3552.38–3553.24. | Downgraded Luna's high confidence: these may be spoken lyrics. Retain only as an unresolved onset question if consequential; no earlier start justified from text alone. |
| 18432 / sermon §336060 | Section ends 4450, although final sermon words end 4407.92 and hymn setup follows. Output ends 4408.22. | Broad section extent does not establish output contamination; no output defect supported. |
| 1294 / songs §3679–§3680 | Frozen sections split “From Heaven You Came”; §3680 lies inside the declared 196–366 transcript gap. | **Correction to this session's first report:** already heard and resolved by the operator on 11-05: one continuous song, one clip. The split-song fix is built and awaits the next recomposition, as recorded in the video plan §4.1a item 8. Do not ask the same listening question again or count this as a new lead. |
| 1177 / sermon §2358 | Section starts 2409.64; repeated “Thank you” cues continue until about 2425.64; substantive speech begins at 2425.48 (“I'm not a pacifist”). | Unreliable repeated transcription, not proof of a late or early cut. No recommended timing change without audio evidence. |
| 1208 / between notices §2550 and song §2551 | Notices end 240.08; song starts 275.04. At 248.50–274.04 the leader introduces and quotes 1 Timothy 6 (“God, the blessed and only ruler…”), outside any section. | Retain a **classification lead**: a Scripture/call-to-worship interval is unlabelled. Not confirmed output loss or a reason to extend the song automatically. Already present in `scans/text/speech_in_no_output.json` under the 11-05 review directory. |
| 1259 / between reading §3232 and prayer §3233 | Reading ends 214; prayer starts 268.21. The unassigned interval reflects on worship with the heavenly assembly (“We don't simply worship God as a small group…”). | Retain a **classification lead**: substantive worship reflection, with a prayer invitation at the end. Decide whether it needs a section under the existing taxonomy before treating it as omitted extractable content. Already present in the same automated gap scan. |
| 1135 / after sermon §2109 | Sermon ends 3722.20; song §164709 starts 3750. The intervening speech names the next songs and asks people to stand. | Ordinary song announcement; rejected as a boundary defect. The following song interval has a declared transcript blind spot. |
| 1049 / song §174158 | Section starts 3862.28; earlier hymn words are followed by another invitation at 3866.26–3867.88. | Rejected as a new defect: quoted lyrics do not prove singing. Existing `sentence-listening-20261026:174158-start` heard 3866.22 and records `expect: keep`; no new contrary audio evidence. |
| 1325 / prayer §4121 | Prayer starts 908.16; 884.36–892.54 is a handover asking Richard to lead prayer. | Ordinary handover, with the prayer beginning “Shall we bow our heads in prayer?” No boundary correction supported. |

No further concrete leads survived these reviews. This is not a claim that all other sections
are correct, and it does not resolve the existing defect classes or independent acceptance work.

### Transcription gaps: proposed follow-up

**Missing text is missing evidence, not proof of missing audio.** Intervals without transcript
cues may contain music, silence or speech. Keep these distinct from explicit `unobservable_windows`
in packet metadata. Do not repeatedly send inadequate text to stronger models or move a boundary
across a gap merely to reach the nearest available cue.

The checked packet-declared blind spots include:

| Run | Source interval(s), seconds | Recorded reason / disposition |
|---|---|---|
| 1294 (round 1) | 196–366 | `retranscription_failed`; song continuity already heard and ruled, as above. |
| 1177 (round 2) | 524–533 | `repetition_retry_empty`; distinct from the later repeated “Thank you” cues. |
| 1316 (round 2) | 2439–2591 | `retranscription_failed`. |
| 1239 (round 2) | 261–381; 2192–2376 | `retranscription_failed`. |
| 1135 (round 2) | 3749.06–3961.06 | `retranscription_failed`; follows the song announcement. |

The round-2 list is complete for its 30 packets: four services, five declared windows. Round 1's
1294 entry is a checked example, not a completed round-1 gap census. **Correction to the round-2
progress message:** it said “five services”; the actual count is four services / five windows.
Ordinary no-cue intervals reported by agents are not additional declared transcription failures.

Next work, proposed rather than performed or authorised by this documentation update:

- [ ] Build a deduplicated gap queue from the frozen packet metadata, recording run, source hash,
  interval, affected section/output edge or join, existing ruling, consequence and disposition.
  Include consequential garbled-text leads separately; do not label every no-cue interval a failure.
- [ ] Reuse applicable source-listening rulings before asking for new evidence. For 1294, check the
  eventual recomposed output against the settled one-song decision instead of reopening continuity.
- [ ] Prioritise gaps touching sermon speech, section edges or assembled joins. Route precise
  intervals with surrounding context into the **existing parallel source-audio review**; avoid
  duplicate media jobs or another independent processing round.
- [ ] Establish from actual source audio whether each consequential interval contains speech,
  singing, silence or missing/corrupt source sound. Record modality and source/output generation.
  Lack of speech transcription alone establishes none of these categories.
- [ ] Where audible speech matters, propose bounded retranscription under Pass C's existing
  authorisation rule. Preserve the original text and hash; retain source-time offsets and record
  the backend. Supply no inherited transcript as a prompt. Verify recovered words against audio
  before using them to change sections; a second decoder is corroboration, not proof.
- [ ] Keep unresolved intervals explicitly unassessable. Accepted source limitations, recovered
  transcript evidence and supported boundary corrections get separate dispositions. Any later
  processing change must invalidate/recheck dependent evidence against the new generation.

This queue complements the other session's four investigations: source audio versus transcript;
internal audiovisual quality (frozen frames, drift, channel loss/cancellation); content identity
across services independent of labels; and actual playback/seeking/download delivery across MP3,
video and metadata. The Luna reviews neither performed nor cleared those checks.

## 0. Wider release confidence and who does the checks (2026-10-29)

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

Keep the pre-freeze checklist bounded. During the authorised staged canary, take one representative
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
| A: inventory and media | Every eligible output and every excluded/held disposition | Start from the close's reconciliation (693 outputs, 0 missing, 0 live duplicate pointers, 0 failed jobs; hashes in `storage/scratch/close-final-round-20261022/`) and re-check only what it did not cover: duration versus executed spans, streams, partial-recording flags and late approvals. Decode-check all final outputs with throttled I/O, never during a cut; count failures/unreadable files explicitly. |
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

### 2026-11-05 revised scope: sample to discover, scan to count

**Operator decision (11-05):** do not read every output. October's per-service review was paused
after 94 services because it kept finding examples of classes already known, while the corpus-wide
class scans (10-24) found and counted the real classes. This scope replaces "every eligible service"
in Pass B for discovery. It is planned, not started; it authorises no processing, publication or
production write, and it makes no paid API calls.

**Purpose and order.** Before the next round: find defect *classes* not yet known, each with an
affected population and a mechanism, so the code is fixed before re-running (fix the mechanism, not
the instance). The round regenerates most outputs (405/423 changed last time; K16 v3, the split-song
snap and the ten stale generations all land at the next recompose), so current outputs serve
discovery, not acceptance. Acceptance is checked after the round, on the regenerated hashes.

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

- Media: throttled `ffmpeg -v error` full decode of the 872 main files and 1,276 section clips (two
  jobs, never during a cut; time the first 20 files before estimating the rest, roughly overnight);
  `historic-import:verify-output-generations --hash`.
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
  returns one line, with at most 4–6 concurrent and a checkpoint. Keep prompts and bundles as files,
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

**Expected cost and time (estimates from packet sizes, not measured).** No API spend. Claude Code
allowance: about **15–30% of a week**, almost all Opus build and adjudication; the Haiku subagents
add under 2%, and the post-round Sonnet sample about 5–8%. Calibration: the 10-16 → 10-23 week that hit the weekly limit priced at
about $321 at API rates (Opus $312, Sonnet $8, Haiku $1, from the local transcripts), so a week ≈ $320
in that unit. Activity outside these transcripts, such as claude.ai chats, also counts, so the
percentages lean high. Keep Opus context small: adjudicate in fresh sessions from packed evidence
files. About 1–1.5 days elapsed, plus the overnight decode. Measure on calibration plus the first
batch of 15, then report actual usage before continuing.

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
failure rate. Current checkpoint and unfinished membership remain below; §7 tracks unresolved
leads. Revalidate packet/output hashes before reusing any review after processing.

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

Update this document's §7 acceptance ledger and the video plan's §4.5 status; preserve rulings
under its §4.6. The song protocol remains authoritative for its measurement.
Browser/access checks use the existing staging and production acceptance lane. This audit's source
sample supplements, and does not silently replace, the predeclared release-membership sample.

**Superseded 11-05 by the [revised scope](#2026-11-05-revised-scope-sample-to-discover-scan-to-count):**
discovery no longer schedules every remaining service. The text below is kept for its obligations
(sermon-opening class check, full decode, assembled joins, Pass C), which the revised scope carries.

**Next execution slice (10-29):** schedule the remaining frozen services in queues of 50–100, reusing
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

## 7. Acceptance ledger and reconciled obligations (2026-10-29)

This is the single output-acceptance worklist, consolidating video-plan §§4.3/4.5. Procedures are
in §§0–6 above; production authority belongs to the programme. The 10-29 reconciliation used later dated
records, not fresh corpus checks; the explicitly scoped 11-05 checkpoint below adds current checks.
“Revalidate” means closure was not established, not that the
old case remains a confirmed defect. All counts below labelled historical must be recounted.

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

**Decision: not ready as a complete production upload/release batch.** This is a fresh local
inventory and exploratory quality check, not a replacement for the acceptance protocol above.
The [video worklist §4.1b](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md#41b-exploratory-pre-round-findings-2026-11-05)
owns the repair actions, including the earlier scripture-refresh, audit-disk and degraded-analysis
findings. No processing records, media assets, holds or publication states were changed.

**Frozen evidence:** `storage/scratch/output-quality-20261105/`, with a consistent 328,119,170-byte
SQL snapshot restored into the separate read-only application schema `crockenhill_audit_20261105`.
`snapshot.sha256` binds that copy; code was `88afb351b` plus the prior two characterisation tests.
The working database and existing audit schema were not replaced. `service-audit:packets` generated
442 current packets. Four explicitly excluded runs (955, 1043, 1051, 1098) were removed from the
following output inventory: **438 non-excluded completed runs, 436 main sermons and 1,276 section
outputs marked pending/approved/published**. That section population includes held/unapproved
content and is not release membership. Two adopting runs have no separate main sermon. Historical
media entries for ineligible sections are not counted as required outputs.

| Dimension | Checked evidence | Result and limit |
|---|---|---|
| Main media presence and streams | `main-media-probes.json`: actual sermon-row disk/path, 436 videos and 436 MP3s | All 872 files exist and ffprobe succeeds. This does not prove full decode, synchronisation, semantic quality or destination delivery. |
| Main output generation | Every main video's size compared with the recorded new output; all ten size mismatches hashed against quarantine and staging (`quarantine-main-generation-mismatches.json`) | Ten stale quarantine videos: 1025, 1055, 1133, 1199, 1214, 1286, 1299, 1305, 1325, 1349. Every expected new video survives in staging with its exact recorded hash. Seven differ by >1 s in duration (1.64–39.30 s); the other three prove duration-only checks are insufficient. The other main videos were size-checked, not exhaustively hashed. |
| Dependent audio/text generation | Both locations hashed for all ten (`stale-main-audio-transcripts.json`) | The first seven above also have older quarantine MP3s and transcripts; 1305/1325/1349's audio/text hashes agree. Recorded new cuts and actual served files therefore describe different generations for seven services. All ten retain replacement authority; their prior `historic_promotion` totals are not evidence of the new generation. |
| Section asset access | 1,276 active section paths resolved on their recorded disks and owning batch roots (`asset-inventory.json`) | 21 paths on 16 runs are unreadable inside Docker with errno 20, “Not a directory”; their parent entries nevertheless list as directories. All 21 host files exist and match recorded hashes (`host-container-reconciliation.json`, `container-path-errors.json`, `host-missing-candidate-hashes.txt`). No loss is established. This is additional to `audit:section-assets` omitting `asset_disk`. |
| Semantic discovery | Seeded six-service draw: 18431, 1006, 1059, 1015, 1307, 1093; separate targeted reference challenges 936/1010 | Luna/medium read complete source text, saved source-first outlines, then compared delivered text/metadata/executed spans. Six current sermon hashes match their recorded outputs. These eight text reviews are not the stratified independent apparent-pass sample and do not estimate a corpus defect rate. Source/derived text share ancestry. Selection, exclusions, packet hashes and per-service reviews are retained in `selection.json`, `packets/`, `reviews/`. |
| Bounded decode checks | All six sampled MP3s and their videos' complete AAC streams; one complete audio/video decode (1015) | 12 audio decodes and the full 1015 audio/video decode return zero with no decoder errors. `sample-full-audio-decodes.json`, `run1015-full-av-decode-result.json`. No normal-speed semantic listening, full-corpus video decode or browser/mobile delivery certification is claimed. |
| Audio treatment | Retained measurements plus fresh continuous EBU measurement of 1055's current first 55.38 s | 68 per-part rendition misses on 58 runs (53 video, 15 MP3; five include integrated-loudness misses). Actual 1055 AAC measures +5.6 dBTP, confirming the reported large overshoot (`run1055-current-peak.log`); it is not proof of audible clipping. A4 treats video peak as a target, not an automatic hold, and does not waive MP3/integrated misses. Diagnose the pre-AAC versus decoded-AAC transient before choosing a fix. |
| Editorial/release fields | Current local snapshot | 421 non-excluded main sermons still use default “Visiting Speaker”; visitor status is not proven by that default. P3 remains open. 395 main thumbnail paths are blank under deliberate P2 deferral, and three recordings are marked partial; these need the planned editorial/thumbnail/P5 delivery work, not speculative identity assignments. |

**Confirmed generation mechanism.** The ten mismatches are exactly the November round's ten
section-candidate failures repaired by `sections:recut`. Its standalone
`PrepareSectionPublicationCandidates::finishStandalonePreparation()` marks completion and
dispatches `CleanupTemporaryFiles`, bypassing `PromoteHistoricAssets` in the normal full chain.
The new main outputs were produced before section preparation failed. Recutting the sections
successfully did not complete that main-output custody transition. For example, 1055's stored
plan/video evidence says 1853.57/1853.59 s, while quarantine holds 1888.21 s. Preserve the staged
set; repair the completion/recovery contract and verify actual destination hashes for the exact
recovered generation before accepting it. No recovery or additional paid analysis was run.

**Content leads and adjudication:**

- **1307 §4665, song end:** current file hash `d9fadd21c3b678885a27bea3663686ab3ffa76b9060f7489261067a60e771a99`
  and 160.5 s duration match the recorded 3860.12–4020.62 cut. The retained v16 edge decode puts
  “Just a little” at 4019.36 (zero-duration tokens), “reminder” at 4019.51–4020.62, then “before”.
  The cut therefore reaches into the following announcement according to decoded timing, under
  a 1.5 s fade. The initial coarse cue at 4012.32 overstates the speech overlap; do not report an
  eight-second audible leak. This is an E24 candidate for actual faded-edge verification and
  investigation of zero-duration opening words; no existing ruling on this particular end was
  found. `run1307-song-end-words.json` retains the evidence. No new by-ear ruling was made.
- **False positives rejected:** X5's “1015 was preached by the operator” names **sermon 1015 /
  run 1088**, already assigned manually, not sampled **run 1015 / sermon 950**. Its alleged
  speaker contradiction is withdrawn. Sampled run 1015's 2.98 s start already has an operator
  `right/keep` ruling; the broad first cue does not override it. Both corrections are retained
  in `reviews/1015-review.md`, not silently removed from the discovery history.
- **Reference differences are leads, not errors:** 118 stored-versus-banked strings differ, often
  because a chapter and verse range describe the same passage. Targeted source reviews support
  stored 1 Peter 4:7–11 on 936 and John 18:28–40 on 1010; the alternate passages are thematic/other
  readings. Do not overwrite incumbent metadata from AI disagreement alone.
- **Unresolved small leads:** 1059's final benediction/song edge and 18431's announced final hymn
  need source/scope reconciliation. Announcement text does not prove singing was recorded, and
  absence of a standalone benediction clip does not by itself violate the output contract. Keep
  these as candidates, not confirmed missing-content defects. No substantive sermon argument
  omission was established in the six sampled services; this is limited, correlated text evidence.

The new access-failure membership is: 941 (§754/759), 988 (§1149/173672), 1009 (§1274), 1038
(§4805), 1127 (§2043/2046), 1217 (§2670), 1222 (§2736), 1232 (§2864), 1233 (§2877), 1253
(§4722), 1275 (§3446), 1279 (§3498), 1298 (§3813), 1313 (§3976), 1328 (§176153), 1379
(§4623/4625/176320). Keep host bytes intact while repairing the container view.

**Still unestablished:** final release membership and current song acceptance; whole-corpus
semantic/independent review and every assembled join; full-media and real-browser delivery;
verified speaker/partial-recording presentation; final thumbnails; fresh production identity
census and rehearsal against production-only content; destination object identity and signed
release. The next processing round can supply new candidates but cannot itself certify these
dimensions. No upload or release was attempted or authorised by this investigation.

### 2026-11-06 complementary media, identity and delivery checks

**Disposition: bounded checks complete; content/delivery acceptance remains incomplete.**
These operator-requested checks complement Claude's **three batches / 45 services**, which stopped
after two consecutive batches found no new classes, and the separately recorded supplemental
boundary reviews. They are not another six-service semantic discovery round. The earlier full
decode completed on **2,127 files with zero errors** (`output-review-20261105/STATUS.md`); it was
not repeated here and does not establish semantic quality or playback delivery.

Evidence is under `storage/scratch/output-independent-20261106/`, indexed by `summary.json`.
Frozen text/identity evidence uses the 11-05 packets; actual media checks ran against retained
source and quarantine files, with source/output hashes recorded, at code `3f4da2ea6` using
FFmpeg 6.1.1. Local HTTP checks used the running app and read-only local record queries, not
production. No application code, media, publication state or production data changed; no fresh
transcription or paid-provider call was made. Luna performed bounded identity, transcript and
delivery investigations; direct media measurements and lead adjudication were separate.

| Dimension | Coverage and result | Limit / disposition |
|---|---|---|
| Cross-service identity | 442 frozen packets; 1,931 recorded output media hashes are unique. The 3 unhashed outputs are excluded sermons 1043/1051/1098. Corrected exact eight-word overlap scan across 438 service transcripts found 26 pairs: 25 longest matches are Scripture; 1049/1205 share sermon exposition. `identity/findings.json`, `identity/overlap-pairs.json`. | These are recorded hashes, not a fresh hash of every file. Runs 1049 (2025-11-09) and 1205 (2023-11-12) share a VE Day anecdote and Romans 5 material, but differing introductions, durations and segment structure support distinct performances. Intentional reuse is undetermined; no wrong-recording defect is established. |
| Source/output audiovisual correspondence | Reused the six seeded services 18431/1006/1059/1015/1307/1093 for complementary measurements: 20-second windows at 10%, 50%, 90% of each output (18 windows), MP3/AAC/source audio comparisons and 18 source/output frame pairs. All six actual video hashes match frozen packets; files remained unchanged during checks. No sampled channel cancellation, rendition mismatch or growing timing drift; MP3/AAC measured lag was zero, source/output audio lag at most 25 ms. `media/summary.json` and underlying results. | This is a bounded sample, not a corpus rate or full audiovisual certification. Numerical audio alignment and frame correspondence do not establish spoken meaning or correct lip-sync in the original recording. |
| Visual leads | Run 1006 is black through the sampled 131.235–151.235 s interval in both source and output; audio is nonzero. Its later static interval also appears in source. Run 1015's freeze-detector flags at 162.399–182.399 s were not supported by the 100-frame source/output comparison: both show movement and closely corresponding frames. | Record 1006 as a source visual limitation, not a new extraction defect or automatically accepted limitation. No recut is justified by these findings. The 1015 detector lead is closed without a frozen-output defect. |
| Retained separate transcription | Three runs checked against retained H10B source decodes and delivered mappings/text. Delivered sermon interiors on 1330 (source 2138–2183 s) and 1069 (779.24–824.24 s) agree after text normalization. The 1330 announcement concern at 180–225 s is outside delivered membership, so it is not output loss. `text/source-decode-comparison.json`. | No semantic audio listening occurred; related ASR ancestry can correlate errors. Run 1215's song §2643 has divergent lyric text at 1980–2025 s and remains unassessable from text. Its initial 1393.49–1438.49 s window mostly overlaps another delivered song (§2639), not an omitted interval. |
| Local delivery and exposure | Six local HTTP requests: health 200; sermon listing 500; nonexistent audio 404; selected published audio 404; quarantined audio GET+Range and video HEAD+Range both 404 without media bytes. Debugbar identifies `HistoricStagingUrlGuard` rejecting the configured `historic_staging` default in the listing. No published media rows explicitly use local `public`, and no published video rows were available. `delivery/check.json`. | The local staging configuration prevents a usable published-media candidate. Actual 206/range, seek, resume, download, rendered metadata and browser/mobile delivery remain unverified. This is not evidence of a production outage; quarantine must remain protected. |

**Corrections retained in the evidence:** the initial zero lexical-overlap result was invalid
because timestamps entered tokenization; the corrected scan strips them and reports 26 pairs.
Missing segment arrays are **32 entries (3 sermons and 29 sections)**, not 30 missing media
hashes; the 29 section hashes exist. The initial 1330 window attribution and 1215 membership
description were corrected against actual mappings. Use the final dispositions above, not the
intermediate leads, when counting defects.

**Remaining work in the existing acceptance lanes:**

- Pass C: semantic source-audio verification remains open. Triage 1215 §2643's lyric disagreement
  for a material effect on identity, boundaries or presented text; if consequential, settle the
  bounded source/output interval with audio-capable evidence. Transcript voting cannot close it;
  fresh transcription retains the existing authorisation rule.
- Passes A/C: retain 1006's black-source evidence in its visual-quality disposition and assess any
  presentation consequence before release. Continue the existing whole-output/join and broader
  media coverage; do not treat these 18 windows as their replacement.
- §7 delivery / programme observation: use an appropriate isolated published-media environment
  for playback, seeking, range responses, download identity, rendered metadata and replacement
  freshness, then perform authorised destination checks. Resolve the local listing/configuration
  obstacle for that rehearsal without making staging or quarantine public.
- Rebind dependent checks after the next round changes artifacts. The six-service measurements,
  old full-decode result and frozen packet hash comparisons do not automatically certify new bytes.

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

Close acceptance only when every applicable row has current evidence, explicit coverage and
limitations; expected omissions are reconciled; cheap instruments have covered their eligible
membership; required independent samples, whole-output/media/delivery checks and controlled
variations are complete; detector/song criteria are reported without retrospective threshold
changes; and residual material uncertainty is contained or explicitly disposed of. Deferred or
inapplicable subsets need scoped reasons. Follow §0's agent-first escalation and retain expressly
human listening requirements. This is bounded confidence in these artifacts, not proof that unknown
classes cannot remain. Record completion in place; put detailed execution results in hash-bound
artifacts or a dated archive rather than extending this document with a session diary.
