# Section Extraction — Real-Service Regression Testing Guide

Tests section detection, transcript classification, order-of-service alignment, song matching, reading-reference resolution, and main-sermon extraction against a varied collection of real Sunday recordings.

This is deliberately a local, environment-specific regression harness. It uses the current development database and fixed local media. Portability is not a goal.

## Scope

The harness starts with a raw MP4 and stops after `ExtractSermon`.

It intentionally does **not** run the remainder of the production livestream chain:

- `SubmitToProcessing`, which creates the main `Sermon` record.
- Sermon enhancement, transcription, analysis, thumbnail generation, and completion.
- `PrepareSectionPublicationCandidates`, which extracts publishable sections such as children’s talks.
- Approval and publication of section candidates.

The expected intermediate result is therefore usually:

```text
status=processing
current_step=extraction_complete
```

The main sermon’s extracted media is attached to the `MediaProcessingLog`, but no main `Sermon` record is expected from this harness.

## Test assets

Media fixtures and generated test state live in `storage/scratch/` and are gitignored:

| File | Date | Service | Character |
|---|---|---|---|
| `Sunday 24th May 2026.mp4` | 2026-05-24 | AM | Simpler: ~70 min, single sermon block |
| `Sunday 14th June 2026.mp4` | 2026-06-14 | AM | Complex: ~76 min, includes children’s talk |
| `Sunday 30th April 2023.mp4` | 2023-04-30 | AM | Complex: two sermon-like blocks and children’s talk |
| `Sunday 17th November 2024.mp4` | 2024-11-17 | AM | Standard service; Luke 15 reading, guest preacher |
| `Sunday 21st January 2024.mp4` | 2024-01-21 | AM | Includes Heidelberg Catechism study |
| `Easter Sunday 5th April 2026.mp4` | 2026-04-05 | AM | Recording only; no OoS or service record exists yet |
| `10-30.mkv` | 2025-03-16 | AM | Recording for 16 March 2025 (named after capture start time) |
| `10-31_2.mkv` | 2025-09-28 | AM | Recording for 28 September 2025 |
| `10-30 2.mkv` | 2025-12-28 | AM | Recording for 28 December 2025 |
| `10-37.mkv` | 2026-03-22 | AM | Recording for 22 March 2026 |
| `2023-04-30 AM.osz` | 2023-04-30 | AM | OpenLP OoS for 30 April 2023 |
| `2024-01-21 AM.osz` | 2024-01-21 | AM | OpenLP OoS for 21 January 2024 |
| `2024-11-17 AM.osz` | 2024-11-17 | AM | OpenLP OoS for 17 November 2024; Luke 15 reading, guest preacher |
| `2025-03-16 AM.osz` | 2025-03-16 | AM | OpenLP OoS for 16 March 2025; includes Heidelberg Catechism |
| `2025-09-28 AM.osz` | 2025-09-28 | AM | OpenLP OoS for 28 September 2025; includes Andrew Talk children's slot |
| `2025-12-28 AM.osz` | 2025-12-28 | AM | OpenLP OoS for 28 December 2025; post-Christmas, only five items |
| `2026-03-22 AM.osz` | 2026-03-22 | AM | OpenLP OoS for 22 March 2026; Isaiah reading, two weeks before Easter |
| `2026-05-24 AM.osz` | 2026-05-24 | AM | OpenLP OoS for 24 May |
| `2026-06-14 AM.osz` | 2026-06-14 | AM | OpenLP OoS for 14 June |

Gemini-suggested comparison notes for the recordings are in `storage/scratch/orders-of-service.txt`. Use them to sense check what the processing produces, but beware they are not infallible themselves. Report on any inconsistencies. 

The executable harness is tracked in `scripts/section-extraction/`.

## Prerequisites

1. Start Sail:

   ```bash
   vendor/bin/sail up -d
   ```

2. Configure real local transcription and OpenAI classification:

   ```env
   TRANSCRIPTION_SERVICE_TYPE=local
   LOCAL_WHISPER_URL=http://whisper:8000
   ANALYSIS_SERVICE=openai
   OPENAI_API_KEY=...
   SERVICE_SECTION_CLASSIFICATION_MODEL=gpt-5
   ```

   If configuration has been cached, run:

   ```bash
   vendor/bin/sail artisan optimize:clear
   ```

3. Confirm the `whisper` container is running:

   ```bash
   vendor/bin/sail ps
   ```

4. Ensure a `ChurchService` exists in the local database for each test date and service.

5. Import the matching `.osz` file through **Admin → Services → Upload OoS**.

6. Confirm the MP4 files are present in `storage/scratch/`.

The runner records the current Git commit and classifier model in every saved post-transcription baseline. When expected output is updated, record both values beside the observed baseline.

## Tracked harness scripts

The tracked harness contains:

| Script | Purpose |
|---|---|
| `scenarios.php` | Shared fixture names, service identities, pid files, and stable assertions |
| `run-init.php` | Copies the MP4 to temporary processing storage and creates a `MediaProcessingLog` |
| `run-step2.php` | Runs RMS/visual analysis, segmentation, and initial service-section classification |
| `run-downstream.php` | Creates or restores the post-transcription baseline, then runs section classification and extraction |
| `run-cleanup.php` | Cancels an active test run if needed, then deletes its database records and generated media through application services |
| `verify-classifier.php` | Runs a read-only classifier probe against persisted sermon and children’s-talk transcripts |

Each runner accepts a `$scenario` value:

| Value | Recording |
|---|---|
| `'may24'` | Sunday 24 May 2026 |
| `'jun14'` | Sunday 14 June 2026 |
| `'apr23'` | Sunday 30 April 2023 |
| `'jan24'` | Sunday 21 January 2024 |
| `'nov24'` | Sunday 17 November 2024 |
| `'mar25'` | Sunday 16 March 2025 |
| `'sep25'` | Sunday 28 September 2025 |
| `'dec25'` | Sunday 28 December 2025 |
| `'mar26'` | Sunday 22 March 2026 |

Example:

```bash
vendor/bin/sail artisan tinker --execute '$scenario="may24"; require base_path("scripts/section-extraction/run-init.php");' 2>&1
```

### `run-init.php`

Stores the video on the configured temporary disk, extracts metadata, creates the processing log, and writes its UUID to the scenario’s pid file.

Starting a new run also removes that scenario’s old post-transcription baseline so it cannot accidentally be used with a different processing UUID.

### `run-step2.php`

Runs:

```text
GenerateRmsLog
→ PerformVisualAnalysis
→ AnalyzeSegments
→ ClassifyServiceSections
```

It prints the initial `LivestreamSegment` and `ServiceSection` records. Most unclassified sections initially have confidence `0.5` and require review; obvious sermon sections may already have higher confidence.

### `run-downstream.php`

Runs:

```text
ClassifySpeechSections
→ ProjectLivestreamServiceStructure
→ AlignWithOos
→ ResolveReadingReferences
→ MatchSongsFromTranscript
→ ReclassifyIntroOutroSections
→ ExtractSermon
```

The first invocation for a fresh run must include transcription:

```bash
vendor/bin/sail artisan tinker --execute '$scenario="may24"; $includeTranscription=true; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1
```

This runs `TranscribeSpeechSegments` and saves the untouched post-transcription sections to:

```text
storage/scratch/{scenario}_post_transcription_baseline.json
```

Later invocations omit `$includeTranscription`. The script restores that baseline before classification, preventing prior classifier output, song matches, alignment metadata, or section types from contaminating a rerun.

`ServiceSectionStatus::Identified` alone is not a clean baseline: previously classified `song` and `sermon` sections are deliberately skipped by `ClassifySpeechSections`. Restoring the saved post-transcription records is therefore required for meaningful classifier comparisons.

The runner:

- Fails immediately when a job throws.
- Exits unsuccessfully when a stable assertion fails.
- Refuses to restore over section-publication artifacts; clean up and initialise a new run instead.
- Reports only `Sermon` records associated with the current processing UUID or its published sections.

### `run-cleanup.php`

Cancels an active run through `ProcessingRunOrchestrator`, then deletes it through `DeleteLivestreamUpload`. This also cleans generated source, extraction, transcript, and section media known to the application.

```bash
vendor/bin/sail artisan tinker --execute '$scenario="may24"; require base_path("scripts/section-extraction/run-cleanup.php");' 2>&1
```

Do not delete `MediaProcessingLog`, `LivestreamSegment`, or `ServiceSection` rows directly: direct database deletion can leave generated media behind.

## Running a fresh scenario

```bash
# 1. Create a fresh processing run.
vendor/bin/sail artisan tinker --execute '$scenario="may24"; require base_path("scripts/section-extraction/run-init.php");' 2>&1

# 2. Generate segments and initial sections.
vendor/bin/sail artisan tinker --execute '$scenario="may24"; require base_path("scripts/section-extraction/run-step2.php");' 2>&1 | tee storage/scratch/may24_step2.out

# 3. Transcribe, save the clean baseline, classify, and extract.
vendor/bin/sail artisan tinker --execute '$scenario="may24"; $includeTranscription=true; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/may24_downstream.out
```

## Re-running after classifier or prompt changes

```bash
vendor/bin/sail artisan tinker --execute '$scenario="may24"; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/may24_downstream.out
vendor/bin/sail artisan tinker --execute '$scenario="jun14"; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/jun14_downstream.out
```

Each invocation restores the same post-transcription input before calling the classifier.

Create a new transcription baseline when:

- The transcription service, Whisper model, or speech-segment boundaries change.
- Step 2 behavior changes.
- The MP4 fixture changes.
- The baseline belongs to a different processing UUID.

## Stable assertions versus observed output

Stable assertions are intended to catch meaningful regressions:

- Pipeline status and step at the harness boundary.
- Associated church-service identity.
- Section count for established scenarios.
- Confirmed song-match count.
- Presence or absence of a children’s-talk section.
- Main sermon time range, with a ±15-second tolerance.

Exact boundaries, confidence values, transcript excerpts, and item IDs are captured as **observed baselines**. They may move slightly when models or prompts change and should be reviewed rather than blindly frozen.

`transcript_alignment=content_anchor` is expected only on AI-split speech sections where the model’s `start_text` can be found in the transcript. Original RMS-derived songs, sermons, or untouched sections may have no `transcript_alignment` value.

## Scenario A — 24 May 2026

Observed on 2026-06-20 using commit `fc0777b` and classifier model `gpt-5`.

### Stable expectations

| Check | Expected |
|---|---|
| Status | `processing` |
| Current step | `extraction_complete` |
| Church service | `791` |
| Section count | 10 |
| Confirmed songs | 2 |
| Children’s talks | 0 |
| Main sermon | approximately 33:23–69:49 |
| Main `Sermon` record | Not created by this harness |

### Observed section baseline

```text
1   0:00-16:01  other          match=-            item=5287  conf=0.85 [review] -> "24May"
2  16:01-19:10  song           match=confirmed    item=5288  conf=0.9  [ok]     -> "There's A Place Where The Streets Shine" [song#943]
3  19:10-20:05  bible_reading  match=-            item=-     conf=0.92 [ok]     align=content_anchor READING=John 14:1-6(transcript_ai)
4  20:05-20:45  other          match=-            item=5289  conf=1    [ok]     align=content_anchor -> "Bible Reading"
5  20:45-21:50  bible_reading  match=-            item=5290  conf=1    [review] align=content_anchor READING=Joshua 1:1-4(transcript_ai) flags=reading_reference_conflict
6  21:50-23:10  bible_reading  match=-            item=-     conf=0.92 [review] align=content_anchor READING=Joshua 1:4-9(transcript_ai)
7  23:10-29:50  prayer         match=-            item=-     conf=0.98 [review] align=content_anchor
8  29:50-30:34  song           match=inferred     item=5286  conf=0.74 [review] -> "O king, you are most worthy..." [song#708] flags=song_alignment_inferred,unmatched_song_section
9  30:34-33:23  song           match=confirmed    item=5291  conf=0.9  [ok]     -> "Speak O Lord" [song#859]
10 33:23-69:49  sermon         match=-            item=-     conf=0.9  [ok]
```

Notes:

- The content-anchor classifier surfaces the John 14 reading that the old time-ratio classifier swallowed inside the opening block.
- The Joshua reading is split across sections 5 and 6 after a pause. Section 5 receives a `reading_reference_conflict` because the OoS contains Joshua 1:1-9 while its transcript slice resolves to Joshua 1:1-4.
- Section 4 is a genuine presenter transition between readings.
- OoS item 5287 is an OpenLP title slide and remains aligned to the opening block.
- `ExtractSermon` produces the main sermon media on the processing log. `SubmitToProcessing` is intentionally not run, so no main `Sermon` record is created.

## Scenario B — 14 June 2026

Observed on 2026-06-20 using commit `fc0777b` and classifier model `gpt-5`.

### Stable expectations

| Check | Expected |
|---|---|
| Status | `processing` |
| Current step | `extraction_complete` |
| Church service | `790` |
| Section count | 20 |
| Confirmed songs | 4 |
| Children’s talks | 1 |
| Main sermon | approximately 40:20–68:48 |
| Main `Sermon` record | Not created by this harness |
| Children’s-talk publication record | Not exercised by this harness |

### Observed section baseline

```text
1   0:00-0:55   notices        match=-            item=5278  conf=1    [ok]     align=content_anchor -> "Notices"
2   0:55-3:08   bible_reading  match=-            item=-     conf=0.92 [ok]     align=content_anchor
3   3:08-3:47   song           match=unmatched    item=-     conf=0.72 [review] align=content_anchor flags=unmatched_song_section
4   3:47-6:46   song           match=confirmed    item=5279  conf=0.9  [ok]     -> "Praise To The Lord The Almighty" [song#800]
5   6:46-9:08   prayer         match=-            item=-     conf=0.98 [ok]     align=content_anchor
6   9:08-18:16  childrens_talk match=-            item=-     conf=0.95 [ok]     align=content_anchor
7  18:16-19:46  prayer         match=-            item=-     conf=0.9  [ok]     align=content_anchor
8  19:46-21:16  other          match=-            item=5280  conf=1    [ok]     align=content_anchor -> "Ezekiel"
9  21:16-21:51  notices        match=-            item=-     conf=0.9  [ok]     align=content_anchor
10 21:51-24:46  prayer         match=-            item=-     conf=0.9  [ok]     align=content_anchor
11 24:46-30:46  prayer         match=-            item=-     conf=0.98 [ok]     align=content_anchor
12 30:46-33:46  bible_reading  match=-            item=-     conf=0.98 [ok]     align=content_anchor
13 33:46-37:10  other          match=-            item=5281  conf=1    [ok]     align=content_anchor -> "Bibles.mp4"
14 37:10-40:20  song           match=confirmed    item=5283  conf=0.9  [ok]     -> "Prepare our hearts, O God" [song#1117]
15 40:20-68:48  sermon         match=-            item=-     conf=0.98 [ok]
16 68:48-69:43  song           match=unmatched    item=-     conf=0.5  [review] flags=unmatched_song_section
17 69:43-72:33  song           match=confirmed    item=5284  conf=0.9  [ok]     -> "What love could remember" [song#1013]
18 72:33-72:43  other          match=-            item=5282  conf=0.85 [ok]     -> "Bible Reading"
19 72:43-75:33  song           match=confirmed    item=5285  conf=0.9  [ok]     -> "When I Was Lost" [song#1024]
20 75:33-76:09  bible_reading  match=-            item=-     conf=0.8  [review] align=content_anchor
```

Notes:

- `processing/extraction_complete` is the expected harness outcome.
- The content-anchor classifier identifies the children’s talk as a distinct nine-minute section, leaving one high-confidence main sermon.
- OoS item 5280, “Ezekiel”, aligns to the presenter announcement after the talk. This reflects when the OpenLP slide was cued.
- Sections 10 and 11 form a prolonged missions-prayer block.
- The extraction strategy currently uses `non_adjacent_bible_plus_sermon_concat`, pairing section 2 with section 15. This remains a known selection issue.
- The harness stops before `PrepareSectionPublicationCandidates`, approval, and publication, so it cannot prove or disprove children’s-talk publication behavior.

## Known output noise

- Guzzle deprecation warnings about string request timeouts may be emitted by `MatchSongsFromTranscript`.
- PHP float-to-int deprecations may be emitted from cached views during extraction.

Treat these as known noise only when the jobs complete and all stable assertions pass.

## Classifier-only probe

`verify-classifier.php` runs the classifier against persisted sermon and children’s-talk transcripts for a selected scenario without writing changes:

```bash
vendor/bin/sail artisan tinker --execute '$scenario="jun14"; require base_path("scripts/section-extraction/verify-classifier.php");' 2>&1
```

This is useful for inspecting model or prompt output, but the baseline-restoring `run-downstream.php` flow remains the authoritative regression run.

## Scenarios C and D

These recordings require a first transcription pass of approximately 15–40 minutes each.

### Prerequisite checks

```bash
vendor/bin/sail artisan tinker --execute 'echo \App\Models\ChurchServiceItem::where("church_service_id", 481)->count();'
vendor/bin/sail artisan tinker --execute 'echo \App\Models\ChurchServiceItem::where("church_service_id", 546)->count();'
```

The current database is expected to contain 6 items for service 481 and 9 for service 546.

### Scenario C — 30 April 2023

Observed on 2026-06-20 using commit `fc0777b` and classifier model `gpt-5`.

```bash
vendor/bin/sail artisan tinker --execute ‘$scenario=”apr23”; require base_path(“scripts/section-extraction/run-init.php”);’ 2>&1
vendor/bin/sail artisan tinker --execute ‘$scenario=”apr23”; require base_path(“scripts/section-extraction/run-step2.php”);’ 2>&1 | tee storage/scratch/apr23_step2.out
vendor/bin/sail artisan tinker --execute ‘$scenario=”apr23”; $includeTranscription=true; require base_path(“scripts/section-extraction/run-downstream.php”);’ 2>&1 | tee storage/scratch/apr23_downstream.out
```

#### Stable expectations

| Check | Expected |
|---|---|
| Status | `processing` |
| Current step | `extraction_complete` |
| Church service | `481` |
| Section count | 13 |
| Confirmed songs | 4 |
| Children’s talks | 1 |
| Main sermon | approximately 35:43–66:41 |
| Main `Sermon` record | Not created by this harness |

#### Observed section baseline

```text
1   0:00-0:15   welcome        match=-            item=-     conf=0.75 [review] align=content_anchor flags=oos_structure_mismatch
2   0:15-5:50   notices        match=-            item=-     conf=0.77 [review] align=content_anchor flags=oos_structure_mismatch
3   5:50-6:40   bible_reading  match=-            item=-     conf=0.9  [ok]     align=content_anchor
4   6:40-10:20  song           match=confirmed    item=3257  conf=1    [ok]     align=content_anchor -> “Come People Of The Risen King” [song#191]
5  10:20-15:50  prayer         match=-            item=-     conf=0.96 [ok]     align=content_anchor
6  15:50-19:00  childrens_talk match=-            item=-     conf=0.96 [ok]     align=content_anchor
7  19:00-22:03  song           match=confirmed    item=3258  conf=0.9  [review] -> “We Have Heard A Joyful Sound” [song#991]
8  22:03-29:13  prayer         match=-            item=-     conf=0.96 [ok]     align=content_anchor
9  29:13-31:37  bible_reading  match=-            item=-     conf=0.95 [ok]     align=content_anchor
10 31:37-35:43  song           match=confirmed    item=3259  conf=0.9  [review] -> “Creator God” [song#205]
11 35:43-66:41  sermon         match=-            item=-     conf=0.9  [ok]
12 66:41-69:40  song           match=confirmed    item=3260  conf=0.9  [review] -> “O Jesus I Have Promised #901” [song#706]
13 69:40-70:09  bible_reading  match=-            item=-     conf=0.95 [ok]     align=content_anchor
```

Notes:

- Section 6 (`childrens_talk`, 15:50–19:00) absorbs the brief ~2-minute “Look and Live” intro sermon that follows the children’s talk. See finding F6.
- Section 9 is the Jude 1:1-7 reading directly before the main sermon; `ExtractSermon` uses `non_adjacent_bible_plus_sermon_concat` but incorrectly pairs section 3 (Revelation 1) instead. See findings F3 and F5.
- Section 13 is the closing Jude 1:24-25 doxology, classified as `bible_reading` — technically correct per content.
- Sections 1 and 2 carry `oos_structure_mismatch` because the Apr 2023 OoS has only 6 items and does not include discrete welcome/notices entries.

### Scenario D — 21 January 2024

Observed on 2026-06-20 using commit `fc0777b` and classifier model `gpt-5`.

```bash
vendor/bin/sail artisan tinker --execute '$scenario="jan24"; require base_path("scripts/section-extraction/run-init.php");' 2>&1
vendor/bin/sail artisan tinker --execute '$scenario="jan24"; require base_path("scripts/section-extraction/run-step2.php");' 2>&1 | tee storage/scratch/jan24_step2.out
vendor/bin/sail artisan tinker --execute '$scenario="jan24"; $includeTranscription=true; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/jan24_downstream.out
```

#### Stable expectations

| Check | Expected |
|---|---|
| Status | `processing` |
| Current step | `extraction_complete` |
| Church service | `546` |
| Section count | 21 |
| Confirmed songs | 4 |
| Children's talks | 1 (Heidelberg Catechism — known misclassification, see F4) |
| Main sermon | approximately 32:44–62:24 |
| Main `Sermon` record | Not created by this harness |

#### Observed section baseline

```text
1   0:00-0:11   welcome        match=-            item=-     conf=0.96 [ok]     align=content_anchor
2   0:11-0:13   notices        match=-            item=3648  conf=1    [ok]     align=content_anchor -> "Notices"
3   0:13-3:27   other          match=unmatched    item=-     conf=0.4  [review] flags=unmatched_song_section
4   3:27-4:17   welcome        match=-            item=-     conf=0.7  [review] align=content_anchor flags=oos_structure_mismatch
5   4:17-5:29   song           match=confirmed    item=3650  conf=1    [ok]     align=content_anchor -> "Great Is The Lord" [song#318]
6   5:29-6:52   bible_reading  match=-            item=-     conf=0.95 [ok]     align=content_anchor
7   6:52-8:36   song           match=confirmed    item=3655  conf=0.9  [review] -> "God is for us" [song#1111]
8   8:36-8:39   other          match=-            item=3653  conf=1    [ok]     align=content_anchor -> "Reading"
9   8:39-8:56   song           match=confirmed    item=3656  conf=1    [ok]     align=content_anchor -> "God is for us" [song#1111]
10  8:56-11:36  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
11 11:36-18:36  childrens_talk match=-            item=-     conf=0.9  [ok]     align=content_anchor
12 18:36-20:06  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
13 20:06-22:16  bible_reading  match=-            item=3654  conf=1    [ok]     align=content_anchor -> "Colossians 1:15-23" READING=Colossians 1:15-23(transcript_ai)
14 22:16-28:36  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
15 28:36-29:56  song           match=confirmed    item=3652  conf=1    [ok]     align=content_anchor -> "Lord Jesus build your church t..." [song#594]
16 29:56-31:54  song           match=unmatched    item=-     conf=0.4  [review] flags=unmatched_song_section
17 31:54-32:44  song           match=unmatched    item=-     conf=0.7  [review] align=content_anchor flags=unmatched_song_section
18 32:44-62:24  sermon         match=-            item=-     conf=0.98 [ok]     align=content_anchor
19 62:24-64:34  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
20 64:34-66:19  song           match=unmatched    item=-     conf=0.75 [review] align=content_anchor flags=unmatched_song_section
21 66:19-66:54  prayer         match=-            item=-     conf=0.9  [ok]     align=content_anchor
```

Notes:

- Section 2 (2 seconds) and section 8 (3 seconds) are micro-sections emitted for OoS slide transitions. See finding F7.
- Section 3 (0:13–3:27) is an unmatched opening song not listed in Gemini's order of service — possibly pre-service warm-up music. See finding F8.
- Section 11 is the Heidelberg Catechism study (correctly distinct from the sermon but misclassified as `childrens_talk`). See finding F4.
- Section 13 (Colossians 1:15-23) is the preached text and is correctly resolved; however `ExtractSermon` uses `non_adjacent_bible_plus_sermon_concat` and pairs section 6 (Romans 8, 5:29) over it. See finding F5.
- Sections 16 and 17 are unmatched song fragments around the pre-sermon song transition.
- Section 20 ("Oh Jesus I Have Promised") is unmatched despite a readable transcript. See finding F8.
- Section 21 is the closing benediction (Jude 1:24-25), correctly identified as a prayer/doxology.
- `content_anchor` alignment is present on all AI-split speech sections where `start_text` was matchable.

## Scenarios E–I

These five services have `.osz` files in `storage/scratch/`. All five also have video recordings: Scenario E as a conventional `Sunday … .mp4`, and Scenarios F–I as `.mkv` files named after their capture start time.

### Prerequisite checks

Verify OoS item counts are correct before running each scenario:

```bash
vendor/bin/sail artisan tinker --execute 'echo \App\Models\ChurchServiceItem::where("church_service_id", 607)->count();'
vendor/bin/sail artisan tinker --execute 'echo \App\Models\ChurchServiceItem::where("church_service_id", 635)->count();'
vendor/bin/sail artisan tinker --execute 'echo \App\Models\ChurchServiceItem::where("church_service_id", 688)->count();'
vendor/bin/sail artisan tinker --execute 'echo \App\Models\ChurchServiceItem::where("church_service_id", 713)->count();'
vendor/bin/sail artisan tinker --execute 'echo \App\Models\ChurchServiceItem::where("church_service_id", 735)->count();'
```

Expected item counts: 9 for service 607 (E), 9 for 635 (F), 8 for 688 (G), 5 for 713 (H), and 9 for 735 (I).

### Scenario E — 17 November 2024

Observed on 2026-06-21 using commit `fc0777b` and classifier model `gpt-5`.

```bash
vendor/bin/sail artisan tinker --execute '$scenario="nov24"; require base_path("scripts/section-extraction/run-init.php");' 2>&1
vendor/bin/sail artisan tinker --execute '$scenario="nov24"; require base_path("scripts/section-extraction/run-step2.php");' 2>&1 | tee storage/scratch/nov24_step2.out
vendor/bin/sail artisan tinker --execute '$scenario="nov24"; $includeTranscription=true; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/nov24_downstream.out
```

Review questions:

- Are all five songs matched, including the two that appear after the sermon ("All I Have Is Christ", "Behold The Lamb")?
- Is the Luke 15:1-32 reading identified and its reference resolved?
- Does the opening notices block align to the `Notices2024Looped.pptx` OoS item?

**This scenario is a known pipeline failure.** See finding F10. The RMS and visual analyser produced only three segments for the full 68-minute recording, and `ClassifyServiceSections` labelled the first 65 minutes as `sermon` at high confidence before `ClassifySpeechSections` could split it. The observed baseline below reflects that failure; do not use it to gate regressions until F10 is addressed.

Gemini's comparison notes do not include an entry for this service.

#### Step 2 segments

```text
0    0:00-65:16    3916s  speech   rms=-50   vis=-
1   65:16-67:37     141s  song     rms=-43.7 vis=0.54
2   67:37-68:22      45s  speech   rms=-50   vis=-
```

#### Stable expectations

| Check | Expected |
|---|---|
| Status | `processing` |
| Current step | `extraction_complete` |
| Church service | `607` |
| Section count | 3 (under-detection — see F10) |
| Confirmed songs | 1 |
| Children's talks | 0 |
| Main sermon | 0:00–65:16 (the full speech block, not a true sermon boundary) |
| Main `Sermon` record | Not created by this harness |

#### Observed section baseline

```text
1   0:00-65:16  sermon         match=-            item=-     conf=0.9  [ok]
2  65:16-67:37  song           match=confirmed    item=4091  conf=0.9  [review] -> "All I Have Is Christ" [song#58]
3  67:37-68:22  bible_reading  match=-            item=4096  conf=1    [review] align=content_anchor -> "Luke 15:1-32" READING=Luke 15:1-32(transcript_ai) flags=reading_reference_conflict
```

Notes:

- Review question (1): Only 1 of 5 expected songs was matched. All songs within the first 65-minute block were invisible to the RMS analyser. "Behold The Lamb" is absent entirely.
- Review question (2): Luke 15:1-32 appears as `READING` in section 3 (67:37–68:22), but this section is at the end of the recording, not before the sermon. The `reading_reference_conflict` flag fires. The reading reference may be spurious — section 3 is the closing remarks, not the Luke 15 reading. See finding F14.
- Review question (3): No notices block was produced. The opening `Notices2024Looped.pptx` slide alignment question cannot be answered from this run.
- `ExtractSermon` used `sermon_only` strategy, trimming 0–65:16 as the main sermon span.
- Section 2 (65:16–67:37) is "All I Have Is Christ" [song#58], matched to OoS item 4091 (pre-sermon position). The actual detected song is at the post-sermon position. See finding F13.

### Scenario F — 16 March 2025

Observed on 2026-06-21 using commit `fc0777b` and classifier model `gpt-5`.

```bash
vendor/bin/sail artisan tinker --execute '$scenario="mar25"; require base_path("scripts/section-extraction/run-init.php");' 2>&1
vendor/bin/sail artisan tinker --execute '$scenario="mar25"; require base_path("scripts/section-extraction/run-step2.php");' 2>&1 | tee storage/scratch/mar25_step2.out
vendor/bin/sail artisan tinker --execute '$scenario="mar25"; $includeTranscription=true; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/mar25_downstream.out
```

Review questions:

- Is the Heidelberg Catechism section (Q&A 123) kept distinct from the main sermon?
- Which section is selected as the main sermon when both the catechism and the sermon are present?
- Is the Luke 18:9-14 reading (Pharisee and Tax Collector) correctly identified and resolved?

#### Stable expectations

| Check | Expected |
|---|---|
| Status | `processing` |
| Current step | `extraction_complete` |
| Church service | `635` |
| Section count | 18 |
| Confirmed songs | 3 |
| Children's talks | 1 (Heidelberg Catechism — misclassified, see F11) |
| Main sermon | approximately 38:00–64:15 |
| Main `Sermon` record | Not created by this harness |

#### Observed section baseline

```text
1   0:00-4:30   notices        match=-            item=4317  conf=1    [ok]     align=content_anchor -> "Notices2025Looped.pptx"
2   4:30-6:30   bible_reading  match=-            item=-     conf=0.95 [ok]     align=content_anchor
3   6:30-10:00  song           match=confirmed    item=4318  conf=1    [ok]     align=content_anchor -> "Holy Holy Holy Lord God Almigh..." [song#368]
4  10:00-14:30  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
5  14:30-19:30  childrens_talk match=-            item=-     conf=0.9  [ok]     align=content_anchor
6  19:30-21:30  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
7  21:30-22:37  song           match=confirmed    item=4320  conf=1    [ok]     align=content_anchor -> "Let Your Kingdom Come" [song#1128]
8  22:37-24:42  song           match=unmatched    item=-     conf=0.3  [review] flags=unmatched_song_section
9  24:42-25:10  other          match=-            item=4319  conf=0.95 [review] align=content_anchor -> "Heidelberg Q123.pptx"
10 25:10-25:22  song           match=unmatched    item=-     conf=0.5  [review] align=content_anchor flags=unmatched_song_section
11 25:22-27:02  bible_reading  match=-            item=-     conf=0.95 [ok]     align=content_anchor
12 27:02-34:27  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
13 34:27-34:45  other          match=-            item=4321  conf=1    [review] align=content_anchor -> "Reading"
14 34:45-38:00  song           match=confirmed    item=4323  conf=0.9  [ok]     -> "Your Word" [song#1101]
15 38:00-64:15  sermon         match=-            item=-     conf=0.95 [ok]     align=content_anchor
16 64:15-65:10  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
17 65:10-67:28  song           match=unmatched    item=-     conf=0.3  [review] flags=unmatched_song_section
18 67:28-67:56  bible_reading  match=-            item=4322  conf=1    [ok]     align=content_anchor -> "Luke 18:9-14" READING=Luke 18:9-14(transcript_ai)
```

Notes:

- Review question (1): Catechism (section 5, 14:30–19:30) is distinct from sermon (section 15, 38:00–64:15). ✓
- Review question (2): Section 15 is selected as the main sermon. ✓
- Review question (3): Luke 18:9-14 is correctly identified at section 11 (25:22–27:02). ✓ However, section 18 (67:28–67:56, the closing benediction) is also incorrectly resolved to Luke 18:9-14. See finding F12.
- Section 2 (4:30–6:30) is the Revelation 4 reading, not Luke 18. `ExtractSermon` uses `non_adjacent_bible_plus_sermon_concat`, pairing section 2 (gap ~31 min) rather than section 11 (gap ~11 min). See finding F5.
- Section 5 is the Heidelberg Catechism study, misclassified as `childrens_talk`. See finding F11.
- Sections 7 and 8 together form "Let Your Kingdom Come", split at the RMS boundary at 22:37. See finding F16.
- Section 10 (25:10–25:22, 12 seconds) is a micro-section. See finding F7.
- Section 17 (65:10–67:28, "All I Once Held Dear" / "Knowing You") is unmatched. The song database likely holds it under a different title.
- The opening notices block aligned to `Notices2025Looped.pptx` (section 1). The pipeline boundary is 0:00–4:30; Gemini places notices ending at 2:20.

### Scenario G — 28 September 2025

Observed on 2026-06-21 using commit `fc0777b` and classifier model `gpt-5`.

```bash
vendor/bin/sail artisan tinker --execute '$scenario="sep25"; require base_path("scripts/section-extraction/run-init.php");' 2>&1
vendor/bin/sail artisan tinker --execute '$scenario="sep25"; require base_path("scripts/section-extraction/run-step2.php");' 2>&1 | tee storage/scratch/sep25_step2.out
vendor/bin/sail artisan tinker --execute '$scenario="sep25"; $includeTranscription=true; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/sep25_downstream.out
```

Review questions:

- Is the "Andrew Talk" identified as a children's talk and cleanly separated from the main sermon?
- What section does the classifier align to the `epap.pptx` OoS item? (Answer: none — see finding F15)
- Are all four songs matched?

#### Step 2 segments

```text
0    0:00-5:00      300s  speech   rms=-50   vis=-
1    5:00-7:00      121s  song     rms=-34.2 vis=0.54
2    7:00-22:54     953s  speech   rms=-50   vis=-
3   22:54-25:10     136s  song     rms=-32.1 vis=0.54
4   25:10-39:44     874s  speech   rms=-50   vis=-
5   39:44-42:37     173s  song     rms=-32.2 vis=0.55
6   42:37-79:02    2185s  speech   rms=-50   vis=-   → pre-classified as sermon (conf=0.9)
7   79:02-82:19     197s  song     rms=-32.2 vis=0.56
8   82:19-83:25      66s  speech   rms=-50   vis=-
```

RMS detection quality is notably better here than Scenario E: all four OoS songs are detected with consistent timing (King of Kings at 22:54 vs Gemini's 22:56; Prepare Our Hearts at 39:44 vs 39:48). The 36-minute pre-classified sermon block (segment 6) is genuinely the sermon, so its pre-labelling is correct.

#### Stable expectations

| Check | Expected |
|---|---|
| Status | `processing` |
| Current step | `extraction_complete` |
| Church service | `688` |
| Section count | 16 |
| Confirmed songs | 4 |
| Children's talks | 1 |
| Main sermon | approximately 42:37–79:02 |
| Main `Sermon` record | Not created by this harness |

#### Observed section baseline

```text
1   0:00-0:04   other          match=-            item=-     conf=0.7  [review] align=content_anchor
2   0:04-3:15   notices        match=-            item=4679  conf=1    [ok]     align=content_anchor -> "NoticesSept2025"
3   3:15-4:00   bible_reading  match=-            item=-     conf=0.72 [review] align=content_anchor flags=oos_structure_mismatch
4   4:00-5:00   song           match=confirmed    item=4680  conf=1    [ok]     align=content_anchor -> "All Praise To Him" [song#1123]
5   5:00-7:00   song           match=unmatched    item=-     conf=0.3  [review] flags=unmatched_song_section
6   7:00-10:50  prayer         match=-            item=-     conf=0.76 [review] align=content_anchor flags=oos_structure_mismatch
7  10:50-22:20  childrens_talk match=-            item=-     conf=0.97 [ok]     align=content_anchor
8  22:20-22:54  song           match=unmatched    item=-     conf=0.72 [review] align=content_anchor flags=unmatched_song_section
9  22:54-25:10  song           match=confirmed    item=4682  conf=0.9  [ok]     -> "King Of Kings Majesty" [song#546]
10 25:10-28:20  notices        match=-            item=-     conf=0.9  [ok]     align=content_anchor
11 28:20-35:10  prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
12 35:10-39:30  bible_reading  match=-            item=-     conf=0.95 [ok]     align=content_anchor
13 39:30-42:37  song           match=confirmed    item=4685  conf=0.9  [ok]     -> "Prepare our hearts, O God" [song#1117]
14 42:37-79:02  sermon         match=-            item=-     conf=0.9  [ok]
15 79:02-82:19  song           match=confirmed    item=4686  conf=0.9  [ok]     -> "Above The Voices Of The World..." [song#41]
16 82:19-83:25  bible_reading  match=-            item=-     conf=0.95 [ok]     align=content_anchor
```

Notes:

- Review question (1): "Andrew Talk" correctly identified as `childrens_talk` (section 7, 10:50–22:20, conf=0.97). Separated from the sermon (section 14, 42:37–79:02). ✓
- Review question (2): The `epap.pptx` OoS item (4683) is **not aligned to any section**. Section 10 (25:10–28:20, `notices`) covers the Harvest Appeal period (Gemini: 25:41) but carries no item alignment. See finding F15.
- Review question (3): All four OoS songs confirmed: "All Praise To Him" ✓, "King Of Kings Majesty" ✓, "Prepare our hearts" ✓, "Above The Voices" ✓.
- Section 1 (0:00–0:04, 4 seconds) is a micro-section. See finding F7.
- Section 3 (3:15–4:00, 45 seconds) captures the Nehemiah 9:5-6 reading, which is not in the OoS (`oos_structure_mismatch`). Its low confidence (0.72) prevents it from being selected as the preferred bible reading in `SermonExtractionPlanResolver`.
- Section 5 (5:00–7:00, song, unmatched) is the RMS-detected remainder of "All Praise To Him" after the classifier split it at 5:00. See finding F16.
- Section 8 (22:20–22:54, 34 seconds, song unmatched) is a brief musical bridge between the children's talk and the King of Kings song. See finding F7.
- Section 10 is classified as `notices` (harvest appeal, epap content) rather than `prayer`. The actual prayer content is section 11 (28:20–35:10).
- Section 12 (35:10–39:30) captures the Luke 23:1-25 reading ("Andre is going to come up and read…") and is selected by `ExtractSermon` as the accompanying reading. Luke 23 is the correct preached text. ✓
- `ExtractSermon` uses `non_adjacent_bible_plus_sermon_concat`, pairing section 12 (Luke 23, ~3 min before the sermon) with section 14. The correct reading is selected here only because section 3 (Nehemiah) is in `[review]` status and is filtered out. See finding F5 for why this is coincidental.
- Section 16 (82:19–83:25) contains the closing benediction ("With merciful to those who doubt, save others by snatching them from the fire" — Jude 1:22-23), classified as `bible_reading`. Correctly identified but no OoS item to align to.
- `ExtractSermon` trim segments: {35:10–39:30} + {42:37–79:02} = ~44 min total extracted.

### Scenario H — 28 December 2025

Observed on 2026-06-21 using commit `fc0777b` and classifier model `gpt-5`.

```bash
vendor/bin/sail artisan tinker --execute '$scenario="dec25"; require base_path("scripts/section-extraction/run-init.php");' 2>&1
vendor/bin/sail artisan tinker --execute '$scenario="dec25"; require base_path("scripts/section-extraction/run-step2.php");' 2>&1 | tee storage/scratch/dec25_step2.out
vendor/bin/sail artisan tinker --execute '$scenario="dec25"; $includeTranscription=true; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/dec25_downstream.out
```

Review questions:

- Are the four Christmas songs matched despite non-standard titles ("In A Stable Long Ago", "Come, adore the humble King")?
- Does the sermon extract cleanly from the simpler five-item service?
- Does the absence of a `bibles`-type OoS slide affect reading-reference resolution?

#### Step 2 segments

```text
0    0:00-0:18       18s  speech   rms=-50   vis=-
1    0:18-1:48       90s  song     rms=-30.1 vis=0.57   ← extra song not in OoS or Gemini notes
2    1:48-7:07      318s  speech   rms=-50   vis=-
3    7:07-9:49      162s  song     rms=-28.4 vis=0.55   ← "O What A Mystery I See" (Gemini: 7:16)
4    9:49-19:55     606s  speech   rms=-50   vis=-
5   19:55-23:01     186s  song     rms=-27.2 vis=0.55   ← "In A Stable Long Ago" (Gemini: 20:14)
6   23:01-33:36     635s  speech   rms=-50   vis=-
7   33:36-37:35     239s  song     rms=-26.6 vis=0.55   ← "Come, adore the humble King" (Gemini: 33:02)
8   37:35-69:32    1917s  speech   rms=-50   vis=-      ← pre-classified as sermon (conf=0.9)
9   69:32-71:27     115s  song     rms=-30.5 vis=0.54   ← "I Know That My Redeemer" (Gemini: 69:41)
10  71:27-72:16      48s  speech   rms=-50   vis=-
```

The four OoS carols match RMS segments 3, 5, 7, 9 very closely. Segment 1 (0:18–1:48) is an extra song not mentioned in the OoS or Gemini's notes; likely pre-service content. The OoS has only five items (four songs + one "Reading" custom slide, no notices or bible type items).

#### Stable expectations

| Assertion | Expected | Result |
|---|---|---|
| `church_service_id` | 713 | PASS |
| Total sections | 18 | PASS |
| Songs matched | 1 (Come, adore the humble King) | PASS |
| Songs unmatched | 5 | PASS |
| Children's talk sections | 1 (13:34–17:49) | PASS |
| Sermon section | 37:35–69:32 | PASS |
| Extraction strategy | `non_adjacent_bible_plus_sermon_concat` | PASS |
| Paired reading | Section 5 (5:48–6:48, earliest — F5) | PASS |

#### Observed section baseline

```text
1   0:00-0:18   other          match=-            item=-     conf=0.5  [review]
     (none)
2   0:18-1:48   other          match=-            item=4828  conf=0.75 [review] -> "Reading"
     (none)
3   1:48-2:58   welcome        match=-            item=-     conf=0.86 [review] align=content_anchor
     "This is a Christian sermon preached at Crockenhi..."
4   2:58-5:48   notices        match=-            item=-     conf=0.95 [ok] align=content_anchor
     "Just a few notices before we begin our service...."
5   5:48-6:48   bible_reading  match=-            item=-     conf=0.95 [ok] align=content_anchor
     "Well, I'm going to turn our attention now to the..."
6   6:48-9:49   song           match=unmatched    item=-     conf=0.3  [review] flags=unmatched_song_section
     (none)
7   9:49-13:34  prayer         match=-            item=-     conf=0.95 [ok]
     (none)
8  13:34-17:49  childrens_talk match=-            item=-     conf=0.95 [ok] align=content_anchor
     "At Christmas time, we receive Christmas presents..."
9  17:49-19:24  prayer         match=-            item=-     conf=0.95 [ok] align=content_anchor
     "Let's pray, shall we, as we think about that tog..."
10 19:24-19:55  song           match=unmatched    item=-     conf=0.7  [review] flags=unmatched_song_section ← F16 split (lead-in to "In A Stable")
     (none)
11 19:55-23:01  song           match=unmatched    item=-     conf=0.3  [review] flags=unmatched_song_section ← "In A Stable Long Ago"
     (none)
12 23:01-26:11  bible_reading  match=-            item=-     conf=0.95 [ok] align=content_anchor  ← closer reading; not selected (F5)
     "Will you please turn with me, in your Bible, to..."
13 26:11-32:21  prayer         match=-            item=-     conf=0.98 [ok] align=content_anchor
     "Well, on a Sunday evening, Gareth has been teach..."
14 32:21-33:36  song           match=confirmed    item=4826  conf=1    [ok] -> "Come, adore the humble King" [song#1142]  ← in-speech portion matched
     (none)
15 33:36-37:35  song           match=unmatched    item=-     conf=0.3  [review] flags=unmatched_song_section ← F16 split (step2-seg portion of same song)
     (none)
16 37:35-69:32  sermon         match=-            item=-     conf=0.9  [ok]  ← pre-classified from step2
     (none)
17 69:32-71:27  song           match=unmatched    item=-     conf=0.3  [review] flags=unmatched_song_section ← "I Know That My Redeemer"
     (none)
18 71:27-72:16  prayer         match=-            item=-     conf=0.95 [ok] align=content_anchor
     "And to present you before his glorious presence,..."
```

**Key observations:**
- Only 1 of 4 OoS songs matched ("Come, adore the humble King", section 14). The matched portion was detected within the preceding speech block (step2 seg 6, 23:01–33:36) because it had a transcript. The remainder (section 15, step2 song seg 7) is unmatched — **F16**.
- The same F16 split occurs at 19:24–23:01: section 10 (31s, speech-block tail) and section 11 (step2 song seg 5) together form "In A Stable Long Ago".
- Step2 segment 1 (0:18–1:48) was classified as `song` at step2 but ends up as `other` in section 2. The OoS "Reading" item (4828) is aligned to it at the start of the service, which is likely pre-service background reading — not a service song. This is a correct reclassification.
- Neither bible_reading section (5 or 12) has a `READING=` annotation. The OoS contains only a custom "Reading" item (no bibles-type item with a passage title), and transcript AI did not resolve a scripture reference from either section's text. **See finding F17.**
- Extraction selects section 5 (5:48–6:48, 60s, earliest) as the paired reading rather than section 12 (23:01–26:11, 3.2 min, closer). **F5 confirmed.**

### Scenario I — 22 March 2026

Observed on 2026-06-21 using commit `fc0777b` and classifier model `gpt-5`.

```bash
vendor/bin/sail artisan tinker --execute '$scenario="mar26"; require base_path("scripts/section-extraction/run-init.php");' 2>&1
vendor/bin/sail artisan tinker --execute '$scenario="mar26"; require base_path("scripts/section-extraction/run-step2.php");' 2>&1 | tee storage/scratch/mar26_step2.out
vendor/bin/sail artisan tinker --execute '$scenario="mar26"; $includeTranscription=true; require base_path("scripts/section-extraction/run-downstream.php");' 2>&1 | tee storage/scratch/mar26_downstream.out
```

Review questions:

- Are all five songs matched, including the two less common titles ("God Of Glory", "Purify My Heart")?
- Is the Isaiah passage identified and its reference resolved from the `Isaiah.pptx` OoS slide or the transcript?
- Does the two-image header (`Notices2026Looped.pptx` + `Slide1.JPG`) produce any alignment artefact in the opening block?

#### Step 2 segments

```text
0    0:00-0:01        1s  speech   rms=-50   vis=-   ← micro-section (see F7)
1    0:01-3:24      202s  song     rms=-28   vis=0.56  ← two OoS songs merged (God Of Glory + O God Beyond)
2    3:24-14:03     639s  speech   rms=-50   vis=-
3   14:03-16:38     156s  song     rms=-30.1 vis=0.56  ← "How Deep The Father's Love" (Gemini: 13:46)
4   16:38-32:35     956s  speech   rms=-50   vis=-
5   32:35-34:54     140s  song     rms=-29.6 vis=0.53  ← "Purify My Heart" (Gemini: 32:09)
6   34:54-65:43    1849s  speech   rms=-50   vis=-      ← pre-classified as sermon (conf=0.9)
7   65:43-68:28     165s  song     rms=-28.4 vis=0.54  ← "There Is A Hope" (Gemini: 65:06)
8   68:28-69:17      49s  speech   rms=-50   vis=-
```

OoS items for service 735:

| ID | Pos | Type | Title | Song |
|---|---|---|---|---|
| 4956 | 1 | presentations | Notices2026Looped.pptx | — |
| 4957 | 2 | images | Slide1.JPG | — |
| 4958 | 3 | songs | God Of Glory #244 | song#295 |
| 4959 | 4 | songs | O God Beyond All Praising #187 | song#688 |
| 4960 | 5 | presentations | Isaiah.pptx | — |
| 4961 | 6 | songs | How Deep The Father's Love For Us #426 | song#375 |
| 4962 | 7 | custom | Reading | — |
| 4963 | 8 | songs | Purify My Heart #814 | song#804 |
| 4964 | 9 | songs | There Is A Hope | song#935 |

Only 4 RMS song segments were detected from 5 OoS songs. The two opening worship songs ("God Of Glory" and "O God Beyond All Praising") are merged into one continuous 3-minute segment (0:01–3:24) with no visual break (vis=0.56 throughout). Since this segment is step2-classified as `song`, `ClassifySpeechSections` will not process it, and the two songs cannot be split from each other or matched individually.

The "Reading" item (pos=7, custom type) has no bibles-type passage title. Same pattern as H — reading reference resolution will depend on transcript AI alone. See F17.

The 1-second micro-section at 0:00–0:01 is consistent with finding F7. The pre-classified sermon (34:54–65:43, 30.8 min) matches Gemini's timing (34:47–64:52) very closely.

#### Stable expectations

| Check | Expected |
|---|---|
| Status | `processing` |
| Current step | `extraction_complete` |
| Church service | `735` |
| Section count | 13 |
| Confirmed songs | 4 |
| Inferred songs | 1 ("O God Beyond All Praising" — false positive, see notes) |
| Children's talks | 1 (section 4, Isaiah presentation absorbed into children's slot) |
| Main sermon | 34:54–65:43 |
| Extraction strategy | `sermon_only` (bible_reading in review status excluded) |
| Main `Sermon` record | Not created by this harness |

#### Observed section baseline

```text
1   0:00-0:01   other          match=-            item=-     conf=0.5  [ok]           ← F7 micro-section
     (none)
2   0:01-3:24   song           match=confirmed    item=4958  conf=0.9  [ok]     -> "God Of Glory #244" [song#295]
     (none)
3   3:24-6:24   prayer         match=-            item=-     conf=0.95 [ok]     align=content_anchor
     "Our loving God and Heavenly Father, it is good t..."
4   6:24-13:44  childrens_talk match=-            item=-     conf=0.95 [ok]     align=content_anchor  ← children's talk + Isaiah intro merged
     "Can we have the first slide, please, Gareth, tha..."
5  13:44-16:38  song           match=confirmed    item=4961  conf=0.9  [ok]     -> "How Deep The Father's Love For..." [song#375]
     (none)
6  16:38-21:38  notices        match=-            item=4956  conf=1    [ok]     align=content_anchor -> "Notices2026Looped.pptx"  ← notices at 16 min, not at start
     "Well yesterday was a prison and prison resourcin..."
7  21:38-27:18  prayer         match=-            item=-     conf=0.75 [review] align=content_anchor flags=oos_structure_mismatch
     "Let's pray, shall we? Our heavenly Father, we th..."
8  27:18-31:38  bible_reading  match=-            item=-     conf=0.79 [review] align=content_anchor flags=oos_structure_mismatch  ← F17; [review] → excluded from extraction
     "Our Bible reading today, as you can see on the b..."
9  31:38-32:35  song           match=inferred     item=4959  conf=0.74 [review] align=content_anchor -> "O God Beyond All Praising #187" [song#688] flags=song_alignment_inferred,unmatched_song_section  ← F18 false positive
     "Our next song picks up one of the themes in our..."
10 32:35-34:54  song           match=confirmed    item=4963  conf=0.9  [ok]     -> "Purify My Heart #814" [song#804]
     (none)
11 34:54-65:43  sermon         match=-            item=-     conf=0.9  [ok]           ← pre-classified from step2
     (none)
12 65:43-68:28  song           match=confirmed    item=4964  conf=0.9  [ok]     -> "There Is A Hope" [song#935]
     (none)
13 68:28-69:17  prayer         match=-            item=-     conf=0.78 [review] align=content_anchor flags=oos_structure_mismatch
     "Our Heavenly Father, we thank You for Your Word..."
```

Notes:

- Review question (1): 4 of 5 OoS songs are confirmed. "O God Beyond All Praising" (song#688) is only `inferred` at section 9 (31:38–32:35), which is the preacher's spoken introduction to "Purify My Heart" — not the song itself. See finding F18.
- Review question (2): No scripture reference was resolved for section 8 (bible_reading, 27:18–31:38). The OoS "Reading" item (4962, custom type, no passage title) was not aligned to section 8 (`oos_structure_mismatch`), and transcript AI produced no `READING=` annotation. See finding F17.
- Review question (3): Two OoS header items are present: `Notices2026Looped.pptx` (item 4956, pos=1) and `Slide1.JPG` (item 4957, pos=2). The notices slide was correctly aligned to section 6 (16:38–21:38) by content anchor despite its early OoS position — it was shown mid-service, not at the start. `Slide1.JPG` was not aligned to any section. See finding F15.
- Section 4 (6:24–13:44, 7.3 min, children's talk) spans both the children's talk (~7:24–9:02 per Gemini) and the Isaiah intro (~9:02–13:46). ClassifySpeechSections merged them into one section under the children's talk label. The `Isaiah.pptx` OoS item (4960) was not aligned. See finding F15.
- Section 6 (notices at 16:38) demonstrates that `AlignWithOos` places slides based on content-anchor timing, not OoS positional order. The notices happened mid-service in this recording despite being item 1 in the OoS.
- `ExtractSermon` used `sermon_only` rather than `non_adjacent_bible_plus_sermon_concat` because the only `bible_reading` section (section 8) is in `[review]` status (due to `oos_structure_mismatch`) and is excluded from `findPreferredSection()`. This prevented a bad pairing, but also means no reading is included in the extraction. See F5 note: the usual F5 error did not fire here because the uncertain reading was correctly quarantined.

## Known issues

See `docs/archived-plans/section-extraction-findings-2026-06-20.md` for issues discovered from these fixtures (all since fixed or consciously parked). Interpret any publication-related finding in light of this harness’s deliberate stopping point.
