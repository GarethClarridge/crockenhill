# Historic import — rulings register

**What this is.** Every operator ruling on the historic import and video work that is **in force**,
one line each, with its date and where it was argued. Superseded rulings are kept in §13 with what
replaced them. Check here before treating anything as a gap, a defect or an open question: on
2026-10-16 three review findings turned out to be rulings already made (thumbnail deferral, the
song digital-zero bar, run 937's retirement).

**How to use and keep it.**
- Add a ruling when it is made: date, rule, source.
- Mark a replaced ruling superseded (§13) rather than deleting it.
- Per-run structure answers are not listed here. They live in each run's banked answers, in the
  detection-rulings export and in the listening pages' `rulings` databases.
- Run-level rulings that change membership or identity (exclusions, duplicate pairs, adoption) are
  listed in §10.
- IDs are this register's own. Older documents reuse letters: **"D1" means two different things.**
  The 09-03 decision record's D1 is "the detector may propose sermon absence" (§3 here); the cut
  plan's D1 is "what a sermon's media contains" (§5 here).

**Sources.**
- [Log 10-29]: [execution record through 10-29](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md).
- [Plan]: the current [historic video plan](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md).
- [Log 10-16]: the [execution log for 2026-10-03 to 10-16](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-10-03-TO-2026-10-16.md): Part 1 is the plan as it stood on 2026-10-16, Part 2 the session notes of 10-10 to 10-16, both word for word.
- [Ensemble]: [ensemble detection](HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md) §0, decisions 1–23.
- [Cut]: [cut from sections](../archived-plans/CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md).
- [Boundary]: [boundary consistency](../archived-plans/VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md).
- [Round]: [follow-up round](../archived-plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md).
- [Music]: [music and silence](MUSIC-AND-SILENCE-DETECTION-2026-09-25.md).
- [Talks]: [talks](TALKS-AND-SHORT-TALK-DETECTION-2026-09-18.md).
- [Songs]: [song acceptance](HISTORIC-SONG-ACCEPTANCE-MEASUREMENT-2026-10-11.md).
- [Programme]: [incremental convergence](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md).
- [Runbook]: [round runbook](HISTORIC-ROUND-RUNBOOK.md).
- "notes": a ruling recorded until 2026-10-16 only in session notes, first written into a plan here.
  The fix-round rulings of 10-12/13 are in [Log 10-16] Part 2.

## 1. Scope and governance

| ID | Ruling | Date | Source |
|---|---|---|---|
| G1 | One-person project: no control may require a second human. Roles are accountability fields, and one person may hold them all. | 08-14 | D10, [archived final import readiness](../archived-plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md) |
| G2 | **No historic-only pipeline behaviour.** The historic work exists to improve routine processing; new stages run in every pipeline that runs `DetectServiceStructure`. Historic-only operator tooling is fine. | 09-25 | [Music] §3 |
| G3 | **Repair only through the pipeline.** Fix the pipeline, then re-run the affected runs; never correct outputs, verdicts or media by hand. A hold through the hold path is containment, not repair, and is allowed. | 09-14 | [Plan] §1 |
| G4 | Public release is a separate, exact-membership, human-authorised act: never a side effect of processing, promotion, cleanup or bundle generation. Production mutation only as a programme round. | 08-14 | [Plan] §1; [Programme] §7–§8 |
| G5 | Weekly processing is paused until the historic work is done; record weekly bugs with the historic fixes rather than fixing them early. | 09-14 | notes |
| G6 | Services are livestreamed and already public: sending service media to third-party processing (transcription, LLMs) is a cost and capability question, with no privacy caveat. | 09-21 | notes |
| G7 | The review queue is the point. Do not minimise it as a goal; if something needs a human look, flag it. | 09-07 | [Talks] §2; notes |
| G8 | Re-run every eligible run (historic, completed, not superseded, not excluded), not only runs predicted to change. | 09-23 | [Log 10-16] §4.0 standing decision 1 |
| G9 | Every repair also protects future services: a run is repaired outside a round only as a named exception with its own reason. | 09-23 | [Log 10-16] §4 |
| G10 | Songs are permanently authenticated-only, never public. Standalone testimonies, prayer requests, prayers and private occasions are withheld by default; exceptions require a named operator decision. Prayers within agreed sermon boundaries remain included. Editorial review, explicit batch approval and provenance-preserving corrections remain required. This settles content/access policy, not licence coverage or any batch release. | 10-17 | Operator in conversation; [Programme] §8.4 |
| G11 | Service archive listings and detail pages are authenticated-only; there is no public visitor service archive. Service links, search and sitemap exposure follow that boundary. The separate sermon archive remains public. Era release never grants anonymous service access. Supersedes the programme's earlier public-service exposure assumptions. | 10-17 | Operator in conversation; [Programme] §8.4 |

## 2. Custody, storage and backups

| ID | Ruling | Date | Source |
|---|---|---|---|
| C1 | All historic sermon and song outputs stay quarantined until the operator authorises a release batch. | 08-29 | [Plan] §1 |
| C2 | **Quarantine and staging are single-copy, by decision.** Outputs are re-derivable from the Sonnics source archive; do not recommend backing them up. The "not yet durable" question matters only at release. | 09-13 | notes |
| C3 | Custody follows the disk: when the sermon disk is historic staging or quarantine, new sermons and song clips are quarantined and name that disk, whatever the lane (`MediaAssetPath::holdingDisk`). | 10-15 | [Round] §12 |
| C4 | The five stray thumbnail objects written to the production bucket on 10-15 stay where they are. | 10-15 | [Round] §12 |
| C5 | Service artifacts live on the internal disk and are rsynced to Sonnics after every batch or round. | 09-28 | [Runbook] §8 |
| C6 | Temp-file cleanup is paused locally (`MEDIA_PAUSE_TEMP_FILE_CLEANUP=true`) so restaged sources survive; unset it after the historic repairs. | 09-23 | [Plan] §4.6 |

## 3. Detection and structure

| ID | Ruling | Date | Source |
|---|---|---|---|
| D1 | The detector may propose that a service held no sermon; the operator confirms it. | 09-03 | appendix D1 |
| D2 | A fixed `ServiceOccasion` enum, shown on the public service page only once confirmed. | 09-03 | appendix D2/D3 |
| D3 | `structure_missing_preached_reading` is raised only when the sermon has no Scripture reference at all. | 09-03 | appendix D5 |
| D4 | Detection reads only independently sourced order-of-service items. Email stays out of the re-run, so the video stays an independent witness. | 09-24, 09-25 | [Log 10-16] §4.0 standing decision 3 |
| D5 | Holds follow their content; only code-found holds recheck themselves. Rules raise review flags or propose held sections, never retype or delete silently. Exception: Music ruling 2 (E14). | 09-23 | [Music] §3 |
| D6 | **A music span is judged whole: sung or wholly instrumental.** An instrumental introduction or ending belongs to its song; a spoken introduction is speech. **Amended 10-24:** a song's spoken introduction is optional, but its musical introduction is always included when detected (36 clips heard; 7 left out an introduction, some with first-verse lines too). **The introduction may overlap the talk before it:** "Some overlap is fine. An intro to the song can also be the end of the sermon" (10-24, 1149 §2219, 1154 §247599, 1163 §174925, 1174 §2341, where the planner had refused the operator's start into the sermon's span); not into another song or music before the service. | 09-25, 10-24 | [Music] §3; operator 10-24 |
| D7 | Pre-service and post-service music stays `other`, and the rules never act on a leading or trailing `other`. | 09-25 | [Music] §12 ruling 5 |
| D8 | Ensemble detection: four equal draws (two `gpt-5.6-luna`, two `gpt-6-luna`) of the whole structure, composed by consensus. No validation retry; a draw that fails validation does not vote. | 09-28 | [Ensemble] 1–4, 6 |
| D9 | A recurring answer becomes a rule only after a replay over saved draws shows its changes and the operator approves, with a fixture. | 09-29, 10-02 | [Ensemble] 5, 10; [Log 10-16] §4.0 standing decision 8 |
| D10 | A whole-job retry redraws all four slots and keeps the previous bundle as evidence. | 09-29 | [Ensemble] 11 |
| D11 | Timings are held to agreement only where they change a cut. Filler is compared on its edges only inside the cut window; songs match on ≥ 50% overlap plus identity. | 09-29 | [Ensemble] 12 |
| D12 | Readings: only a reading the sermon could be cut with is held to ±15 s; others match on ≥ 50% overlap. | 09-30 | [Ensemble] 13 |
| D13 | One edge disagreement is one question; filler is asked only where it changes the cut. | 09-30 | [Ensemble] 15, 17 |
| D14 | A three-to-one vote decides; only ties and three-way splits are asked (rule D). Contested sermon absence is still asked. | 10-01 | [Ensemble] 19 |
| D15 | A talk interrupted by a reading or prayer is flagged, never merged. A reading or recording inside a talk stays a question. | 09-28, 10-02 | [Log 10-16] §4.0 standing decision 5; [Ensemble] 20 |
| D16 | **Questions that never changed an output are not asked.** Talk-edge checks are no longer generated; talk ends differing only by a closing prayer agree; presence questions offer "either"; degraded coverage is recorded but never asked or held. | 10-10 | [Boundary] §6.3.8 |
| D17 | Unresolved composer disputes above 2.0 per run, before answers, stop a pass. | 10-10 | [Boundary] §6.3.9 |
| D18 | An `other` order-of-service item two sections share (slide deck, "Reading" placeholder) is a flag, not `duplicate_oos_item`. That flag keeps blocking the cut until its section is reviewed. | 10-14, 10-15 | [Round] §12 |
| D19 | The recompose guard admits a round's failed validation when the banked draws, with their answers, compose valid under the running rules (re-check, not an exception). | 10-15 | [Round] §12 |
| D20 | The staleness check ignores a section type that the run's own projection wrote onto the item. | 10-15 | [Round] §12 |
| D21 | **Split sermons are joined:** 1073 and 1268 publish only part of their sermon, the rest `other`. Built in `77d880b44`; application to current runs remains subject to the next round and its acceptance. | 10-14; status 10-29 | [Round] §12; [Log 10-29] §4.0 |
| D22 | 954's "Report from Men's Event" is a talk. The detection-only item-type fix was built in `affa64e11`; application and output acceptance remain separate. | 10-14; status 10-29 | [Round] §12; [Log 10-29] §4.0 |
| D23 | Speech with no transcribed line is marked as an unobservable window and asked about, without holding (F11, "mark + ask"). Uncovered sound that decoded to nothing is marked (I1). | 10-05 | [Boundary] §6 |

## 4. Questions, review and listening

Agent-first checking is the operator direction of 2026-10-29; the [audit §0](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md#agent-first-execution-and-escalation) owns its execution and escalation procedure.

| ID | Ruling | Date | Source |
|---|---|---|---|
| Q1 | One standard pattern: each question declares its consequence once, and every gate reads it. **Recorded as not built on 10-16; current implementation to verify** ([Plan] §4.2). | 10-05 | [Boundary] §8.2 |
| Q2 | **Hold or ask, by the mistake the output could contain.** Hold when it might include something it shouldn't; ask when it is right but might omit something at its edge. | 10-05 | [Boundary] §8.2 |
| Q3 | Nothing with an open question goes public, on either path. | 10-05 | [Boundary] §8.2 |
| Q4 | A question is judged against the output it doubts: text questions hold text, label questions ask, cut-content questions hold. | 10-05 | [Boundary] §8.8 |
| Q5 | No review state means nothing to hide: a sermon with no pipeline run or sections is public as before. | 10-05 | [Boundary] §8.8 |
| Q6 | A title variant is not a question. Normalise titles against the catalogue before composing. | 10-06 | [Boundary] §8.9 |
| Q7 | One question per clip: merge disputes that settle the same decision over one stretch. | 10-06 | [Boundary] §8.9 |
| Q8 | **Held sermons are cut with `--held-section`.** The hold stays; a release page then has the operator confirm each defect is gone. The operator approved the 62-run cohort listed in [Log 10-29] §4.0 decision 2, with a dry run per run and the recorded duplicate exclusions and routing. | 10-13; cohort approved 10-16 | [Round] §5 R4; [Runbook] §4.2; operator: "Recut the 62 held sermons" |
| Q9 | **A looped pre-recorded segment is a flag to listen to, never a trim.** A reading may repeat its first and last verse. | 10-14 | [Round] §12 |
| Q10 | Listening pages: ask which text matches, show service time, play at 3× by default, never scroll sideways, and build from the last working page. | 10-07 to 10-11 | [Runbook] §5.3 |
| Q11 | Explain the evidence before asking for a decision. | 07-05 | notes |
| Q12 | **Hold and review the known-bad endings:** runs 1224, 1111, 1198, 962, 1005, 1240 and 1271. Keep or apply content-defect holds and review against the source; a re-cut alone does not clear them. The operator confirms each ending defect is resolved before its hold is cleared. Holds applied and verified on 10-16 to sections 846, 1242, 1935, 165128, 146083, 175519 and 3397. Later listening may settle individual cases; reconcile current dispositions in the audit rather than treating the original cohort as wholly unheard. | 10-16 | Operator: "Hold and review the known bad endings"; [Log 10-29] §4.0 decision 5 |
| Q13 | **A listening ruling is evidence, not absolute truth.** Some are mistakes; some knowingly accepted an imperfect cut (a whispered extra word judged not to matter). When a ruling contradicts a design decision, or blocks a fix that follows one, ask the operator to clarify or put the old and new cuts on a listening page; don't revert the fix or re-baseline the fixture silently. Re-heard answers supersede the older ruling in the register. | 10-26 | Operator: "we shouldn't take my rulings as absolute truth … If my rulings contradict my design decisions then ask me to clarify or listen again." [Log 10-29] §5 "The guard's moves heard again" |
| Q14 | Use 3× playback for triage, then normal speed for final judgments about a word edge, fade or joined audio. Record whether acceptance came from operator listening, model/text review or mechanical checks; text evidence alone does not establish how a cut sounds. | 10-29 | Operator agreement to plan review, 10-29 |

## 5. Sermon composition

| ID | Ruling | Date | Source |
|---|---|---|---|
| K1 | **A sermon's media is** its reading (if separate), the sermon, and a concluding prayer (if before the post-sermon song), as named sections (cut plan "D1"). | 10-02 | [Cut] §5 |
| K2 | **Amended:** also the prayer directly before the sermon, even with a song between it and the reading. Gaps of up to 15 s between selected parts, with no other section in them, play straight through, and each part keeps its own sound treatment. | 10-11, 10-13 | [Cut] §5 |
| K3 | Including or excluding the preacher's prayer at the start is always acceptable (ER1). | 09-29 | [Ensemble] 9 |
| K4 | **Closing prayers are actively preferred in sermons.** | 09-30 | [Ensemble] 18 |
| K5 | A non-matching reading stays joined to the sermon (the nearest one, across a hymn if need be). | 09-14 | [Log 10-29] §4.3 |
| K6 | Every reading directly before the sermon is cut with it, except a carol service's lessons (December, four or more readings), plus passage matches further back. **Amended:** a passage match takes the readings read back to back with it (1270's Job 11, 1302's Deuteronomy 24 and Matthew 19; `3189dc6a3`). An earlier reading of the sermon's chapter across a song stays out: 1059's Luke 23:1-25 and 1033's Luke 2:1-7 are recapped by the sermon itself. | 10-13, 10-23 | [Round] §6, §11; operator 10-23 |
| K9 | Pattern B: a recording that opens with the sermon (first section, ≤ 5 s in) publishes that sermon. | 10-13 | [Round] §11 |
| K10 | Pattern C: a reading the sermon stops for and resumes after stays inside it. Prayers, and parts preaching different passages, stay questions. | 10-13 | [Round] §11 |
| K11 | Pattern D: a low-confidence reading directly before the sermon is cut with it; low confidence holds neither. | 10-13 | [Round] §11 |
| K12 | Sermon versions differing only at their edges, with no other section claimed there in any draft, are one sermon at its widest edges (`c870b1471`). | 10-12 | [Log 10-16] Part 2, fix round item 2 |
| K13 | A sermon part whose speech stands < 20 dB above its pauses is held, never dropped. | 10-10 | [Boundary] §6.3.8 |
| K14 | **Use the longer recording for the service, but never let its sermon replace a better recording's** (higher resolution, then bitrate; the held sermon ≥ 95% of the length). | 10-15 | [Round] §12 |
| K15 | Two answers that share a seam both keep it: the seam stays in both clips. | 10-14 | [Round] §12 |
| K16 | **A sermon ends with its own complete concluding thought and closing prayer where present, before the next song's music.** A bare song announcement, housekeeping and the congregation's separate answering Amen are optional to omit. The shorter sermon ending is preferred, but a substantive concluding application must not be cut merely because it also introduces the song. Reading lyrics aloud alone does not establish that they conclude the sermon. Do not split a sentence or the preacher's Amen to achieve a shorter cut. This narrows the 10-16 tie rule rather than restoring it: the question is whether a line is still the sermon, not whether an introduction relates the song to it. Notices or housekeeping before a song still end the sermon at its own last line. The model is asked twice, and a third time when the two answers differ in judgement, sermon line or video line; the line two answers name stands, else the middle of three, else the earlier. **Heard 10-29 (11 tied cut-aways):** the current prompt, which defines `sermon_last_line` as before "any introduction or announcement of the song", loses the conclusion in 1131 and 1183. **A rewording was measured on all 116 tied ends and parked (10-29 evening):** it mends 1131 (1183 is fine either way on a second hearing) but re-splits 968's sentence, and adopting it re-asks every K16 end; 1131 stays a recorded miss, to be folded into any later K16 prompt change ([Log 10-29] §5, 10-29 evening). A line left open on a clause before the song's recorded start steps back to the line before (1001). Banked answers need not be asked again wholesale. **Softened 10-29 night:** a cut that drops the closing prayer is not preferred but acceptable while the end still makes sense; one that drops the sermon's last point is not acceptable. Quoted lyrics of the next song are its introduction (956). **Asked as sentences 11-04 (item 6, operator "Yes, do that but document the side effects"):** the question's lines are cues joined up to a sentence end their own text shows (. ! ?, an Amen ending a cue or opening the next); a run with no such end stays as raw cues. The decoded edge words are not used: they are banked after K16 answers. A line straight after the congregation's answering Amen steps back to the preacher's Amen (1101's "Thank you."). Side effects (589 of 616 questions re-asked, 13 ends better and 5 worse of 558 answered, the regression set's captures) are in [Plan] §4.1a item 6. | 10-16; amended 10-28, 10-29 (twice) and 11-04 | [Log 10-29] §5, 10-28 sermon-ending work and 10-29; operator 10-29: "a substantive concluding application must not be cut merely because it also introduces the song … only keep if it's fundamentally part of the sermon that happens to mention a song"; operator 10-29 night: "Dropping the sermon's closing prayer is not preferred, but it's probably fine as long as the end still makes sense. Dropping the last point is not acceptable." |
| K17 | **Speech left unowned beside the reading before a sermon is not a reason to hold the sermon.** When a song trim leaves speech next to the reading, `structure_sermon_adjacent_speech_unowned` becomes a note on the sermon, not a hold: it is almost always an announcement, which K1 rightly excludes (7 of 7 heard). Speech left beside the **sermon** itself still holds it (3 heard: an announcement, the sermon's own words, a late start). | 10-25 | Operator: "stop now". [Log 10-29] §5 "10-25 (later)" A4 |
| K18 | **The preacher's introduction before the sermon's reading is in the sermon's media, however long** — including one the structure types `other` (often titled "Sermon introduction"): 18434 (220 s), 1224 (185 s), 942 (155 s). It is sermon speech, not an announcement, so K17's "unowned speech is an announcement" does not apply to it. A leader's handover naming no book stays out (T4). Found by the pre-round discovery sample (`talk_opening_lost`, video plan §4.1b). | 11-05 | operator 11-05 |
| K19 | **A talk or reading start may drop only an opening invitation, and keeping it is preferred.** "Well, do have your Bibles open at Luke chapter 13." before 1137's sermon (cut at 49.62, heard right in canary 13) is acceptable to drop, but the start that keeps it is the better cut. Dropping anything more — the talk's own words (1136) or the reading's announcement (1308) — is a defect. Found by the pre-round discovery sample (`spoken_start_snaps_past_opening`, video plan §4.1b). | 11-06 | operator 11-06 |

## 6. Edges and cutting

| ID | Ruling | Date | Source |
|---|---|---|---|
| E1 | Picture starting after its audio is not acceptable: smart cut (re-encode from each cut point to the next keyframe, copy the rest). | 09-14 | [Plan] §4.3 |
| E2 | **Any sound/picture drift is a defect, and a cut is the identified sections**, with no second set of times. | 10-02 | [Cut] §1 |
| E3 | No section edge falls inside a transcript cue, whatever step caused it. | 10-03 | [Cut] "final spoken-cue invariant" |
| E4 | **Missing content is the defect; overlap is acceptable.** A cue shared by touching sections belongs to both outputs. | 10-03 | [Cut] "whole shared cues" |
| E5 | An output edge sits in a pause between words, never inside a word. A decode that returns no words keeps the whole-cue widening; missing cached evidence is a blocker. | 10-03 | [Cut] "word pauses at output edges" |
| E6 | Uncovered-speech checks look at interior gaps only. | 10-03 | [Cut] "C4 scope ruling" |
| E7 | A song that runs into speech ends with a 1.5–4 s fade of its sound, not a refused cut. | 10-07 | [Boundary] §6, canary 13 |
| E8 | A spoken end leaves tail room after its last word (≥ 0.3 s, at most half the pause; `ca0192710`), and an end moved into a sentence stops at the sentence end it passed (`746d5b956`). | 10-12 | [Log 10-16] Part 2, fix round item 9 |
| E9 | The song ending a sermon may start with or without its spoken introduction (≤ 30 s, nothing spoken between). A song clip keeps its spoken introduction at any length, never held. **Amended 10-24 (D6):** a musical introduction is not optional: when detected it is in the song. | 10-10, 10-24 | [Boundary] §6.3.8; operator 10-24 |
| E10 | **Dead feed:** a spoken edge in ≥ 2 s of dead feed (≤ −80 dB) moves to the sound: a start to 0.3 s before the first sound ("2 seconds is fine at the beginning"), an end to where the feed goes dead. Never reintroduce the clipped endings fixed on 10-12. | 10-15 | [Round] §12 |
| E11 | **Finished runs are re-cut** wherever the replayed plan differs from the cut at all, with no tolerance; song and talk clips too. | 10-15 | [Round] §12 |
| E12 | A clip with no recorded cut is re-cut, not reused. All 155 pre-fix runs go into the re-cut round, and its fresh comparison decides. | 10-16 | [Round] §12 |
| E13 | Use VideoToolbox (host ffmpeg service) for section cuts. | 10-13 | [Round] §2 |
| E14 | **Music ruling 2:** a song edge that runs ≥ 15 s into verified digital zero (every frame `-inf`) moves to where the zeros begin, with no hold. Song starts additionally follow E18; song ends and the 15 s dropout/whole-song policy remain unchanged. | 09-25; clarified 10-16 | [Music] §12; [Log 10-29] §4.0 decision 4 |
| E15 | **Video whole or not at all:** release the whole video when ≥ 75% has usable picture, otherwise hide it and release audio only. Never trim dead stretches; flag "has video issues". | 09-22 | notes; `release_usable_share` |
| E16 | Whole-recording cards count as no video; 1225's partial video is shown. | 09-14 | [Plan] §4.3 |
| E17 | A concatenated recording can contain a song: keep a song section the evidence supports, and demote only join fragments. | 09-14 | [Plan] §4.3 |
| E18 | **Song starts:** remove a leading prefix of ≥ 2 s of verified digital zero, keeping 0.3 s before the first finite audio sample, however quiet or brief. Preserve song ends, fades, interior gaps, content holds and explicit operator answers. All 15 proposed starts were accepted before implementation was authorised. No dispatch or release is authorised by this rule. | 10-16 | Operator: "All of these are fine", then "Ok, do that"; [Log 10-29] §4.0 decision 4 |
| E19 | **Add the 39 missed v9 runs to the re-cut round.** Exact membership and routing are listed in [Log 10-29] §4.0 decision 1; 974 uses `reExtract` under X7. This settles cohort inclusion; the remaining pre-dispatch checks still apply. | 10-16 | Operator: "Add the 39 runns"; [Log 10-29] §4.0 decision 1 |
| E20 | **Dead feed inside a talk is cut out:** where a sermon or short talk's source has *both* no audio (≤ −80 dB, ≥ 15 s, the `DeadFeedInsideSection` measure) *and* dead picture (frozen or black) over the same stretch, that stretch is excised from the cut, so the talk is composed of the spans either side. Sound-only dropouts under live picture stay in, flagged as now; dead picture over live sound stays under E15. Talks only: songs keep E14/E18. Class on 10-18: 1322 only (2511–2584 s); 988 and 1148 are sound-only and stay. Narrows E15, which was ruled for dead picture with the sermon still audible. No dispatch or release is authorised by this rule. | 10-18 | Operator: "we should probably cut out those seconds", then "Only where there's no audio or video"; [Log 10-29] §4.0 |
| E21 | **"Either is fine" means either cut is right — not any time between them, and neither by default.** A cut between two acceptable cuts can split a sentence (introductory sentences in or out are fine; split sentences are not). No tie-break defaults to the longer cut: preferences are to be automated as rules learned from the answers, never recorded as one-off choices. *Amended 10-26; was (10-21): "Where both edges are acceptable, the longer one is better … Scoring and any tie between acceptable cuts follow it."* No dispatch or release is authorised by this rule. | 10-21, 10-26 | Operator 10-21: "In general where both are acceptable the longer option is better" (Held-word Endings page). Operator 10-26: "Either is fine means either is fine, not 'any time between these is fine' … I don't think we should necessarily default to the longest. But I worry that simply preferring B is the kind of one off ruling we're trying to avoid. The aim is to make logic that will automate my preferences." |
| E22 | **A song after a sermon starts no earlier than the sermon's last word:** where the K16 answer keeps the hymn introduction with the sermon, the song starts where the sermon ends, never inside its words. Mechanism: a word window's opening margin is not a pause while another transcript line is still being said there (`CueSafeExtractionPlan::withoutClippedOpening`); the closing margin stays heard (1200 §2509). The ruled edges it moved (961, 1221, 1055) were heard again: as good or better. | 10-26 | Operator on run 1216's "How Deep": B "starts the song mid sentence [of] the introduction. If it skipped the intro completely that would be better"; the start at 69:44.8 (sermon's end) heard "The fix is right". [Log 10-29] §5 "Run 1216 joins" |
| E23 | **A residual miss is accepted as a recorded soft miss** when (1) its class is measured, (2) no rule mends it without moving an edge heard right, and (3) it loses no substantive content — a dropped last point never qualifies. It stays an open-defect fixture so a later fix shows; it is not acceptance of the class. First use: 928's one-word speech start over fading music (2 of 30 fade ends; 1220 ruled either). | 10-30 | Operator 10-30: "Yes" to the proposed disposition ([Plan] §4.1a step 4); consistent with "soft misses aren't worth breaking others". |
| E24 | **Speech after a song belongs to the next item.** A song end is a detection question (where the speech starts under the music) and a fade question (the outro under it fades to the cut, E7), never whether the speech belongs. Listening pages for song ends play each version with the pipeline's fade; a ruling heard on hard cuts that kept speech to save the outro is evidence about the fade, not about the speech. | 10-30 | Operator 10-30, on the F2 accepted-miss record: "Speech always belongs to the next item. The only questions are: 1. Can we accurately detect when the music stops and the speech starts? 2. What do we do with speech overlapping music? … isn't this why we introduced fades?" |
| E25 | **A song clip never starts partway through the song.** It starts at the first sung line, however quiet, and a song recorded partly very quiet (a solo voice far below the congregation and band) is published whole and flagged, as a partial talk recording is (`sermons.partial_recording`, P5). Operator 11-01 on 1011 §1285 (cut 519.73, solo from about 459): "The singing is audible, so we could use it. Or we could decide a song that's half quiet (solo voice) and half loud (congregation and band) is broken, so we shouldn't use the recording at all. The choice that makes no sense is starting the clip halfway through the song." Then: "A, but we should flag it, as we do for broken talk recordings." Supersedes `claude-review-20261030:1011-s1285-start`. Not built: plan §4.1a step 5, "Quiet sung openings". | 11-01 | operator 11-01, "The Muted Song Opening" page |
| E26 | **A song with no sung audio keeps its title in the order of service but makes no clip.** Its section is only the hymn's announcement because the recording ends, or the feed is muted, while the song is sung. Built as `UnrecordedSong`: no music heard in the section, and the recording ends within 10 s of it or digital zero of ≥ 15 s begins at its end; the section is ineligible for publication (`not_applicable`, no clip). Unaccompanied singing the classifier hears as no music is not caught (the service goes on after it) and stays held by `SongSectionWithoutMusic`. No dispatch or release is authorised by this rule. | 11-04 | Operator: "7: yes" to "a song with no sung audio keeps its title in the order of service but makes no clip"; plan §4.1a item 7 |

## 7. Audio

| ID | Ruling | Date | Source |
|---|---|---|---|
| A1 | Normalise each part separately, every speech part to −16 LUFS. | 10-08 | [Boundary] §6.3 |
| A2 | Noise reduction follows the pause level: strong above −30, gentle above −60, otherwise none; per-recording override. | 10-08, 10-09 | [Boundary] §6.3.5 |
| A3 | Hum filter, de-esser, high-pass and levelling stay off; music gets loudness only; MP3 at 48 kbps, 48 kHz. | 10-08, 10-09 | [Boundary] §6.3.5 |
| A4 | **The video's true-peak limit (−0.5 dBTP: treatment ceiling −1.5 plus 1.0 tolerance) is a target, not a requirement.** A video true peak over it no longer holds a sermon for listening; it stays measured and reported, and large overshoots stay a treatment defect to investigate. Not covered: integrated-loudness misses and the public MP3's own ceiling (+1.0 dBTP). | 10-25 | Operator: "target" (10 of 10 heard "sounds fine", overshoots 0.1–4.4 dB). [Log 10-29] §5 "10-25 (later)" B8 |

## 8. Songs

| ID | Ruling | Date | Source |
|---|---|---|---|
| S1 | A song bound to an order-of-service item is that item's song, however its title is spelt. | 09-30 | [Ensemble] 14 |
| S2 | An unbound song is the catalogue song its title names, when the resolver matches it deterministically (rule B). Untitled versus titled stays a question. | 10-01 | [Ensemble] 19 |
| S3 | `confirmed` means any two independent evidence sources. | 09-23 | [Plan] §4.3 |
| S4 | Duplicate catalogue songs are merged in OpenLP after the re-run, before the convergence re-census. Song data lives in OpenLP; local `alternate_title` edits revert. Includes 76/77 and 131/132; the 20 groups are listed in the song title curation plan (10-21). Only "Rejoice" (a new song) is added before the final round. | 09-25 | [Log 10-16] §4.0 standing decision 3 |
| S5 | The hymn workbook's held-out half stays unopened until song acceptance measures it; once measured, it is spent. | 09-25, 10-14 | [Songs] §7 |
| S6 | **Song acceptance has no numeric bar.** Rates are reported as measured; every wrong binding gets a fix or leave verdict with a reason. | 10-14 | [Songs] §7 |
| S7 | 2020–2022 have no workbook; stage 2 adds a 40-service listening sample for those years, reported separately. | 10-14 | [Songs] §9 |
| S8 | **A looped song transcript is a transcription fault, not a reason to hold the clip.** `song_looped_transcript` stops holding a song for approval. A loop's gaps still count for nothing as boundary evidence (`SongLoopedTranscript::blocksFor`). Not covered: a sermon or talk transcript loop, which is the published text itself (`transcript_repetition_suspect`), and speech hidden under a loop (`song_speech_under_loop`). | 10-24 | [Log 10-29] §5 "10-24 (later)" |
| S9 | **A sung item may publish itself from 55 s, not 90 s.** `short_song_clip` holds a song only under 55 s (`song_minimum_automatic_duration_seconds`). Fragments under 60 s are still diagnosed and held by `SongSectionWithoutSong`. | 10-25 | Operator: "from the listening it looks like it should be 55s" (10 of 10 whole at 58–81 s). [Log 10-29] §5 "10-25 (later)" A3 |
| S10 | **Leave run 1295's incomplete "Only By Grace" held; no further repair is planned.** Do not merge its fragments (3768/3769) or pursue the 3769 re-cut merely to remove the feed dropout. The limited listener benefit does not justify more work; prioritise restoring run 1216's sermon opening. Revisit only if someone specifically wants this song. Existing source evidence and the adopted prayer transcript remain retained. | 10-26 | Operator: "Agree", accepting "keep it held and move on" after discussing listener value. [Log 10-29] §5, "Only By Grace disposition" |
| S11 | Prefer keeping an acceptable spoken song introduction over a rule that might remove singing. The proposed dependent-opening announcement-word rule needs corpus measurement and listening, especially mixed speech/lyric case 1174; matching decoded words does not prove delivery as speech. Keep full musical introductions and complete sung lines. | 10-29 | Operator agreement to plan review, 10-29 |

## 9. Talks and readings

| ID | Ruling | Date | Source |
|---|---|---|---|
| T1 | Keep the `sermons` table; talk types are `sermon`, `childrens_talk`, `partner_update` and `testimony`, with no catch-all; one `/christ/talks` page; members-only by type, sermons public. | 09-18 | [Talks] §2 |
| T2 | Only the talk type confirmed in talk type review is published (rule C). | 10-01 | [Ensemble] 19 |
| T3 | Overlapping references are one passage unless they would pair the sermon with different readings (rule A). | 10-01 | [Ensemble] 19 |
| T4 | A reading's introduction is part of it when it names the reading's own book. | 09-30 | [Ensemble] 16 |
| T5 | **A short talk directly after a reading (≤ 15 s gap) is cut with that reading when answered so** (`service:answer-talk-reading`). | 10-13 | [Round] §11 |
| T6 | Nothing in the 191-section talk bucket is retyped by hand. | 09-18 | [Talks] §2 |

## 10. Exclusions, duplicates and identity (run level)

| ID | Ruling | Date | Source |
|---|---|---|---|
| X1 | Retire stale runs 931, 934, 935 (superseded by 936), **937 (dead duplicate of 938)** and 959. | 09-03 | appendix D4 |
| X2 | Saturday rehearsals 1043 and 1089 are excluded (`rehearsal_duplicate`), as are funerals 1051 and 1098 (`private_occasion`). The services their runs created are removed; release refuses an excluded run's rows. | 09-14, 09-15 | [Plan] §4.5 |
| X3 | 1145 is excluded, `no_sermon_in_source`, keeping service 1030; 1143 is an accepted hold. | 09-19 | [Plan] §4.5 |
| X4 | **Duplicate pairs:** keep the real service, use the better file's run, set the poorer run aside (`duplicate_recording`): A 1120 over 930, B 1034 over 1035, C 1375 over 1374. Applied and verified locally on 10-16; all 10 withdrawn assets preserved and hash-checked. Re-detection and re-cut remain pending. | 10-14 | [Plan] §4.4 |
| X5 | 940 is a rehearsal (`rehearsal`, service 653 removed, done 10-15); 879 and 1016 are rehearsals of 1015. Retire 879 (**done 10-21**: actual run 940 retired; sermon withdrawn and four assets retained under `superseded/`) and 1016 (**done 10-21**: run 1089; three assets under `superseded/`). **1015 was preached by the operator** (10-21). | 10-14, 10-15 | [Round] §12 |
| X6 | 607: keep 1126's 1080p sermon; 894 adopts it. 546: "720p with the reading", so 893 wins and 1189 is set aside. 790: 889 and 890 adopt 1378's sermon. | 10-14, 10-15 | [Round] §12 |
| X7 | Hand-edited structure, never to be recomposed: 942, 974, 1363. | 10-15 | [Round] §12 |

## 11. Rounds and operations

| ID | Ruling | Date | Source |
|---|---|---|---|
| R1 | Tier A re-transcribes and stops before detection; Tier B detects; Tier C cuts each run once. | 10-01 | [Log 10-16] §4.0 standing decision 2 |
| R2 | Evidence binds a code revision, not a commit: a documentation commit leaves snapshots valid; any code change needs a new snapshot and a worker restart. | 10-02 | [Log 10-16] §4.0 standing decision 7 |
| R3 | **Dispatch everything, no batches.** A listening defect costs only Mac time to repair. | 10-10 | [Boundary] §6.3.9 |
| R4 | Never move the code freeze mid-pass. Mid-round code changes are not supported unattended: pause, withdraw, end, start a new round. | 10-10, 10-13 | [Runbook] §8 |
| R5 | Rounds run from a frozen, pausable clone. | 10-13 | [Runbook] |
| R6 | Never run tests in the round clone. | 10-15 | [Runbook] §1 |
| R7 | Fix everything on the fix-round list (items 1–20) before the next run. | 10-12 | [Log 10-16] Part 2 |
| R8 | **Weekly processing takes over when the historic import is done**; services after 2026-08-23 wait for it. The definition of "done" is proposed in [Programme] §0.4. | 10-16 | operator, 10-16; [Plan] §4.2 |
| R9 | **Fix the stale-clip cause before the re-cut round.** Complete [Round] §9.1 step 1, test it and include it in the new freeze before dispatch; existing residue clean-up remains in [Plan] §4.1. Cause fix committed in `29126fb8a`; isolated section-sync tests reverified 10-17: 40 tests, 177 assertions passed. Running clone revision not verified by this check; do not alter the current round. | 10-16; status 10-17 | Operator: "Fix the stale clips before the round"; [Log 10-29] §4.0 decision 7 and §4.1 item 3 |
| R10 | **Recompose the 12 runs with duplicate section pointers in the final round** (980, 1073, 1074, 1138, 1159, 1198, 1259, 1260, 1261, 1265, 1269, 1301), so their items re-sync through the projection; a Tier C cut alone does not re-sync. Repair through the pipeline, not by hand. | 10-21 | Operator: "Yes, recompose them"; [Plan] §4.1 item 4 |
| R11 | **1309 §3922 ends at 284.9 s** (283.8 and 284.9 both fine; E21), banked as an operator edge. **1378 §4618 is two songs**, "What love could remember" then "When I Was Lost", changing in the 4356.4–4361.2 silence; split applied 10-21 at 4358.8 (§4618 → "What love could remember", new §272906 → "When I Was Lost"; clip 442 quarantined). A published song's edge may be set (its clip refreshes in place); a published sermon's may not. **Amended 10-31:** a weekly run retired into a round has its published sermon *replaced* at release by the re-import's new cut, keeping its public address; no published sermon is re-cut in place. | 10-21, 10-31 | Operator listening, Final open questions; [Plan] §4.1; operator 10-31 "B - replace them", [Plan] §4.1a step 3b |
| R12 | **Structure reviews answered as proposed:** 1355 [4472, 4473], 973 [190537, 190538], 1287 [3604, 3605], 1288 [3617, 3618, 3619, 3621], 1327 [4152, 4153], 1366 [4571, 4572]; and 1065 [1683, 1684] (operator: "yes"). A reading running into the sermon may be reviewed into it. | 10-21 | Operator: "All as proposed"; [Plan] §4.1 |

## 12. Release

| ID | Ruling | Date | Source |
|---|---|---|---|
| P1 | Release by era slice, each batch separately signed, with observation and rollback. | 08-14 | [Programme] §8 |
| P2 | **Thumbnails are deferred** until the release membership's editorial QA passes (cost), then generated as an explicit step and checked. Deferral reaffirmed for the coming round. | 09-14; reaffirmed 10-16 | [Plan] §4.5; operator: "Keep thumbnail deferral" |
| P3 | Speaker identification stays disabled for the historic corpus. Verify named assignments before release; retrain on suitable historic evidence if justified. Do not present an unknown preacher as "Visiting Speaker" without evidence that the person was a visitor. Decide and apply honest unknown-speaker copy for unresolved identities before a batch is released. | 09-14; amended 10-29 | [Plan] §4.5; operator agreement to plan review, 10-29 |
| P4 | No AI-generated answers on public surfaces. | 07-20 | notes |
| P5 | A partial recording must be clearly identified wherever a person encounters it, including podcast and download contexts as applicable. Website copy alone does not inform a listener who receives only a feed or file. Verify the available surfaces before release. | 10-29 | Operator agreement to plan review, 10-29 |

## 13. Superseded rulings

| Was | Date | Replaced by |
|---|---|---|
| "Finished runs stay untouched" (138 left alone; the 219 left un-recut when VideoToolbox shipped) | 10-13 | E11 (10-15): "I think I probably made the wrong decision before" |
| Batches of about 40 in era order, batch 1 of 20 from the workbook's discovery half, and the per-batch stop rules | 10-02 | R3 (10-10) |
| Talk-edge checks on every agreed talk edge touching other speech (decision 21), with the talk-end prayer exemption | 10-02 | D16 (10-10) |
| Tier A as a detection round | 09-24 | R1 (10-01) |
| Preset `veryfast` with one libx264 ffmpeg worker | 09-30 | E13 (10-13); the preset still applies to libx264 |
| The unique-match-only cue fallback; the shared-cue question, flag and parking | 10-03 | E3, E4 (10-03) |
| Whole-cue widening wherever edge word timings exist | 10-03 | E5 (10-03) |
| The prompt clause "a passage read after a talk has concluded is its own `bible_reading`" | 09-28 | D15, Ensemble 20 (10-02) |
| A sermon's closing prayer "either way" | 09-28 | K4 (09-30) |
| Cut plan D1 as first written | 10-02 | K2 (10-11) |
| A new freeze and canary for every output-changing fix | 10-04 | "No canary 16" and R3 (10-10) |
| A sermon may end on the next hymn's announcement and instrumental introduction, or the leader reading its first verse (K7) | 09-14 | K16 (10-16) |
| Pattern A: a sermon beside a song ends where the singing starts (K8) | 10-13 | K16 (10-16): at the music's start at the latest, and only through an introduction that ties the song to the sermon |

---

## Appendix — the decision record of 3 September 2026 (word for word)

The historical decision record and its implementation journal are preserved in the
[pre-consolidation register snapshot](../archived-plans/HISTORIC-RULINGS-REGISTER-SNAPSHOT-2026-10-29.md#historic-import--decision-record-3-september-2026).
Current rulings remain in §§1–12 above; superseded rulings stay in §13. Historical open items
are not a second worklist.
