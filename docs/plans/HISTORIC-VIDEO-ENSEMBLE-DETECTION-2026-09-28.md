# Ensemble structure detection

**Status — 2026-10-02: BUILT, evaluated and canaried; this is the design authority and the register
of operator decisions on what the ensemble compares.** Built from `92040d273` (2026-09-29), evaluated
over 232 paid draws (§6 evaluation, 2026-09-30) and run as canary 9 (2026-09-30: detection passed,
custody failed and was fixed in `1210d534e`). Decisions 12–20 were each measured by replaying saved
draws before adoption (decision 10). Decisions 21–23 (2026-10-02) prepare the final canary.
Execution, the canary bar and the batches live in the
[historic plan's §4.0](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md).

The pre-build evidence (§1), the code this plan changed (§2), the test specification (§5), the §6
evaluation and the canary 9 record (§7) moved verbatim to the
[2026-09-24 to 10-02 execution log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md);
section numbers are kept so references to §3–§9 still hold. It belonged to the
[detection reliability work package](../archived-plans/HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md),
now complete and archived. It is a change to the **routine** detection pipeline: historic and
weekly runs behave identically, following the standing ruling that historic work improves routine
processing and gets no special path.


## 0. Operator decisions this plan implements (2026-09-28)

1. **Four equal draws, not a primary plus opinions.** Run two `gpt-5.6-luna` and two
   `gpt-6-luna` detections of the same inputs with the same prompt, in parallel. A spot where they
   all agree is accepted. A spot where they disagree is flagged for review. A different model is
   wanted because it is more likely to hold a different opinion than a second draw of the same model.
   On the canary sample the operator preferred 5.6's version, but that need not hold across the wider corpus.
2. **Compare the whole structure**, not only talks: talks, sermon, songs and readings.
3. **Do not pick one draw to write.** The written sections are a **consensus composed from the
   draws**, so nothing publishable rests on one draw's say-so. 5.6 breaks ties only.
4. **Delete the validation retry** in favour of voting. A draw that fails validation does not vote.
   The retry is the path that produced canary 8's false talks on 949.
5. **Encode operator rulings as equivalence rules.** First: a sermon start that differs only by
   the prayer before the sermon counts as agreement (ruled acceptable either way, 2026-09-28).
   **After each review, recurring rulings become candidates for further principles.** A local
   correction is immediately reusable on matching evidence; generalisation requires observable
   preconditions, counterexamples and measurement against saved corpus evidence before adoption.
6. **Run the draws in parallel.** Wall time should be the slowest single call, not the sum.

### Operator decisions after the review (2026-09-29)

7. **Split the delivery.** Canary 9 tests detection: the ensemble (DR2), per-draw evidence (slim
   DR1) and the bounded evaluation (DR4). The answer → correction → replay loop (DR3) is required
   before batch 1, not before canary 9. Canary-9 disputes are answered and scored, not used to
   correct output.
8. **Canary bar (Q5) adopted before canary 9:** zero **unflagged** talk-count errors and every
   dispute answered. Pre-review accuracy (flagged errors included) is still reported separately.
9. **ER1 is general, not local.** Including or excluding the preacher's prayer is always acceptable.
   Two sermon starts that differ only by a span some voter types as prayer are agreement; no
   unanimity on the prayer and no prior local ruling is required. The span is not the prayer case
   if any voter types it as a song or reading. Composition picks one start, never a midpoint.
10. **Rule adoption:** after each batch, a recurring ruling is proposed as a rule, replayed over the
    saved draws with the before/after diff shown, and adopted on operator approval with a fixture.
11. **Whole-job retry redraws all four slots** and keeps the previous bundle as evidence. No
    per-slot resume.
12. **Hold timings to agreement only where they change a cut (2026-09-29).** Filler (welcome,
    prayer, notices, other) is compared on its edges only inside the cut window (earliest sermon
    start − 120 s to the latest first song after a sermon, or sermon end + the pairing gap);
    elsewhere it matches on ≥50% overlap and is never a question. A song matches on ≥50% overlap
    plus identity; only the start of each voter's first song after its sermon is held to ±15 s.
    Presence and identity disagreements are still questions.
13. **Readings follow the same principle (2026-09-30).** Only a reading the sermon could be cut with
    is held to ±15 s on both edges: one ending before a voter's sermon and within the pairing gap
    (900 s), narrowed to those matching the sermon's own reference when any does, since that
    outranks all other pairing evidence. Any other reading matches on ≥50% overlap and is never a
    question about its timings; a reading one voter lacks, or a reference disagreement, still is.
    Replay on the saved draws (which carry no references, so the narrowing cannot show): reading
    questions 0.81 → 0.66 per service on p2, 0.72 → 0.53 on p3; every one left is a presence split,
    two-thirds of them the preached reading where one voter's edge is more than 15 s out.
14. **A song bound to an order-of-service item is that item's song (2026-09-30).** Voters binding the
    same item agree however they spell its title; the title is compared only for an unbound song.
    Different items, bound against unbound, and presence splits remain questions. Replay over the
    232 paid §6 draws (`--replay-draws`, no calls): song questions 1.71 → 0.77 per service
    (exactly the 45 same-item splits removed, none added); talk and cut results unchanged; song
    edges now come from a different supporting voter in 9 of 58 compositions (0–20 s, one 55 s
    mid-service song; the sermon-ending song start moved 1 s and 8 s, inside ±15 s); 1108's third
    run becomes the first composition with no question, its cut matching truth.
15. **One edge disagreement is one question (2026-09-30).** Two flagged groups of one type that
    share no voter and mostly overlap become one dispute offering every version, anchored to the
    written group (two written groups stay apart).
16. **A reading's introduction (2026-09-30).** Reading starts that differ only by the leader's
    introduction (≤60 s, no song, talk or sermon in it, no other reading ending in it) are one
    reading. It starts with the introduction when the introduction names the reading's own book
    (spoken forms: "First Corinthians", "Psalm/Psalms"), after it when it does not; always one
    voter's start. Needs the transcript; without it the old ±15 s comparison applies.
17. **Filler is asked only where it changes the cut (2026-09-30, extends 12).** A welcome, prayer
    or `other` question is dropped when the production resolver plans the same cut with each
    disputed group written and left out (`CutAwareEnsembleComposer` via `SermonCutProbe`).
    Notices are always asked. Applied in the job, the replay (answers, replay command) and the
    evaluation.
    Replay of 15–17 over the 232 paid draws: questions 4.46 → 2.33 per service (other 0.85 → 0.08,
    prayer 0.25 → 0, reading 0.90 → 0.48, talk 0.83 → 0.50, sermon 0.48 → 0.33); 949 ×10 5.0 → 3.0;
    compositions with no question 1 → 8 of 48, every one's cut right; talk-count errors 0/0; two
    cuts moved by rule 16, both as ruled (1112 includes "Luke chapter 17, from verse 11";
    949 excludes a handover that names no passage).
18. **A cut is judged by the cut the truth would produce; a sermon's closing prayer is preferred
    in it (2026-09-30).** The scorer places each ruled talk and sermon span (every acceptable
    sermon span) on the proposal, neighbours giving way, and has the production resolver plan
    that cut; the proposal's cut is right when it lands within the sermon's tolerance of any of
    them. Cuts held by policy either way are reported as unscored with their reason, not
    scored. Operator: "Including closing prayers in sermons should be actively preferred" —
    stronger than the 2026-09-28 talk ruling (either way): a sermon truth ends after its closing
    prayer, with no alternative ending before it. Only 1358 was ruled short of one (3203 → 3323).
    Replay over the 232 paid draws, structures and questions unchanged: cuts wrong 5 → 0
    (1050 ×2 plan the same 0–920 cut either way; 1358 ×3 now match); 7 cuts (949, 1250) held
    either way on `no_high_confidence_sermon_section`; talk-count errors 0/0; no wrong cut
    reaches extraction unreviewed.

19. **Canary 9's answers become four rules (2026-10-01).** Measured against the 41 answers and the
    saved draws before adoption; the operator approved all four.
    - **A. Overlapping references are one passage** ("Psalm 95" = "Psalm 95:1-7", "Philippians
      3:4-9" = "3:4b-9") unless they would pair the sermon with different readings: a reading
      reference is checked against every voter's sermon reference and vice versa.
    - **B. An unbound song is the catalogue song its title names** when `SongTitleResolver`
      matches it deterministically (not fuzzy or hymnbook-absent): "Jesus Saves" = "We Have
      Heard a Joyful Sound". Untitled versus titled stays a question (1311 and 1304 were ruled
      opposite ways).
    - **C. A short talk's proposed type is not compared**: only the type confirmed in talk type
      review is ever published.
    - **D. A three-to-one vote decides.** Only ties and three-way splits are asked. Where truth
      was ruled the majority was never wrong (26 canary-9 answers; 31 talk/sermon splits in the
      §6 draws); the one structural mismatch (936's Psalm 100, unpairable) changes no cut.
      Each decision is kept as `majority_decisions` for a skim list, stays answerable (an
      answer outranks the vote) and holds nothing; filler the cut probe finds neutral is left
      off it. Contested sermon absence is still asked.
    The composer never compared free-text titles or exact edges: the canary-9 "either" answers
    were reference granularity, unbound song titles and three-to-one edge splits, and the music
    detector found no music in any disputed gap (closing-song gaps are the spoken announcement).
    Replay, no calls: canary 9 41 → 7 questions (24 on the skim list; with the answers, 0 open,
    0 conflicting, all valid; 10 "either" answers now match no question). §6 232 draws:
    questions 2.33 → 0.77 per batch-1 sequence, 949 ×10 3.0 → 1.0, sermon questions 0.33 → 0;
    talk-count errors 0/0, cuts wrong 0, wrong cuts reaching extraction unreviewed 0.

20. **A reading or recording inside a talk stays a question (2026-10-02).** Canary 9 ruled 964's
    children's talk one talk including its Deuteronomy 6 reading (573–1166; its closing prayer on
    the talk's theme optional, so 573–1054 also right) and 1112's talk one item including its
    filmed testimony. The 09-28 prompt clause "a passage read after a talk has concluded is its own
    bible_reading" had been added on a misreading of 964; the truth file now holds the ruling.
    A prompt defining "concluded" and adding recordings was evaluated (24 calls, $0.12, 964 and
    1112 × 3): no effect. gpt-5.6-luna splits 964 in 6 of 6 draws under either prompt and
    gpt-6-luna keeps it whole in 6 of 6, so the composition is a 2–2 question every time; 1112 is
    one talk throughout. Operator: accept it as a question; the prompt change was not adopted.
    With the corrected truth the §6 draws score 4 of 60 talk boundaries wrong, all flagged.

21. **A talk edge every draft agrees on is asked where it touches other speech (2026-10-02).**
    Agreement cannot show an error every draft shares, and the 2026-09-28 ruling asks about every
    talk edge within 3 s of a speech section (welcome, prayer, notices, reading, talk, sermon,
    `other`; never music). `TalkEdgeChecks` adds each such edge on an undisputed talk as an
    ordinary question whose one version is the composed talk, so it holds the run like any
    question and its answer, confirming or correcting, carries by scope. Measured before adoption
    (no calls): canary 9 with its answers 7 → 12 questions, every check a talk ending into a
    prayer; the 232 §6 draws 0.77 → 1.19 questions per batch-1 sequence, 23 → 15 of 48 sequences
    question-free; none on an edge the truth file marks wrong. **Operator adopted 2026-10-02:**
    exempt a talk's end running into prayer under the closing-prayer ruling. Prayer before a
    talk and other speech edges remain checked; disputed talks and interruptions still follow
    their existing questions. The 232-draw replay removes 12 of 20 checks, leaving 0.94 questions
    per batch-1 sequence and 20 of 48 question-free; zero unflagged talk-count errors and zero
    wrong scored cuts (45 scored, three accepted holds). No provider calls; evidence at
    `storage/scratch/carryover-20261002/prayer-exempt-report.json`.
22. **Answers reach the cut from the draws they were given on (2026-10-02; implements §3.9).** A
    recompose request makes the job compose the latest banked draws again under the current rules
    and every answer, with no provider call, then validate, sync and review as after fresh draws.
    It refuses rather than draws when the attempt is not the latest or its input no longer matches
    the run. Measured: canary 9's answers over the 58 saved §6 sequences of the same recordings
    left 11 re-rounds asking again (6 lost drafts, one 0.49 overlap, 6 new content questions); a
    recompose asks none of them. Fresh draws remain the route for new text, new items, a prompt
    or model change, and a whole-job retry (decision 11).
23. **The RMS log is banked by reference (2026-10-02; §3.6).** An attempt's input names the log by
    the path and hash it already recorded instead of copying 11–16 MB; a draw or replay reads it
    back and refuses if it changed.

Agreement never overrides an existing content hold. The implementation guarantee is that every
decision affecting extraction has supported evidence, and unresolved disagreement reaches the
actual extraction gate. The technical policies below implement this alongside the operator's
preference for deterministic correction/replay rather than further model calls.


## 1–2. Evidence and the code this plan changed

Moved to the [execution log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md)
(pre-build measurements from saved draws, and the 2026-09-28 code). Read the code itself for its
current shape.

## 3. Design

### 3.1 Draws

- New config `media-processing.service_structure.ensemble` holds an ordered list of models,
  default `['gpt-5.6-luna', 'gpt-5.6-luna', 'gpt-6-luna', 'gpt-6-luna']`. Assign immutable slot IDs
  before dispatch; completion order must never decide a tie. The first-listed model is the
  tie-break model, with slot order resolving residual ties between its own differing draws.
- Use explicit per-call model/options on the detector interface, including the mock, rather than
  changing global configuration. Reasoning effort, prompt, inputs and requested service tier are
  identical across slots apart from model; record the actual tier separately after flex fallback.
- Snapshot or content-hash-bind the transcript, RMS, audio timeline, recording identity/duration,
  recording-song policy and attested OoS before dispatch. Children verify the same snapshot;
  do not let independent live reloads turn input drift into apparent model disagreement.
- Bank raw response, parsed structure, pre/post-refinement structure and validation outcome.
  Each draw uses the production `detect` → `snapToSilences` (including `SoundStage`) → `validate`
  path. Composition and replay use those same deterministic services, not scratch copies.

### 3.2 Parallel execution

- Prefer isolated subprocess draws with a controlled runner using the installed process facilities.
  Collect outcomes individually, including parent-enforced timeouts and abrupt exits; catching
  exceptions inside a closure cannot handle a child killed by the parent. A bad child must not
  discard sibling results. Explicitly stop/reap children on normal failure and cancellation, and
  test parent termination/orphan handling in the actual worker environment.
- Capture staging context as serialisable data plus scalar run/slot IDs. In a fresh child,
  reconstruct it and enter `HistoricStagingContextRegistry::within()` before resolving storage.
  Do not serialize activated global config or the registry: the guard needs the child's pristine
  baseline. Queue lifecycle hooks do not run for an invoked closure. Verify artifact disk identity
  and private storage in each child; context mismatch is a failure, not a fallback to another disk.
- Set explicit connection/request and process deadlines (initial candidate: 300 s per draw), with
  a whole-stage deadline below the 900 s job timeout and margin for composition/persistence.
  Flex fallback and any explicitly requested recheck share the remaining budget. A recheck cannot
  add the configured 900 s HTTP timeout after an ensemble. Verify worker/queue timeout alignment.
- Return and durably persist each slot's outcome independently: valid, invalid, unavailable, or
  interrupted/unknown, with usage and latency where known. Use isolated writes/atomic aggregation
  rather than concurrent read-modify-write of the run's metadata. A lost response can have incurred
  provider cost; do not report unknown usage as zero.
- A whole-job retry (crash or timeout) runs a fresh four-draw ensemble (decision 11); the previous
  bundle is kept as evidence. Within one ensemble an invalid draw is never redrawn. Final
  persistence still checks the run revision so a stale worker cannot overwrite a newer result.
- Bus batches are an alternative only with an explicit worker-topology change: the historic LLM
  queue defaults to one replica, so four queued draws would otherwise be sequential. Never block
  its sole worker awaiting work on that same queue. Concurrent HTTP would need equivalent async
  flex fallback, usage handling and isolation. Do not introduce either architecture speculatively.
- Check peak memory, Sail/CLI process behaviour, cancellation and rate limits before rollout.
  Account for four concurrent prompts plus output budgets, rather than assuming single-call limits.

### 3.3 Eligibility, replacing the validation retry

- A draw **votes** only if it passed validation.
- **Fewer than two votes** → the existing manual-review path (`llm_structure_validation_failed`)
  with every draw's proposal persisted. In reconcile mode, the existing sections are kept as now.
- Two or three valid votes permit a reviewable composition, but record a **degraded ensemble**.
  Do not label it four-draw agreement or allow unattended adoption of affected extraction/absence
  decisions until reviewed. Record surviving model coverage, especially two votes from one model.
  Any future relaxation needs measured evidence and an explicit policy decision.
- Structural validation is not semantic correctness. Preserve validation flags on eligible draws;
  agreement cannot silently turn an already-held proposal into an unflagged one.
- Delete `detectionWorthRetrying()`, `detectionRetryFeedback()` and the retry branch of
  `detectWithPrimaryRecovery()`.

### 3.4 Claims, alignment and agreement

- A **claim** includes every fact used to choose or describe published content:

  | Claim | Compare |
  |---|---|
  | Short talk | Presence, both edges, talk type and output-relevant binding |
  | Sermon | Presence/absence, both edges, preached reference, continuation and pairing decisions |
  | Song | Presence, identity and OoS binding; boundaries only for the start used to end a sermon (decision 12) |
  | Bible reading | Presence, reference and OoS binding; both edges only where the sermon could pair it (decision 13) |
  | Adjacent filler | Type/bounds wherever these change inclusion or stopping of an extraction span (decision 12) |

- ±30 s talk edges remain the initial alignment tolerance, not proof that every cut in the interval
  is acceptable. Overlap (including the old ≥50% of the shorter span) is a candidate-match signal,
  never sufficient agreement for songs/readings. Predeclare boundary tolerances and semantic
  identity normalisation before evaluation; justify them against accepted cuts, not desired flag volume.
- Align one-to-one across draws with at most one claim per slot in each cluster. Every member of
  an agreed cluster must agree with every other member. Do not use transitive closure: starts at
  100, 125 and 150 s under ±30 s do not form an agreed cluster. A long reading matching two shorter
  readings is a split/merge dispute, not two votes from the same slot.
- Specify and test deterministic assignment/tie ordering before implementation is accepted.
  Where competing assignments materially change support or output, preserve the alternatives as
  an alignment dispute instead of manufacturing a majority. Results must be invariant to arrival
  order and input array ordering when immutable slot/claim IDs are unchanged.
- A spot is agreed only when all eligible voters support its output-relevant claims; absence of a
  claim, type conflicts, splits, merges, identity/reference/binding differences are disputes.
  Degraded-ensemble review in §3.3 applies separately even if surviving voters agree.
- Canonicalise identities with existing deterministic song/Scripture resolvers and version the
  rules. Null versus a claimed identity is not automatically agreement. Free-text wording is not
  an exact-string vote; titles/summaries use the provenance rule in §3.5.
- Equivalence rules run before agreement, with explicit evidence, scope and version. A disputed
  model label alone must never prove the condition used to suppress that same dispute.

  | Rule | Authority and conditions | Measurement required before general application |
  |---|---|---|
  | ER1: pre-sermon prayer may be included or excluded | Operator rulings 2026-09-28 and 2026-09-29 (decision 9): always acceptable, general. Applies when the span between the two starts is typed prayer by at least one voter and no voter types it song or reading. | Replay on saved draws to list the disputes it removes; no adoption gate beyond decision 9. |

- Additional recurring rulings are candidates, not automatically general principles. §3.10 defines
  the replay, counterexample and regression requirements for adoption.

### 3.5 Composition

- Compose per claim, never adopt a whole authoritative draw. At agreed spots choose an actual
  supported boundary pair from the cluster (for example, the pair minimising total boundary
  distance to the others, then tie-break model/slot order). Do not independently average starts
  and ends. Under ER1 choose one complete accepted alternative: two starts before and two after
  a prayer must never average to the middle of the prayer.
- At disputed spots use the uniquely best-supported version as a **flagged proposal**, counting
  absence as an alternative. A tie prefers a supporting tie-break-model slot, then stable slot
  order; if that model is absent use stable slot order and retain degraded status. An unresolved
  assignment/split/merge remains reviewable evidence, not an unflagged arbitrary partition.
- Select a coherent semantic field bundle from supporters of the chosen claim; do not combine a
  reference, identity and binding no supporter proposed together. Compare these fields as claims
  before choosing the bundle. Select title/summary wording from its representative supporter with
  provenance, rather than treating four different paraphrases as four substantive disagreements.
- Define confidence conservatively (initial policy: minimum among the chosen claim's supporters),
  preserve relevant existing flags, and re-derive applicable deterministic flags. Keep dissenting
  evidence even when its section is omitted. Model confidence is not calibrated ensemble accuracy.
- Filler/notes may use the most representative supporter with stable ties only where this cannot
  change extraction. Where filler changes a cut, it participates in agreement. Rebuild/check
  chapter markers against composed spans. Sermon absence is an explicit voted decision, never
  copied as incidental metadata from the representative draw.
- Apply matching local rulings as explicit constraints, recording before/after and authority.
  Use shared boundary/sound refinement in a fixed order and track changes. Refinement must not
  create a new unsupported cut or erase an accepted complete alternative; re-evaluate support
  and dependencies after any movement. Start each replay from immutable evidence, not the last
  already-refined output, so repeated replay cannot drift.
- Validate the complete composition and derive its actual extraction plan. Structural validity
  alone cannot prove semantic agreement. If invalid, retain proposals for manual review; reconcile
  keeps the previous authoritative sections. No invalid composition reaches section sync.

### 3.6 Flags and evidence

- New `ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES` is raised on each written section at a
  disputed spot. For a claim that exists in only a minority of voters and is not written, it goes
  on covering sections. If no section covers it, retain a run-level unresolved dispute; do not lose
  questions in gaps. Catalogue disagreement/degradation signals and define their policy explicitly.
- Propagate each unresolved dispute to every dependent extraction/absence decision. A song-start
  dispute can hold the sermon cut; a reading dispute must not be bypassed by silently omitting it;
  a missing-sermon dispute cannot conclude the run as no-sermon. Prove these through the actual
  resolver/job/publication paths, including RMS fallback and manually reviewed paths. Keep valid
  existing operator authority and unrelated content holds intact.
- `service_structure_ensemble` references a private, immutable evidence bundle containing:
  - run/source identity; hashes or snapshots of transcript, RMS, audio timeline, OoS and policy inputs;
  - prompt/schema, code and deterministic-rule versions, requested models/effort/tier;
  - stable draw/attempt IDs, raw/parsed/refined structures, failures, actual tier, usage and latency;
  - alignment, alternative clusters, votes, degraded status and per-field/boundary provenance;
  - composed output, extraction-plan dependencies, disputes, applied rulings and rule versions.
  Store large bodies on the existing private service artifact disk, with references in metadata;
  back them up with the service artifacts before disposable scratch can be removed.
  *Since 2026-10-02 (decision 23) the input names the RMS log by its recorded path and hash rather
  than copying it.*
- Re-derive disputes without paid calls only when the evidence bundle is complete and still maps
  to current content. Missing/stale evidence means unresolved, not agreement. Extend the established
  re-derivation path with an evidence-aware ensemble pass; do **not** merely add the flag to
  `REANNOTATED_FLAGS`, whose current validator pass cannot reconstruct it and could clear it blindly.
- Rule changes produce a reviewable before/after diff with their version and affected decisions.
  Preserve historical proposals, rulings and holds; flag withdrawal is not publication approval.

### 3.7 Missing-reading recheck

- If **some** voters find the preached reading and others do not, that is a disputed reading
  claim and is flagged.
- When none finds it, first apply saved rulings and existing deterministic reference/pairing checks.
  Ask a targeted question only when the missing reading is consequential under existing policy.
- Remove automatic whole-structure adoption by the single-model recheck. If retained as an
  explicitly requested diagnostic, its response is **review evidence only**, within the remaining
  deadline/budget; it cannot replace the ensemble or clear its flags. No default fifth paid call.
  Any later automated recovery must meet the same claim/support and extraction gates.

### 3.8 gpt-6 payload

- Widen `OpenAiChatPayload::isReasoningModel()` to include gpt-6, then drop `temperature` and send
  `reasoning_effort`. A unit test covers the model-name table. The scratch driver's per-process
  patch is then deleted.

### 3.9 Deterministic replay and scoped recomputation

Model detection supplies evidence. Composition, boundary/sound refinement, validation, ruling
application, extraction planning and question generation form a shared deterministic replay path.
Evaluation invokes the same services without syncing sections, synthesising segments, clearing
holds, publishing or moving freeze state. Private evaluation artifacts are allowed and identified.

| Change | Required work |
|---|---|
| Equivalence, validator, alignment or composer rule | Replay saved raw/parsed draws with the new deterministic versions; no detection calls |
| New operator ruling | Recompose/revalidate affected content and dependent extraction decisions; no detection calls |
| Metadata-only correction | Update validated metadata/review state; do not recut unchanged media |
| Corrected media boundary | Recalculate the plan and use existing authorised re-extraction/publication controls |
| Model, prompt or reasoning settings | New explicitly scoped detection evidence; preserve the old bundle |
| Source/transcript/OoS change | Invalidate dependent results; remap rulings only with verified matching evidence, otherwise mark stale |

*Implemented for answers and rules 2026-10-02 (decision 22):* `historic-import:rerun-recompose`
asks the job to compose the latest banked draws again, with no detection call, for a run whose
answers or rules changed and whose input still matches.

Replay is deterministic and idempotent: same evidence/rules/rulings gives the same semantic
output; a second application causes no boundary drift, repeated recut or reopened resolved issue.
Timestamps in audit records do not count as semantic differences. Persist results only against
the run/content revision they were computed from. Extend existing services and evaluation tooling;
do not grow another scratch driver, historic-only pipeline or general-purpose rules framework.

### 3.10 Review answers as durable constraints and measured rules

Before scaling reprocessing, complete **answer → correction → deterministic replay → verification**.
Banking an answer or suppressing a question alone does not complete a repair.

| Ruling kind | Example | Authority |
|---|---|---|
| Content correction | This span is not a standalone talk; its end is this anchor | This recording and matching evidence |
| Accepted alternatives | Include or exclude this complete prayer | The enumerated alternatives on this content |
| General principle | A verified observable pattern admits the same treatment elsewhere | Versioned predicate, tested counterexamples and corpus measurement |

- Use stable content/question identity rather than `section_order` or row ID. Bind source identity,
  transcript hash, span, anchor, question scope, alternatives, answer, operator and ruling revision.
  A changed transcript/timebase or ambiguous remapping marks a ruling stale/conflicting. Approximate
  times alone cannot authorise reuse. Preserve superseded answers instead of overwriting history.
- Apply matching rulings to output, not just the question selector or score. Record original and
  corrected values; revalidate chronology, coverage, claim relationships and affected media plans.
  Contradictory rulings stay unresolved rather than silently letting the last writer win.
- Generate one specific question per underlying issue, grouping duplicate flags/model proposals.
  Resolve presence/type before edges/identity when those depend on presence. Rejecting a talk
  removes its now-irrelevant edge questions. A question remains attached to omitted claims too.
- Present the relevant clip and transcript with expandable context and actual alternatives.
  Keep model labels secondary. Support "both acceptable", "none correct", a specific correction
  and "cannot tell". Retain cannot-tell as deferred on unchanged evidence, not completed and not
  repeatedly rediscovered as a new question. Surface deferred/stale totals for the operator.
- Resolve only the question's named issue and its mechanically obsolete dependants. Do not use
  blanket section confirmation to clear other flags or content holds. The review UI must support
  the correction needed, including starts, splits/merges and omitted claims; the current short-talk
  end-shortening form alone is insufficient. Reuse shared components/actions and project UI skills.
- Recurring answers propose a rule (decision 10). Replay it over saved draws and show the operator
  changed outputs, cleared questions, changed extraction plans and any regression of a known-correct
  decision; the operator approves or rejects. An uncertain semantic pattern remains a targeted
  question rather than a brittle keyword/LLM-label rule.
- Keep regression fixtures for the ruling and its counterexamples. Adopt a rule only when its
  predicate and observed effects support the generalisation; repeat frequency alone is insufficient.
- After each batch report new/reused/deferred/stale questions, actual corrections versus accepted
  alternatives, questions avoided by rulings/rules, review time, refusals/degradation and known
  errors after replay. Fewer flags is not success if errors or unjustified clearances increase.

## 4. Deletions

- Validation retry: `detectionWorthRetrying()`, `detectionRetryFeedback()` and the retry branch.
- Today's uncommitted retry repair and guard: `sectionsDisagreeingOnTalks()`,
  `withRetryTalkChangesFlagged()`, `retryRecord()`, `FLAG_RETRY_CHANGED_TALKS` and its catalogue
  entry. Preserve their behavioural regression coverage in the ensemble scenarios below; the
  project rule against deleting existing tests still applies. Do not simply remove four guards
  because their implementation names disappear.
  - The 949 regression is preserved as an ensemble scenario: one voter produces the talks and
    the others do not.
  - The `service_structure_retry` metadata key is no longer written.
- Missing-reading recovery: remove automatic adoption of a single recheck's entire structure.
- Shadow mode: **defer deletion**. A voting ensemble member affects output; a non-voting candidate
  is an evaluation instrument. Delete the old mode only after its useful evaluation role has an
  explicit tested replacement, in a separately bounded change. It is not needed to ship this plan.
- Move reusable evaluation/review/replay logic into existing application locations. Retire scratch
  duplicates only after parity and artifact preservation; temporary tooling retains IC8 ownership.

## 5–7. Tests, evaluation and canary 9

Moved to the [execution log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md):
the test specification the build followed, the §6 evaluation (232 calls, $1.19: talk-count errors 0
of 58, every composition flagged before rules), its free replays under decisions 14–19, and canary
9. The rollout (canary 10, batches, stop rules) is the
[historic plan's §4.0](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md). The evaluation
command and its manifest remain: `structure:ensemble-evaluate {manifest} --replay-draws={dir}` replays
the bought draws free under any candidate rule.


## 8. Risks

- **Composition can be wrong despite validation.** Use supported boundary pairs and coherent field
  bundles, compare extraction dependencies, retain dissent and verify actual downstream decisions.
- **Shared errors remain invisible to voting.** The p3 table reports 25% error capture, not 25%
  shared errors. Targeted edge/weak-song checks and banked rulings help only where their selectors
  reach. A unanimously missed talk can still escape; do not claim completeness from no disputes.
- **Review volume:** 24–70% of services flagged before equivalence rules. Operator stance: volume is
  not an objection. Reuse local answers immediately, but do not lower correctness to reduce flags.
- **Stale rulings and over-generalisation:** approximate span matching or a rule justified only by
  a disputed model label can silently reproduce an error. Bind evidence and test counterexamples.
- **Process isolation and partial failure:** explicit budgets, durable slots, cleanup and real worker
  tests are required. Check capacity; a timeout is not a negative content vote.
- **Provider rate limits and flex availability** with four concurrent requests.
- **gpt-6 behaviour drift:** reported comparisons use p2/p3, not the current prompt. §6 measures the
  current candidate on the bounded sample, without claiming unseen-corpus accuracy.
- **Review tooling scope:** the current scratch bank/selector and broad confirmation action do not
  implement scoped durable corrections. Finish this loop before increasing processing volume.

## 9. Review dispositions and remaining decisions

| Question | Disposition from this review |
|---|---|
| Q1: per-draw model | Explicit per-call options; no global mutation (§3.1). |
| Q2: concurrency | Controlled isolated subprocess runner; prove partial failure/deadlines/staging with real workers (§3.2). |
| Q3: reading recheck | No automatic whole-structure replacement or default fifth call; optional diagnostic is review-only (§3.7). |
| Q4: flag replay | Evidence-aware deterministic re-derivation; no blind addition to the generic flag list (§3.6). |
| Q5: canary bar | **Decided 2026-09-29:** zero unflagged errors plus every dispute answered; pre-review accuracy reported separately (§7). |
| Q6: shadow | Defer deletion until a tested non-voting evaluation replacement exists (§4). |
| Q7: claim scope | Include identities/references, absence and all extraction dependencies; predeclare exact boundary/normalisation rules before evaluation (§3.4). |
| Q8: boundaries | Select supported pairs/complete alternatives, not arithmetic medians (§3.5). |

Before coding the composer, settle the deterministic matching objective, residual assignment
ties and per-claim tolerances in fixtures. These are implementation specifications to measure, not
permission to weaken the acceptance bar. ER1 is general (decision 9). Any relaxed degraded-ensemble gate,
expanded paid evaluation or changed rollout workload requires an explicit recorded decision.
