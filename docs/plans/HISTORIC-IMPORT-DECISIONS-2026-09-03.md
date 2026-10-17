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
| C6 | Temp-file cleanup is paused locally (`MEDIA_PAUSE_TEMP_FILE_CLEANUP=true`) so restaged sources survive; unset it after the historic repairs. | 09-23 | [Plan] §4.2 |

## 3. Detection and structure

| ID | Ruling | Date | Source |
|---|---|---|---|
| D1 | The detector may propose that a service held no sermon; the operator confirms it. | 09-03 | appendix D1 |
| D2 | A fixed `ServiceOccasion` enum, shown on the public service page only once confirmed. | 09-03 | appendix D2/D3 |
| D3 | `structure_missing_preached_reading` is raised only when the sermon has no Scripture reference at all. | 09-03 | appendix D5 |
| D4 | Detection reads only independently sourced order-of-service items. Email stays out of the re-run, so the video stays an independent witness. | 09-24, 09-25 | [Log 10-16] §4.0 standing decision 3 |
| D5 | Holds follow their content; only code-found holds recheck themselves. Rules raise review flags or propose held sections, never retype or delete silently. Exception: Music ruling 2 (E14). | 09-23 | [Music] §3 |
| D6 | **A music span is judged whole: sung or wholly instrumental.** An instrumental introduction or ending belongs to its song; a spoken introduction is speech. | 09-25 | [Music] §3 |
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
| D21 | **Split sermons are joined:** 1073 and 1268 publish only part of their sermon, the rest `other`. Not built yet. | 10-14 | [Round] §12 |
| D22 | 954's "Report from Men's Event" is a talk; the detection-only item type needs a fix. Not built yet. | 10-14 | [Round] §12 |
| D23 | Speech with no transcribed line is marked as an unobservable window and asked about, without holding (F11, "mark + ask"). Uncovered sound that decoded to nothing is marked (I1). | 10-05 | [Boundary] §6 |

## 4. Questions, review and listening

| ID | Ruling | Date | Source |
|---|---|---|---|
| Q1 | One standard pattern: each question declares its consequence once, and every gate reads it. **Not built** ([Plan] §4.3). | 10-05 | [Boundary] §8.2 |
| Q2 | **Hold or ask, by the mistake the output could contain.** Hold when it might include something it shouldn't; ask when it is right but might omit something at its edge. | 10-05 | [Boundary] §8.2 |
| Q3 | Nothing with an open question goes public, on either path. | 10-05 | [Boundary] §8.2 |
| Q4 | A question is judged against the output it doubts: text questions hold text, label questions ask, cut-content questions hold. | 10-05 | [Boundary] §8.8 |
| Q5 | No review state means nothing to hide: a sermon with no pipeline run or sections is public as before. | 10-05 | [Boundary] §8.8 |
| Q6 | A title variant is not a question. Normalise titles against the catalogue before composing. | 10-06 | [Boundary] §8.9 |
| Q7 | One question per clip: merge disputes that settle the same decision over one stretch. | 10-06 | [Boundary] §8.9 |
| Q8 | **Held sermons are cut with `--held-section`.** The hold stays; a release page then has the operator confirm each defect is gone. The operator approved the 62-run cohort listed in [Plan] §4.0 decision 2, with a dry run per run and the recorded duplicate exclusions and routing. | 10-13; cohort approved 10-16 | [Round] §5 R4; [Runbook] §4.2; operator: "Recut the 62 held sermons" |
| Q9 | **A looped pre-recorded segment is a flag to listen to, never a trim.** A reading may repeat its first and last verse. | 10-14 | [Round] §12 |
| Q10 | Listening pages: ask which text matches, show service time, play at 3× by default, never scroll sideways, and build from the last working page. | 10-07 to 10-11 | [Runbook] §5.3 |
| Q11 | Explain the evidence before asking for a decision. | 07-05 | notes |
| Q12 | **Hold and review the known-bad endings:** runs 1224, 1111, 1198, 962, 1005, 1240 and 1271. Keep or apply content-defect holds and review against the source; a re-cut alone does not clear them. The operator confirms each ending defect is resolved before its hold is cleared. Holds applied and verified on 10-16 to sections 846, 1242, 1935, 165128, 146083, 175519 and 3397. Source listening and operator confirmation remain outstanding. | 10-16 | Operator: "Hold and review the known bad endings"; [Plan] §4.0 decision 5 |

## 5. Sermon composition

| ID | Ruling | Date | Source |
|---|---|---|---|
| K1 | **A sermon's media is** its reading (if separate), the sermon, and a concluding prayer (if before the post-sermon song), as named sections (cut plan "D1"). | 10-02 | [Cut] §5 |
| K2 | **Amended:** also the prayer directly before the sermon, even with a song between it and the reading. Gaps of up to 15 s between selected parts, with no other section in them, play straight through, and each part keeps its own sound treatment. | 10-11, 10-13 | [Cut] §5 |
| K3 | Including or excluding the preacher's prayer at the start is always acceptable (ER1). | 09-29 | [Ensemble] 9 |
| K4 | **Closing prayers are actively preferred in sermons.** | 09-30 | [Ensemble] 18 |
| K5 | A non-matching reading stays joined to the sermon (the nearest one, across a hymn if need be). | 09-14 | [Plan] §4.3 |
| K6 | Every reading directly before the sermon is cut with it, except a carol service's lessons (December, four or more readings), plus passage matches further back. | 10-13 | [Round] §6, §11 |
| K9 | Pattern B: a recording that opens with the sermon (first section, ≤ 5 s in) publishes that sermon. | 10-13 | [Round] §11 |
| K10 | Pattern C: a reading the sermon stops for and resumes after stays inside it. Prayers, and parts preaching different passages, stay questions. | 10-13 | [Round] §11 |
| K11 | Pattern D: a low-confidence reading directly before the sermon is cut with it; low confidence holds neither. | 10-13 | [Round] §11 |
| K12 | Sermon versions differing only at their edges, with no other section claimed there in any draft, are one sermon at its widest edges (`c870b1471`). | 10-12 | [Log 10-16] Part 2, fix round item 2 |
| K13 | A sermon part whose speech stands < 20 dB above its pauses is held, never dropped. | 10-10 | [Boundary] §6.3.8 |
| K14 | **Use the longer recording for the service, but never let its sermon replace a better recording's** (higher resolution, then bitrate; the held sermon ≥ 95% of the length). | 10-15 | [Round] §12 |
| K15 | Two answers that share a seam both keep it: the seam stays in both clips. | 10-14 | [Round] §12 |
| K16 | **A sermon followed by a song ends at the join to it, and never after the music starts.** The preacher's introduction to the song is part of the sermon when it ties the song to the sermon, because then it works as the sermon's conclusion. A bare announcement, the introduction to an unrelated song, and notices are not. A "let's stand and sing" call on its own is never included. "It's not really about the length, it's about the relevance of the song to the sermon." Notices or housekeeping between the sermon and a tied introduction end the sermon at its own last sentence. The preacher must say how the song relates to the sermon: reading its words aloud is no tie on its own (1040, 1311, 932). The judgement is asked of the model in the routine pipeline; a third answer settles a disagreement on the judgement or the line (a line two name, else the middle, else the earlier). | 10-16 | Edge-tool session 10-16 (`storage/scratch/weekly-edge-tool-20261016/`) |

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
| E9 | The song ending a sermon may start with or without its introduction (≤ 30 s, nothing spoken between). A song clip keeps its spoken introduction at any length, never held. | 10-10 | [Boundary] §6.3.8 |
| E10 | **Dead feed:** a spoken edge in ≥ 2 s of dead feed (≤ −80 dB) moves to the sound: a start to 0.3 s before the first sound ("2 seconds is fine at the beginning"), an end to where the feed goes dead. Never reintroduce the clipped endings fixed on 10-12. | 10-15 | [Round] §12 |
| E11 | **Finished runs are re-cut** wherever the replayed plan differs from the cut at all, with no tolerance; song and talk clips too. | 10-15 | [Round] §12 |
| E12 | A clip with no recorded cut is re-cut, not reused. All 155 pre-fix runs go into the re-cut round, and its fresh comparison decides. | 10-16 | [Round] §12 |
| E13 | Use VideoToolbox (host ffmpeg service) for section cuts. | 10-13 | [Round] §2 |
| E14 | **Music ruling 2:** a song edge that runs ≥ 15 s into verified digital zero (every frame `-inf`) moves to where the zeros begin, with no hold. Song starts additionally follow E18; song ends and the 15 s dropout/whole-song policy remain unchanged. | 09-25; clarified 10-16 | [Music] §12; [Plan] §4.0 decision 4 |
| E15 | **Video whole or not at all:** release the whole video when ≥ 75% has usable picture, otherwise hide it and release audio only. Never trim dead stretches; flag "has video issues". | 09-22 | notes; `release_usable_share` |
| E16 | Whole-recording cards count as no video; 1225's partial video is shown. | 09-14 | [Plan] §4.3 |
| E17 | A concatenated recording can contain a song: keep a song section the evidence supports, and demote only join fragments. | 09-14 | [Plan] §4.3 |
| E18 | **Song starts:** remove a leading prefix of ≥ 2 s of verified digital zero, keeping 0.3 s before the first finite audio sample, however quiet or brief. Preserve song ends, fades, interior gaps, content holds and explicit operator answers. All 15 proposed starts were accepted before implementation was authorised. No dispatch or release is authorised by this rule. | 10-16 | Operator: "All of these are fine", then "Ok, do that"; [Plan] §4.0 decision 4 |
| E19 | **Add the 39 missed v9 runs to the re-cut round.** Exact membership and routing are listed in [Plan] §4.0 decision 1; 974 uses `reExtract` under X7. This settles cohort inclusion; the remaining pre-dispatch checks still apply. | 10-16 | Operator: "Add the 39 runns"; [Plan] §4.0 decision 1 |

## 7. Audio

| ID | Ruling | Date | Source |
|---|---|---|---|
| A1 | Normalise each part separately, every speech part to −16 LUFS. | 10-08 | [Boundary] §6.3 |
| A2 | Noise reduction follows the pause level: strong above −30, gentle above −60, otherwise none; per-recording override. | 10-08, 10-09 | [Boundary] §6.3.5 |
| A3 | Hum filter, de-esser, high-pass and levelling stay off; music gets loudness only; MP3 at 48 kbps, 48 kHz. | 10-08, 10-09 | [Boundary] §6.3.5 |

## 8. Songs

| ID | Ruling | Date | Source |
|---|---|---|---|
| S1 | A song bound to an order-of-service item is that item's song, however its title is spelt. | 09-30 | [Ensemble] 14 |
| S2 | An unbound song is the catalogue song its title names, when the resolver matches it deterministically (rule B). Untitled versus titled stays a question. | 10-01 | [Ensemble] 19 |
| S3 | `confirmed` means any two independent evidence sources. | 09-23 | [Plan] §4.3 |
| S4 | Duplicate catalogue songs are merged in OpenLP after the re-run, before the convergence re-census. Song data lives in OpenLP; local `alternate_title` edits revert. | 09-25 | [Log 10-16] §4.0 standing decision 3 |
| S5 | The hymn workbook's held-out half stays unopened until song acceptance measures it; once measured, it is spent. | 09-25, 10-14 | [Songs] §7 |
| S6 | **Song acceptance has no numeric bar.** Rates are reported as measured; every wrong binding gets a fix or leave verdict with a reason. | 10-14 | [Songs] §7 |
| S7 | 2020–2022 have no workbook; stage 2 adds a 40-service listening sample for those years, reported separately. | 10-14 | [Songs] §9 |

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
| X5 | 940 is a rehearsal (`rehearsal`, service 653 removed, done 10-15); 879 and 1016 are rehearsals of 1015. Retire 879 (not done). | 10-14, 10-15 | [Round] §12 |
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
| R9 | **Fix the stale-clip cause before the re-cut round.** Complete [Round] §9.1 step 1, test it and include it in the new freeze before dispatch; existing residue clean-up remains in [Plan] §4.1. Cause fix committed in `29126fb8a`; isolated section-sync tests reverified 10-17: 40 tests, 177 assertions passed. Running clone revision not verified by this check; do not alter the current round. | 10-16; status 10-17 | Operator: "Fix the stale clips before the round"; [Plan] §4.0 decision 7 and §4.1 item 3 |

## 12. Release

| ID | Ruling | Date | Source |
|---|---|---|---|
| P1 | Release by era slice, each batch separately signed, with observation and rollback. | 08-14 | [Programme] §8 |
| P2 | **Thumbnails are deferred** until the release membership's editorial QA passes (cost), then generated as an explicit step and checked. Deferral reaffirmed for the coming round. | 09-14; reaffirmed 10-16 | [Plan] §4.5; operator: "Keep thumbnail deferral" |
| P3 | Speaker identification stays disabled for the historic corpus. Retrain it on the historic video, or accept "Visiting Speaker" explicitly for a batch. | 09-14 | [Plan] §4.5 |
| P4 | No AI-generated answers on public surfaces. | 07-20 | notes |

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

Headings are nested one level deeper; nothing else changed. Its D1–D7 are summarised above as D1–D3
and X1; its §4–§6 open items were settled or carried into the plan.

## Historic import — decision record, 3 September 2026

**Status:** Historical decision record. D1, D2/D3, D4, D5 and D7 were implemented
and applied; later execution and review superseded its D6 and §4 status. See §6
for what landed. The complete dated execution context is in the
[archived video log](../archived-plans/HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md);
current containment and acceptance work is in the
[focused video plan](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md).

Working tree was clean when these decisions were taken (`9f3f61cc2`). No code
changed in the session that produced them; the implementation followed in the
next one.

---

### 1. Corrections to the existing record

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

### 2. Decisions

#### D1 — The detector may propose sermon-absence; the operator confirms it

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

#### D2 / D3 — A fixed `ServiceOccasion` enum, rendered publicly once confirmed

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

#### D4 — Retire all five stale runs

**Decided: retire #931, #934, #935, #937 and #959** with operator notes, via
`historic-import:retire-run`.

They need truthful terminal dispositions for the Phase 8 exit gate regardless.
Between them they pin ~1.3 GiB. **Verify on implementation whether retirement
clears `Failed`** — `HistoricReviewSourceReclaimer::isEligibleRun()` excludes
`Failed` *before* checking obligations, so a run that stays failed keeps pinning
its source no matter what else is settled. #959 is superseded today and still
pins 0.13 GiB, which suggests superseding alone is not enough.

#### D5 — Retarget `structure_missing_preached_reading` to a missing reference

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

#### D7 — Instrument the staging-guard leak; do not chase it

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

### 3. Deferred

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

### 4. Operator items — only a person can settle these

- **Confirm #978's occasion** once D1/D2 land: `2024-02-11-evening`, a London
  City Mission "Operation Forgiveness" presentation evening.
- **Work the review queue.** 41 obligated runs / 74 decisions. Irreducible
  classes: `childrens_talk_speaker_review` (12, mandatory by design),
  `unmatched_song_section` and `song_title_marker_mismatch` (identification).
- **#959** (`2023-07-16-morning`, 405 s against a 64.8-min morning median) is on
  the open-decisions list from 2026-09-01 as a children's talk. D4 retires the
  run; whether the *identity* is reprocessed or excluded at Phase 8 is still open.

### 5. Still open, not decided

- **#937's `rms_generation` failure** — *"File size exceeds maximum allowed
  size"* on a 10.98 GiB source. D4 retires the run as a duplicate, but the cap
  itself is unexamined and will recur on the next oversized source.

---

### 6. What was implemented, 2026-09-03

Every decided item landed. D6 was not touched, and §4's operator items are still
a person's to settle.

#### D1 — sermon-absence assertion

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

#### D2/D3 — `ServiceOccasion`

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

#### D4 — the five stale runs

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

#### D5 — `structure_missing_preached_reading`

`SectionReviewFlagPolicy` demotes the flag when the sermon carries a
`sermon_reference`, so detection and reconciliation agree by construction.
`services:recompute-section-review-flags`, scoped to the six affected services,
took the class from 8 to 2 exactly as predicted: §645 (#940) and §742 (#952)
keep it, and **#958, #966 and #979 released outright**.

#### D7 — staging-guard instrumentation

Every activate/deactivate records the depth it moved to, which of the four call
sites moved it, the job, and the live `historic_staging` root. A root still
carrying a batch directory at depth zero is the leak itself, so
`HistoricStagingGuard::leakedActivationEvidence()` detects it and the registry
logs that case as a warning carrying both paths; balanced transitions stay at
debug.

#### Released bytes

2.63 GiB became reclaimable across four runs — #935 (1.19) and #959 (0.13) from
D4, #958 (0.63) and #979 (0.68) from D5. The scheduled
`media:cleanup-temp-files` sweep releases it; nothing was deleted by hand.

#### Still owed

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
