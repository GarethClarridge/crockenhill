# Cut What Was Identified, In Sync

**Date:** 2026-10-02 · **Status:** PROPOSED, awaiting the operator's rulings (§5) · **Blocks:** canary 10
acceptance and Tier A ([main plan §4.0](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md))

## 1. Why

Canary 10's cuts met the predeclared bar as written. The operator then ruled on two findings:

- **Any drift between sound and picture is a defect.** One file being unnoticeable proves nothing
  about the next.
- **A cut is the video for what was identified.** Detection has already found the talk or song.
  A second set of rules at cut time, deciding the times all over again, should not exist.

This plan traces both findings to their causes (§2, §3), proposes one design (§4) and lists the
rulings it needs (§5). Everything here was measured read-only. Evidence is in
`storage/app/private/canary10-20261002/` (`cut-avscan.json`, `cut-gapscan.json`) and
`storage/app/private/cut-rule-census-20261002/census.json`. Scripts are
`storage/scratch/canary10-avscan-20261002.py`, `canary10-srcwindow-20261002.py` and
`cut-rule-census-20261002.php`.

## 2. Sound and picture: four causes, all in our code

A scan of all 73 canary 10 outputs, comparing every audio and video timestamp, found 54 clean.
Every remaining defect traces to one of four causes, and none is in the recordings. The sources
checked (1221, 1108 and 936) are regular where the cuts are not.

| # | Cause | Where it shows | Effect |
|---|---|---|---|
| A1 | **`loudnorm` leaves a hole in the sound's timestamps.** Song videos get a second audio pass (`AudioEnhancementService::enhanceVideo`: noise reduction, `dynaudnorm`, `loudnorm`). When `loudnorm` flushes its 3 s look-ahead, it skips 9–88 ms. | 9 of 21 song videos, always 2.9–3.0 s before the end | The last 3 s of sound runs up to 88 ms late, or a gap is played, depending on the player |
| A2 | **Each span's sound is cut separately from its picture,** to a slightly different length. Two-span sermons (reading, then sermon) glue the spans by picture length. | 9 of 16 sermons, at the reading/sermon join (8–42 ms jump) | A gap or overlap in sound at the join. Whether the rest stays in sync depends on the player |
| A3 | **A smart cut's re-encoded piece starts half a frame late.** The piece seeks half a frame early (so as not to miss the first frame) and encodes on the source's clock, so its first frame is at +17 ms. The join places pieces by declared length and assumes each starts at 0. Whether the offset cancels depends on the encoder's frame reordering. | 1 of 29 smart cuts (936, 59.25 s); reproduced with the live cutter | Picture runs 16 ms behind sound for the rest of the sermon |
| A4 | **Old cuts are reused.** A section's media signature is its type, start and end only (`ServiceSection::mediaSignaturePayload`), not the cutter that made it. A re-run keeps any cut whose span did not change. | 2 of 57 clips in canary 10, both cut 09-27 before the smart-cut fix `9a2e688f1`, both defective. Corpus: 1,244 of 1,314 section clips, 460 of 480 song videos and 440 of 456 sermons predate that fix | Defects fixed in the cutter survive the re-run |

**Why our checks missed them.** `VideoExtractionService::cutIsAligned` compares only where sound
and picture start and how long they run. A shift in the middle passes, and so does anything
added after the cut (A1). The canary's gap scan looked only at picture.

Two things are not defects: the −23 ms first audio packet (encoder pre-roll, hidden by the
container's edit list), and 1050's last frame (it is in the recording).

`loudnorm` is reproduced and fixed in isolation. Adding `asetpts=N/SR/TB` after it gives
continuous sound with the same 8,271 frames and the source's duration (192.052 s against
192.049 s). `aresample=async=1` does the same.

## 3. Cut rules: what the sermon cut does today

Census: the current plan (`SermonExtractionPlanResolver::resolve`, in a rolled-back
transaction) for all 475 runs with a sermon section, 447 of them historic. Every planned second
is classified by what it contains. The local database holds pre-re-run sections, so the counts
describe the rules applied to today's sections, not the outcome of the re-run.

| Route | Runs | What the cut contains beyond the sermon section |
|---|---|---|
| Sections: sermon plus the chosen reading (glued if not adjacent) | 311 | The reading, median 150 s; one run takes 19 s of a song |
| Sections: sermon only | 47 | Nothing beyond the sermon (median 3 s) |
| *All section routes:* sermon end moved "to the next song" | 211 of 358 | Median 5 s, up to 452 s, including time no section covers |
| Old detector, sermon section content-held | 71 | Not cut; the run parks for review (correct) |
| **Old detector, sermon section rejected** | **46** | Median 344 s extra; **26 contain songs (5,271 s)**, 32 contain other readings |

**Why 44 sermon sections were rejected:** 34 for `transcript_repetition_suspect`, a flag about
the *transcript text*; 4 for `structure_sermon_interruption_merged` (canary 10's 1050, 1250 and
1356); and 6 for low confidence or an OoS mismatch. A text flag therefore swaps in a different
detector's times, and those cuts include songs. A further two were rejected as longer than
45 minutes.

The other clips (songs, readings, talks) are already cut at their section's own times
(`PrepareSectionPublicationCandidates`). Only the sermon carries the extra rules.

## 4. Proposed design

### Cut rules

- **C1. A sermon video is a list of sections.** The structure names the sections that make the
  sermon video (§5 D1), and they are visible on review pages. The cut takes exactly those
  sections' times. Nothing outside a section is cut, and nothing extends by time arithmetic. If
  the closing prayer belongs in the video, it must be a section; finding it is detection's job,
  and its absence is an ensemble question.
- **C2. Untrusted sections hold; they are never replaced.** If a section cannot be cut, the run
  parks for review, as content-held sermons already do. Delete the old-detector route from the
  cut (`baselinePlan` and the `sermon_start_time`/`sermon_end_time` it reads), along with the
  confidence threshold, the 45-minute ceiling and flag-based rejection. A flag asks for review;
  it never chooses a different cut. Text flags go to text repair.
- **C3. Song, reading and talk clips are unchanged.**

### Sync

- **S1. Cut sound and picture as one.** Cut every output in one FFmpeg pass that trims each span's
  picture and sound together (`trim`/`atrim`, then `concat=v=1:a=1`) and encodes once. Joins are
  then exact by construction. This removes the smart cut (`smartCutPlan`, `writeSmartCut`, the
  transport-stream join, separate sound mux), which A2 and A3 come from. In canary 10, 69 of 98
  cuts were already full re-encodes (source above 6 Mbps or VP9). **Measure the cost first**:
  re-encode the 29 smart-cut spans and time them. A rough guess from canary 10 timings is 10–15%
  more Tier C time; the measurement decides.
- **S2. Fix `loudnorm`:** `asetpts=N/SR/TB` after the filter chain (A1), test first.
- **S3. Check sync on every output, whole-file.** After every cut and every audio pass, sound and
  picture must start together, end together within one audio frame, and have no timestamp step
  beyond tolerance anywhere. A failure is never accepted. This replaces `cutIsAligned`, runs in
  weekly processing as well as historic, and joins the canary bar.
- **S4. A cut records the cutter that made it.** Add the code revision of the cutting code to the
  media signature, so a changed cutter means a re-cut. For the re-run, Tier C re-cuts every
  output.

## 5. Rulings needed

- **D1. What a sermon video contains.** (a) The sermon only. (b) The preached reading and the
  sermon. (c) The reading, the sermon and a directly following closing prayer. Today's behaviour
  is closest to (c), done by time arithmetic. With C1 each part is a named section.
- **D2. When the sections cannot be trusted, hold.** No cut, review instead (C2). Confirm.
- **D3. Smart cut or one-pass re-encode,** decided by the S1 measurement. Recommendation: one pass
  unless it costs more than about 15% of Tier C. It deletes the most fragile code.
- **D4. Already-published weekly media.** A1–A3 apply to weekly uploads too. Production is a
  separate machine, so propose a read-only sync scan of published media there, then re-cut what
  fails.

## 6. Order

1. Rulings D1–D4.
2. Build test-first, failing test first for each: S3 (makes every defect visible), S2, S1, S4,
   C2, C1. Build them with the 1112 matcher fix already required before batch 1. One commit
   series, then the gates (full suite, Dusk, PHPStan, Pint).
3. Replay the census with C1/C2 and the whole-file sync check over canary 10's existing outputs,
   to confirm that every defect found here is caught.
4. **Canary 10 again** on the same 16 runs: fresh snapshot, saved draws, Tier C. Add to the bar:
   every output passes S3, and no sermon is cut from anything but its named sections.
5. Then Tier A.
