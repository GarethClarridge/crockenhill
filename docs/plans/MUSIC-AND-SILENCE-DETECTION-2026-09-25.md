# Music and silence: an audio classifier for structure detection

**Date:** 2026-09-25 (revised the same day after review by Codex and Claude)
**Status:** Plan only; nothing built. Verified against code at `1bf59e65a`.
**Gates:** the historic corpus re-run freeze (`HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md`
§4.0). Operator ruling 2026-09-25: build this **before** the freeze; canary 5 runs on the commit that
carries it.
**Scope:** Give structure detection a per-run record of where the recording holds **music** and
**speech**, taken from the audio by a trained classifier, and use it as evidence in the detector
prompt and in the existing sound-stage rules. Separately, extend the existing digital-silence rule
from talks to songs. Both go into the **routine** pipeline, not a historic branch. Song *identity*
is out of scope: it stays with the announcement, the order of service and OpenLP.
**Operator rulings:** §12 (all five settled 2026-09-25). Step 0 listening done 2026-09-25 (§6.3, §7).

## 1. Why

Canary 4 (2026-09-25) parked run 1304 (`unplaced_content_hold`). The operator listened:

| Time (s) | Truth | Whisper | Canary 3 | Canary 4 |
|---|---|---|---|---|
| ~127–~215 | Jesus Shall Take the Highest Honour | "Thank you." / "The End" | §3869 song ✓ | song ✓ |
| over the outro → 261 | Philippians 2 reading | from 220 s | ✓ | ✓ |
| 190–220 | (still song and reading) | | §3870 "Lord I Lift", **phantom** | **phantom** |
| 261–~360 | Lord, I Lift Your Name on High | "Thank you." ×2 | "Unidentified singing" (sound rule) | **`other`** |

Whisper writes a filler cue ("Thank you.", ". . .", "The End") over singing it cannot hear. The model
sees an empty gap and guesses from the announcement, so the same transcript gave two different wrong
structures in two canaries.

**Whisper cannot be fixed by settings.** Ten decodes of the 1304 song failed: production turbo with
and without the service prompt, a song prompt, temperature 0.4, no-speech threshold off, band-pass
and loudness normalisation, a song-only slice, full large-v3, and Demucs-separated vocals (whole,
normalised, and in 10 s slices). Prompting with the expected lyrics was rejected: it makes "heard"
repeat "planned", which the `confirmed` rule counts as two independent sources.

**The RMS sound stage cannot tell speech from singing at edges.** `SustainedSound` judges RMS level
and pauses; it is right on whole sections (0 of 438 sermons read as sung) but a leader at a
microphone over the band reads as singing (21 of 38 run-on edges, 2026-09-15). That is why
`SustainedSoundSongSections` refuses to widen a song into a neighbouring typed section (its
docblock). A syllable-rate modulation measure on the same 23 ms RMS log did not separate them either
(song 0.16–0.47, speech 0.11–0.41 on 1304).

**A trained classifier can.** `MIT/ast-finetuned-audioset-10-10-0.4593` (Audio Spectrogram
Transformer, AudioSet labels), 5 s windows, spike in `storage/scratch/ml-spike-20260925/`:

- **1304:** every boundary within one window, including the reading over the outro (Speech 0.70 and
  Music 0.70 at 215–220 s). Speech resumes at ~360 s, so Whisper's 339.8 s cue start was a chunk
  artefact.
- **36 song-edge cases** of the 2026-09-15 probe: agrees with Whisper's text on every informative
  row (18 speech, 9 lyrics); settles the 3 uninformative ones; reads spoken Psalm 103, Psalm 24 and
  Revelation 15 as speech; places song→speech edges within 5 s. (Reference is Whisper text, not
  listening.)
- **40 blind listening windows** (operator, predictions sealed first, sha256 `6a7e5b71…e644a`):
  singing 12/14 as music under the sealed rule (clip-mean Music ≥ 0.5 and Speech < 0.3,
  `predict-sample.py`); 14/14 at 0.4, a cut-off chosen **after** the verdicts were seen; speech 2/2;
  no silence or speech window predicted as music. It does **not** separate singing from
  instrumental music (a long song outro reads as music; "Choir" is 0 on several sung windows).
- **Caveats found on review.** Instrumental pre-service music also raises the speech score (0.42 on
  1030, 0.38 on 930). The Silence label is weak: real room quiet scores 0.04–0.36, and digital zero
  gives one fixed output (music 0.122, speech 0.023, silence 0.515 on five different runs). The
  classifier is therefore **not** used for silence (§6.5 R1 uses the RMS log instead).

## 2. What the full sections showed (review, 2026-09-25)

The listening sampled short windows, not whole sections. On review each truth case was re-read
over its **whole** section: the classifier over the full span in 5 s windows, and the RMS log for
digital silence (frames at `-inf`, which `RmsAnalysisService` maps to `DIGITAL_SILENCE_RMS`). This
changed what the rules must do:

| Run | Current section | Whole-section finding | Defect | Rule |
|---|---|---|---|---|
| 1050 | §1584 song 884–916 | digital zero from ~868 s to the recording's end (920 s) | whole song over a dead feed | R1 flag |
| 1346 | §4377 song 3663.8–3980.0, "O Come, O Come, Emmanuel" | sung 3664–~3895; digital zero ~3902 to the end (3980) | song end overruns into a dead feed by ~78 s | R1 edge |
| 1028 | §1394 song 977.9–1089; §1395 `other` 1089–1181 | music continues 1089–~1123, speech 1125–1181 (operator: music stops 1121.7, speech 1125.2) | song end ~35 s early; the `other` is ~37 % music | R2 widen |
| 1262 | §3282 `other` 1090–1121 (31 s), then §3283 song from 1121 | all 31 s music, speech before 1090 (operator: sung from the start) | song start ~31 s late | R2 widen |
| 993 | the sampled 4342 s now sits in §1189 song 4126.9–4345.6 | music to ~4350, speech from 4350 | none in the current structure (edge within one window) | none |
| 1304 | canary 4: `other` 261–~360 | music throughout | whole song typed `other` | R3 propose |
| 1304 | §3870 song 190–220 | real singing and a reading | phantom **identity**, not silence | prompt only (§6.4) |

Three conclusions:

1. **Most defects are song edges, not whole sections.** 1028, 1262 and 1346 need an edge moved;
   only 1050 and 1304-canary-4 are whole-section cases. The original "3 of 20 phantom songs over
   silence" was really 2 sections, and one of those (1346) is an edge.
2. **The existing sound-stage rules already have the right shapes.** Widening a song
   (`SustainedSoundSongSections`), proposing a song over unclaimed music (same class) and reading
   typed sections as sung (`MistypedSungSections`) exist; they lack evidence that tells music from
   speech. The classifier supplies that evidence to those rules, rather than adding parallel rules
   that would propose the same song twice from different evidence.
3. **Digital silence needs no classifier.** `AudioDropoutInsideTalk` already flags ≥ 15 s at
   ≤ −80 dB inside a talk. Songs are the missing section type. Dead feeds are not only at the end:
   1304 has dropouts through 900–1140 s.

The 1304 current DB rows show the **canary 3** shape (§3872 "Unidentified singing"), not canary 4's
`other`. Truth cases are therefore tested as **fixtures**, never by replaying whatever structure the
database holds today.

## 3. Rulings in force

- Build before the freeze (operator, 2026-09-25).
- **No historic-only pipeline behaviour** (operator, 2026-09-25): the historic work exists to
  improve routine processing. The classifier and the rules run in every pipeline that runs
  `DetectServiceStructure`. Historic-only *operator tooling* (backfill, re-run guard) is fine.
- Holds follow content; rules **raise review flags or propose held sections**, never retype or
  delete silently (content-holds ruling, 2026-09-23). §12 ruling 2 makes one exception: song edges in verified digital zero move without a hold.
- Repair through the pipeline, never by hand: 1304 is settled by re-detection on the new commit.
- Keep the re-run Email-blind; song identity sources are unchanged.
- No rollback flag or config seam for the new stage (prefer deletion over config seams).
- **A music span is judged whole: sung or wholly instrumental** (operator, 2026-09-25). An
  instrumental introduction or ending is part of its song and belongs in the song section; a span
  is never split into instrumental and sung parts. Inside the service a music span is almost
  always a sung item. A wholly instrumental span is almost always before or after the service and
  stays `other`. A spoken introduction is speech, not part of the song.

## 4. Design overview

```
source file ──► GenerateRmsLog (existing) ──► rms log ──────────────────────────────┐
source file ──► TranscribeFullService (existing) ──► archived service audio (.mp3) │
                  └─► ClassifyServiceAudio (new) ──► audio timeline (.classes.json)─┤
                                                                                     ▼
DetectServiceStructure: detect (prompt gains music/speech spans)
  → snapToSilences:
      SilenceSnapService (existing)
      → DeadFeedInsideSection   R1 (was AudioDropoutInsideTalk; now talks + songs)
      → SustainedSoundSongSections   R2 widening may cross into an interior `other` on music-only windows
                                     R3 proposal also from an interior `other` the classifier hears as music
      → SongSpeechEdges, MistypedSungSections, SungSpanInsideSermon (existing)
  → validate
```

Running R1 first is not enough on its own: widening bridges short gaps, so a later rule could still
cross a dropout. R1's dead-feed intervals are therefore passed to R2/R3 and to the existing widening
as **barriers** no edge may cross (§6.5), and the composed sound stage is tested as a whole.

## 5. The classifier (app container, not a host service)

The repo already runs a PyTorch model in production this way: `scripts/extract_embedding.py`
(Resemblyzer), called from `ResemblyzerSpeakerIdentificationService` through
`Process::timeout(...)->run([...])`. The prod `Dockerfile` installs CPU-only PyTorch for it. The
classifier follows that pattern:

- `scripts/classify_audio.py <audio>` → stdout JSON `{model, model_revision, window_seconds: 5,
  audio_seconds, input_sha256, preprocessing, runtime, windows: [{start, end, music, speech,
  singing, choir}]}`. Non-zero exit or invalid JSON fails the job; nothing is written until the
  output has been validated.
- **Input contract, one for fresh runs and backfill.** The input is the run's archived service
  audio: the `audio` entry in `processing_metadata['service_artifacts']`, written by
  `ServiceArtifactStorage::archiveAudio()` during `TranscribeFullService`, on the sermon disk. Not
  `source_file_path` (the RMS log's input), because historic sources may be cleaned up and the
  backfill could not reach them. This is also the audio the spike measured (the staged `.mp3`s).
- **Preprocessing** lives only in the script: ffmpeg decode to mono 16 kHz f32, 5 s windows from 0,
  final partial window padded as the feature extractor pads. `preprocessing` records the ffmpeg
  arguments and ffmpeg version; `runtime` records torch and transformers versions; `input_sha256` is
  the hash of the file classified. A matching duration alone does not prove the right audio was
  classified; the hash does.
- **Dependencies:** `transformers` and `torchaudio` added to the prod `Dockerfile` and
  `docker/8.4/Dockerfile`, next to the existing torch/resemblyzer install (§12 ruling 1: approved).
- **Model weights baked into the image** at a pinned Hugging Face revision, so prod never fetches
  at runtime and `model_revision` is whatever the image carries. `HF_HUB_OFFLINE=1` at runtime.
- **CPU everywhere.** Docker on the Mac cannot reach MPS; prod has no GPU. One device class for
  historic and routine runs keeps timelines comparable. No host-side fast path for the backfill.
- **Cost.** Spike: ~0.23 s per window on CPU unbatched, ~3.5 min per 75-minute service (fine
  weekly). The historic backfill is ~1,300 runs, ~75 h unbatched. Step 1 measures batched
  throughput in the container and sets the backfill schedule from that number.

## 6. Pipeline changes (app)

### 6.1 Artifact

- Written beside the RMS log at `ServiceArtifactStorage::basePath($processingId).'.classes.json'`,
  i.e. under `service-transcripts/…`, so `ServiceArtifactDisk` resolves it to the durable disk with
  **no resolver change**. Test with distinct temp and durable disks.
- Path on a new `audio_timeline_path` column of `media_processing_logs`, mirroring `rms_log_path`.
- Validated on write: windows contiguous from 0, last window end within one window of
  `audio_seconds`, which must match the source duration within one window.

### 6.2 Job and phase

- `ClassifyServiceAudio` job after `transcribe_full_service` (which archives the audio it reads) and
  before `detect_service_structure`, in **both** pipelines that run `DetectServiceStructure`:
  `livestream` (historic runs use it with one extra wait gate) and `video_auto_trim`. Registered in
  `ProcessingPhaseRegistry` for both. A run with no recorded `audio` artifact fails the job with a
  reason; the backfill dry run reports how many historic runs are in that state before `--execute`.
- The job checks the classified duration against the RMS log's last frame (within one window) and
  refuses a mismatch: the two artefacts come from different inputs and must describe the same audio.
- Reuse on resume through `ProcessingArtifactReuse`, as `GenerateRmsLog` does
  (`mayReuseRecordedRmsLog`). A reused timeline is reused, never re-measured.
- `DetectServiceStructure` **refuses** a run with no readable timeline, with a reason. Every run has
  one, so there is no "absent timeline" branch in the detector or the rules.
- Queue: not the Whisper worker's; exact queue settled in build.

### 6.3 `AudioTimeline` and the decision rule

The rule the spike measured (clip mean) is not the rule the code will run. The code's rule is fixed
here and the measurement in §8 evaluates **this** rule on whole sections:

- A span's **music share** = Σ over windows of (overlap seconds × [music ≥ M]) ÷ span seconds;
  **speech share** likewise with [speech ≥ S]. Partial windows count by their overlap seconds.
- **M = 0.4, S = 0.5, validated 2026-09-25** on a fresh blind set (step 0, §7): 30 windows from 30
  regions not in the earlier set, per-window predictions sealed before listening (sha256
  `95bf1a20…70de0`), `storage/scratch/music-rule-listening-20260925/`. Of 171 scored 5 s windows,
  music 78/78 read as music; speech 87/93 as speech, 4 as mixed, 1 as neither, **1 as music**: 1009
  1917–1922 s (music 0.54, speech 0.46), the first window after the song ends, likely the song's
  tail under the first words. The sweep: M = 0.5 loses 2 music windows, M = 0.6 loses 10; S = 0.4
  would remove the one error at no music cost here, but was not adopted because choosing it now
  would fit the rule to this set. Only ~4 % of windows sit near either cut-off (50 of 1,164
  classified), so the scores are strongly bimodal. No instrumental-only or silent window was drawn;
  those rest on the earlier set.
- Music and speech are **independent** per window, giving four states: **music** (music ≥ M,
  speech < S), **mixed** (both), **speech** (speech ≥ S, music < M), **neither**. Instrumental
  music's raised speech score (~0.4) stays below S, so it reads as music. A reading over a song's
  outro (1304, 215–220 s: 0.70/0.70) reads as mixed.
- `spans(state, minimumSeconds)` merges consecutive windows of one state.
- **Who reads what:** R2 and R3 count **music** windows only; a mixed window stops a widening (a
  reading over an outro is a reading). The prompt shows music **and** mixed spans.

### 6.4 Detector evidence

`OpenAiServiceStructureService::buildPrompt()` gains a block after the order of service:

```
Sound classification (5 s windows, from the audio, independent of the transcript):
- music 145-215
- speech+music 215-220
- music 265-360
```

Music spans ≥ 15 s are shown; a mixed span of any length is shown when it touches a shown music
span (so the 5 s reading-over-outro at 1304 215–220 s survives the filter); speech is not shown, the
transcript already carries it. The system prompt gains one instruction: a music span inside the
service is a sung item, including any instrumental introduction or ending, and is never evidence of
speech, even with no transcribed lyrics; an empty cue inside a music span is unheard audio. This is the **only** mechanism aimed at the 1304 §3870 phantom (a wrong
identity placed over real singing). No deterministic rule can catch that, so it is judged
end-to-end at canary 5 (§8), not in the rule replay.

### 6.5 Rules

**R1 — dead feed inside a song** (RMS log; generalises `AudioDropoutInsideTalk`, renamed
`DeadFeedInsideSection`). Same measure: ≤ −80 dB (`DROPOUT_LEVEL_DB`) for ≥ 15 s
(`MINIMUM_SECONDS`).
Two strengths of evidence are kept apart. A **dropout** is the existing predicate (≤ −80 dB for
≥ 15 s): suspicious, judged by an operator. A **digital-zero span** is a dropout whose every frame is
`-inf` (`DIGITAL_SILENCE_RMS`): the feed carried no samples at all. 1050 and 1346 are 100 % `-inf`
over their spans (§2).

- Talks: unchanged (flag, non-disqualifying).
- **Whole song** inside a dropout, or with < 15 s of live audio outside dropouts: flag
  `structure_song_over_dead_feed` (held). 1050.
- **Edge overrun** (a song's leading or trailing edge runs ≥ 15 s into a dropout, live audio on the
  other side): if a run of **digital zero** (contiguous `-inf` frames) of ≥ 15 s reaches the song's
  edge, move the edge to where that run begins and note it on the section, with no hold (§12
  ruling 2). The dropout itself usually begins a fraction of a second earlier with a fade at
  ≤ −80 dB (1346: dropout from 3901.4 s, zeros from 3901.6 s), so the test is on the zero run, not
  on the whole dropout. Otherwise (only ≤ −80 dB), flag and hold: the threshold was built to flag,
  not to cut. 1346.
- **Interior dropout** (live audio on both sides of a ≥ 15 s dropout inside a song): flag
  `structure_song_over_dead_feed` (held), reason naming the interval. Never split or trimmed: the
  song's media would carry the gap, and whether to release it is the operator's call under the
  whole-or-not-at-all video ruling. Test from a fixture modelled on 1304's 900–1140 s dropouts.
- **Barriers.** Every dropout interval is passed to `SustainedSoundSongSections` (existing widening,
  R2, R3) as a barrier: no widened edge or proposal may cross or enter one.
- No match-type condition: detection runs before song matching.

**Service interior.** Both R2 and R3 act only on an **interior** `other` section: one with a
section of another type somewhere before it **and** somewhere after it in section order. Leading
and trailing `other` sections (pre-service instrumental music, opening audio, post-service
activity: 11 of the listening's instrumental/silence windows sat there) are never widened into or
proposed. The existing proposal path's "after the first section starts" test is kept for unheld
time; it does not apply to existing sections, which is why this definition is needed.

**R2 — song widening across a typed neighbour** (`SustainedSoundSongSections`, classifier-gated).
Today widening is confined to time no section holds. With the timeline, a song's edge may widen into
an adjacent interior **`other`** section while the classifier hears contiguous **music** windows
from the edge, stopping at the first mixed, speech or neither window, or at a dropout barrier. The neighbour shrinks; if nothing remains, it is
removed. Always **held** (`FLAG_…` on the song, reason naming the classifier span), because it moves
an edge the detector chose. Never into prayer, reading, sermon or talk: those carry speech over
music (a leader over the band), which is what the refusal protects today. 1028, 1262.

**R3 — an `other` section heard as music** (`SustainedSoundSongSections` proposal path). An
interior `other` section whose music share ≥ 80 % and speech share ≤ 20 %, not adjacent to a song
(else R2 applies) and not overlapping a dropout, becomes a proposed song "Unidentified singing"
with `FLAG_UNIDENTIFIED_SINGING` (held), the shape the proposal path already produces. The existing
45 s minimum stays. 1304 canary-4 shape.

`MistypedSungSections` is left as it is in this plan: measure whether its RMS+word-rate test can be
replaced by the timeline in the follow-up (§11).

All changed rules stay registered in `DetectorCatalogue` with their evidence, and feed
`SoundStageFlagRecompute` only where no insert is needed (R1 flags, not R1 edge moves; not R2 or R3).

### 6.6 Freezing the evidence

- `HistoricRerunState::capture()` records `audio_timeline_sha256` and `model_revision` beside
  `transcript_sha256`, and `HistoricRerunState::VERSION` goes from 1 to 2 (snapshots taken before
  this commit are refused on load, which is right: canary 5 takes a new snapshot).
- `HistoricRerunDiff::compare()` compares an explicit field list (`status`, `current_step`,
  `superseded`, `transcript_sha256`, `sermon_absence`, `extraction_plan`); both new fields are added
  to it. Only then does a replaced timeline show as a change and `CorpusRerunGuard` refuse the run
  ("run has changed since the snapshot").
- `CorpusRerunGuard` also refuses a run with no timeline.
- Re-detection reads the recorded timeline; nothing in the re-run regenerates it. A new timeline
  (new model revision, repaired source) needs a new snapshot.
- Tests go through the **actual guard** (`CorpusRerunGuard::refusal()`), not the state class alone:
  timeline replaced after the snapshot refused; model revision changed refused; missing timeline
  refused; version-1 snapshot refused on load; short-coverage timeline refused at write.

### 6.7 Corpus backfill (historic operator tooling)

`historic-import:classify-audio {runs} [--execute]`: writes the timeline for existing runs,
create-once, resumable, run in the container on CPU. It selects the input exactly as the job does
(the run's recorded `audio` service artifact, on its recorded disk; staged copies live under
`historic-batches/<batch>/…`), calls the same job code rather than a parallel path, and so gets the
same preprocessing, hash and duration check. The dry run lists runs with no `audio` artifact or a
duration mismatch; those are resolved (or excluded) before `--execute`, never classified from a
different file.

**Most historic runs have lost their `audio` entry (found 2026-09-25).** Only 17 of 442 completed
historic runs record one; run 1028 records only `rms`, though its `.mp3` is on the staging volume.
This is the known processing-metadata lost update (`TranscribeFullService` saved a stale snapshot
over `ServiceArtifactStorage::record()`), fixed in `0be07a053` for runs transcribed since. So the
backfill first **re-attaches** the orphaned audio: for a run with no `audio` entry it derives
`service-audio/<date>/<service>-<processingId>.mp3` from `basePath()` (the exact name
`archiveAudio()` writes), requires the file to exist on the run's staging disk and its duration to
match the RMS log, and records the entry through `writeProcessingMetadata()`. Only then does it
classify. The dry run reports re-attach, classify and refuse counts separately.

## 7. Order of work

0. **Evidence first (operator + read-only).**
   - ✅ Fresh listening set for M and S (2026-09-25): rule validated, §6.3.
   - ✅ Edge checks (2026-09-25): 1028 music stops at 1121.7 s and speech starts at 1125.2 s
     (operator marks), matching the classifier (music to ~1123, speech from 1125); the widened song
     end is ~1122. 1262: the music from ~1090 is sung, so §3282 (1090–1121) is the start of
     §3283 and the song starts at ~1090 (the classifier's edge; not an operator mark). It would be
     part of the song even if it were an instrumental introduction (§3).
   - ✅ Dropout scan (2026-09-25, all 442 completed historic runs, read-only,
     `storage/scratch/interior-dropout-scan-20260925/`), using `AudioDropoutInsideTalk`'s measure.
     1,184 songs; 29 song/dropout overlaps in 24 sections (1138 §2123 has both an interior and an edge dropout). What R1 would do to them:
     - **whole, digital zero, flag and hold:** 1050 §1584;
     - **edge, digital zero, move without hold:** 1031 §1423 (leading, zeros to 533.7 s), 1342
       §4319 (from 1776.1 s), 1346 §4377 (from 3901.6 s), 1353 §4457 (from 3833.1 s);
     - **edge, ≤ −80 dB only, flag and hold:** 993 §1181, 1028 §1399, 1100 §1848, 1138 §2123,
       1198 §2487, 1198 §2490;
     - **interior, flag and hold:** 14 sections, none digital zero (967, 999, 1030, 1036, 1051,
       1117, 1119, 1138 ×2, 1159, 1263, 1269, 1295, 1382). 999 §1212 and 1159 §2241 are mostly
       dead feed with a little live audio at each end; 1138 §2120 is a 709 s "song" with four
       dropouts, more likely a structure defect than a dropout case.
     So R1 would hold 20 sections and move 4 edges across the corpus; the §8 precision gate listens
     to all 4 moves and a random 10 of the holds.
1. `scripts/classify_audio.py` + Dockerfile changes (both images); measure batched CPU throughput
   in the container.
2. `AudioTimeline` (unit tests on a fixture artifact, exact §6.3 rule).
3. Artifact, column, job, phases for livestream and auto-trim, reuse, detector refusal (feature
   tests).
4. R1 (failing tests first from 1050, 1346, an interior dropout like 1304 900–1140 s; digital-zero
   versus ≤ −80 dB edge overrun).
5. R2 and R3 with the interior definition and dropout barriers (failing tests first from 1028,
   1262, 1304 canary-4 fixture; leading/trailing `other` never touched), then a composed
   sound-stage test.
6. Prompt block §6.4.
7. Snapshot fields and guard refusals §6.6.
8. Backfill command; run over the canary set, then the eligible corpus.
9. Measurement §8, then worker restart, snapshot, **canary 5**: both tiers, diff, Tier C, diff, freeze.

## 8. Measurement

**Fixture tests (deterministic, in CI):** every row of §2 with a rule in its last column, as a
structure + RMS log excerpt + timeline excerpt.

**Rule replay (read-only, before canary 5)** over current structures of the eligible corpus, per
era. Predeclared pass:
- 1050 flagged; 1346's edge moved to ~3902 s with no hold (§12 ruling 2);
- 1028 and 1262 widened to within one window of the §2 edges;
- no R2/R3 action on any `speech` verdict window, on the 18 speech edge rows, or on the three spoken
  psalm/Revelation readings;
- each rule passes the precision gate: ≤ 1 wrong in 10 listened actions (§12 ruling 4).

**End-to-end at canary 5** (the prompt changes model output, so only a real detection run shows it):
- 1304: 261–360 a song (proposed or identified) and no song section over 190–220 without singing
  of that song;
- no run gains a song section before its first spoken section that it did not have in canary 4
  (pre-service music, §12 ruling 5);
- no regression in the canary 3 → 4 diff classes already accepted.

## 9. Tests

- `AudioTimeline`: duration-weighted shares with partial windows; M/S boundaries; the four states
  (a 0.70/0.70 window is mixed, not speech); malformed and short-coverage artefacts refused.
- Script call: non-zero exit and bad JSON fail the job; no partial artefact written; `input_sha256`,
  `preprocessing` and `runtime` recorded.
- Job/phase: input is the recorded `audio` artifact; a run without one fails with a reason; duration
  mismatch against the RMS log refused; artifact written under `service-transcripts/` and resolved
  to the durable disk (with distinct temp and durable disks); path recorded; resume reuses; both
  pipelines include the phase after `transcribe_full_service`.
- Detector: refuses a run with no timeline; prompt shows music spans ≥ 15 s and a 5 s mixed span
  touching one (1304 215–220 s), and omits a lone short mixed span.
- Rules: each §2 fixture; R1 interior dropout flagged, not trimmed; ≤ −80 dB (non-zero) edge overrun
  held, digital-zero overrun moved with no hold; R2 stops at a mixed window and never widens into
  prayer/reading/sermon/talk; R2/R3 never touch a leading or trailing `other` (including a first
  `other` of instrumental music before the first song); no edge crosses a dropout barrier in the
  composed sound stage; R1 talk behaviour unchanged.
- Snapshot/guard: through `CorpusRerunGuard::refusal()` as listed in §6.6.

## 10. Production

Production is a separate machine. It gets the classifier by the normal image build: the Dockerfile
change and baked weights ship with the commit. Before weekly processing resumes:
- confirm the prod host's CPU and memory headroom for ~3.5 min of CPU per service (AST base, ~87 M
  parameters; resident memory is an estimate until step 1 measures it);
- confirm the image size increase is acceptable for deploys.

Weekly processing is paused until the historic work is done, so this does not block canary 5, but
it blocks resuming weekly processing.

## 11. Out of scope

- Lyric transcription (Whisper failed; revisit with a lyrics-trained model later).
- Telling a sung span from a wholly instrumental one by sound. The classifier cannot ("Singing" and
  "Choir" are weak), so position stands in for it (§6.5 service interior), which matches the
  ruling in §3: inside the service a music span is a song. A span-level singing test would only
  matter for a sung item inside a leading or trailing `other` (§12 ruling 5, unmeasured); revisit
  if listening shows that happens.
- Replacing the RMS judgements in `SustainedSoundSongSections`, `SongSpeechEdges` and
  `MistypedSungSections` with the timeline. Once the timeline exists everywhere, measure whether the
  RMS paths can be deleted; that is a follow-up.

## 12. Operator rulings (2026-09-25)

1. **Dependencies: approved.** `transformers` and `torchaudio` in both Dockerfiles; model weights
   baked into the image at a pinned revision.
2. **Digital-zero edges: move without a hold.** A song edge that runs ≥ 15 s into verified digital
   zero (every frame `-inf`) moves to where the zeros begin, with a note on the section. Overruns
   into audio that is only ≤ −80 dB, interior dropouts and whole songs over dead feed stay held.
3. **Canary 5: the 13 plus 1028, 1262, 1346 and 1050, plus one run with an interior song
   dropout.** Checked 2026-09-25: 1304's dropouts (903–1014 s, 1021–1120 s) fall in §3874 `other`
   ("Baptism of Roy") and unsectioned time; its next song starts at 1125 s, so no 1304 song
   overlaps one. The step 0 scan (§7) found 14; **1117** §1956 "Glory In The Highest" (song
   131–343 s, dropout 187.5–210.1 s, ≤ −80 dB, not zero) is the one added: a single short dropout
   with plenty of live song either side.
4. **No volume cap; a precision gate instead.** For each of R1 flags, R1 edge moves, R2 widenings
   and R3 proposals in the §8 replay, the operator listens to every action (≤ 10) or a random 10. A
   rule passes at ≤ 1 wrong in 10; one that fails is tightened before canary 5. Correct holds are
   the point of the review queue, not a burden.
5. **Pre-service and post-service music stays `other`**, and R2/R3 never act on a leading or
   trailing `other`. Recordings commonly start during pre-service background music: 42 of 441
   completed historic runs (10 %) open on an `other` section, and in 363 the first section starts
   within 5 s of the recording start, so much pre-service music sits inside the first typed
   section (a 30-recording classifier sample of the first 3 minutes found music openings of up to
   ~2 minutes). The cost, a real opening hymn inside a leading `other` being missed, is
   **unmeasured**: the classifier cannot tell it from instrumental music, so only listening can
   size it.

   Because most pre-service music is inside a typed first section rather than a leading `other`,
   the rules are not the risk; the **prompt** is. §6.4 gains a second instruction: music before the
   first spoken item of the service is pre-service music, typed `other`, not a song, unless the
   transcript or the order of service places a song there. Canary 5 checks that no run gains a song
   section before its first spoken section that it did not have in canary 4.
