# Children's-talk speaker proposals — decision record, 3 September 2026

**Status:** decided and implemented 2026-09-03. See §7 for what landed. This is
item 3 of §7 of
[`HISTORIC-REVIEW-QUEUE-CAUSES-2026-09-03.md`](HISTORIC-REVIEW-QUEUE-CAUSES-2026-09-03.md),
the last of the five and the only one not yet actioned. It follows the shape of
[`HISTORIC-IMPORT-DECISIONS-2026-09-03.md`](HISTORIC-IMPORT-DECISIONS-2026-09-03.md)
and defers to D1 there for the propose-and-confirm pattern.

Working tree clean at `180e22e72`. Queue workers restarted before this survey so
the measurements below reflect post-merge code.

---

## 1. Corrections to the survey

**§4's central claim is wrong.** It recorded this class as *"confirmed
irreducible — no speaker in the OoS item, its metadata, or the service … only the
recording holds it."* Every clause of that is true and the conclusion still does
not follow, because **the recording is exactly what the speaker model reads**,
and it had already read all twelve.

The flagged sections are not sections where nothing is known. They are sections
where the model **ran, scored, ranked the candidates, and was overruled by the
margin gate**:

| outcome | count | what it means |
|---|---|---|
| `ambiguous` | 12 | model ran; top score ≥ accept threshold; margin < 0.10 |
| `no_profiles` | 2 | ran 2026-07-09/10, before profiles were imported — stale |

**The propose-and-confirm machinery already exists.** §4 proposed building it
("*can the same voice-matching supply a proposal here — detector proposes,
operator confirms, exactly as D1 did*"). It is already built, in
`app/Services/Preacher/ChildrensTalkSpeakerService.php`:

- `detectAndStore()` writes a `predicted` payload to
  `metadata.childrens_talk_speaker.predicted` on every children's-talk section;
- a `matched` outcome is auto-accepted into `.reviewed` with
  `review_mode: auto_accepted`;
- `storeManualReview()` records an operator's answer, by `Preacher` id or free
  text, and clears the flag.

So the work is not "build propose-and-confirm". It is much narrower — see §3.

**The two `no_profiles` rows are stale and nothing will ever clear them.**
`services:rederive-structure-review-flags` re-weighs stored *structure* flags; it
never re-runs `detectAndStore`. §380 and §413 have been holding a flag since July
for a condition (no profiles) that stopped being true the same month.

---

## 2. The actual gap: the shortlist is computed, then discarded

`ResemblyzerSpeakerIdentificationService` builds a **named, ranked shortlist** and
attaches it to the result on *both* paths
(`ResemblyzerSpeakerIdentificationService.php:169,177,190`):

```php
$candidates = SpeakerMatchResult::namedCandidates($profiles, $scores);
```

`SpeakerMatchResult::namedCandidates()` exists for precisely this case, and says
so in its own docblock:

> Historic runs fall back to "Visiting Speaker" often enough that whoever reviews
> the fallback needs to see **who the model was choosing between and by how
> much**, so the names travel with the decision.

`ChildrensTalkSpeakerService::predictionPayload()` then **drops it**. On the
no-match branch it merges only `confidence`, `second_confidence`, `margin` and
`source`; `preacher_id` and `preacher_name` stay `null` from `basePrediction()`,
and `candidates` is never carried at all. The names are in memory one statement
before storage and are thrown away.

**The sermon path does not have this bug.** `IdentifySpeaker::storeDecision()`
persists `$result->toLogArray()`, and `toLogArray()` carries `candidates`. So the
same detector, on the same day, writes a named shortlist for a sermon and writes
nothing for a children's talk:

```
run 929  (sermon)          candidates: Mark Drury 0.749, Laurie Everest 0.701,
                                       Gareth Clarridge 0.643
§1088    (children's talk) keys: margin, reason, source, outcome, confidence,
                                 decided_at, preacher_id, preacher_name,
                                 second_confidence, matched_profile_id
```

`candidates` was added on **2026-08-29** (`85f9a56a2`); 29 scored sermon runs
have it and 18 older ones do not. **No** children's-talk section has it, at any
date, because `predictionPayload()` hand-builds its payload instead of reusing
the shared one.

So the defect is not "children's talks are hard". It is **one path hand-rolling a
payload that already exists**. The fix carries the missing key into that payload
rather than switching it wholesale to `toLogArray()`'s shape: the two use
different key names (`confidence` against `top_score`, `preacher_name` against
`matched_preacher_name`) and the children's-talk one is read by the review UI, so
a rename would be a migration, not a bug fix. Nothing is invented either way.

**This is a real defect regardless of which decisions below are taken**, and it
is the cheapest thing in the queue: carry a value that is already computed.

---

## 3. The evidence

### 3.1 The detector works — base rate over 29 live sections

| | count |
|---|---|
| `matched` → auto-accepted | 5 |
| `ambiguous` → flagged | 12 |
| `no_profiles` → flagged (stale) | 2 |
| no prediction stored | 10 |
| **live `childrens_talk` sections** | **29** |

Of the 19 sections that got a real prediction, **5 (26%) auto-resolved with no
human involvement**. None of the five has since been contradicted — but none has
been independently confirmed either, so they are evidence that the detector
*fires*, not yet that it is *right*. The single `manual_override` on record
(§321) had no prediction stored to agree or disagree with.

### 3.2 Margin is the only discriminator, and the two groups nearly touch

Auto-accepted (`matched`):

| section | top | margin | who |
|---|---|---|---|
| §782 | 0.8432 | **0.10301** | Laurie Everest |
| §891 | 0.8285 | **0.10612** | Mark Drury |
| §509 | 0.9657 | 0.13398 | Mark Drury |
| §809 | 0.9803 | 0.14214 | Mark Drury |
| §632 | 0.9750 | 0.16673 | Mark Drury |

Flagged (`ambiguous`), sorted by margin:

| section | top | second | margin |
|---|---|---|---|
| §990 | 0.85360 | 0.84439 | 0.00921 |
| §874 | 0.79113 | 0.75617 | 0.03496 |
| §1047 | 0.80149 | 0.75592 | 0.04557 |
| §939 | 0.80719 | 0.75190 | 0.05529 |
| §703 | 0.82768 | 0.76795 | 0.05972 |
| §842 | 0.82380 | 0.75861 | 0.06519 |
| §1061 | 0.79000 | 0.71069 | 0.07932 |
| §946 | 0.82312 | 0.74001 | 0.08311 |
| §671 | 0.81601 | 0.73191 | 0.08410 |
| §1088 | 0.83835 | 0.75089 | 0.08746 |
| §974 | 0.91190 | 0.82208 | 0.08982 |
| §733 | 0.79756 | 0.70559 | 0.09196 |

**The absolute score carries no information here.** The 2026-07-25 calibration
recorded cross-speaker cosine similarity of **0.78–0.90 between different
people** — the shared room and microphone dominate the embedding. Every top score
above sits inside that band, including §974's 0.91. Only the margin discriminates,
which is why the gate is built on it.

**But the 12 are not homogeneous.** Six of them (§1061 … §733) sit at
**0.079–0.092**, within 0.021 of the accept threshold, while the auto-accepts
begin at 0.103. The known-wrong cases in the hold-out eval had margins of
**0.010, 0.005 and 0.000** — an order of magnitude smaller. §990 (0.00921) is the
only flagged row squarely in that regime.

**The 0.08–0.10 band is unmeasured.** The eval says nothing about it either way.
That is the single fact that decides how far this can be automated, and it is
cheap to measure (§5).

### 3.3 Profile coverage is thin, and correctly so

Four profiles are eligible (`configuredForSpeakerIdentification`):

| id | preacher | centroid | stored `sample_count` |
|---|---|---|---|
| #2 | Mark Drury | real, norm 0.959 | 10 |
| #4 | Laurie Everest | real, norm 1.000 | 1 |
| #5 | Peter Clarridge | real, norm 1.000 | 1 |
| #14 | Gareth Clarridge | real, norm 1.000 | 1 |

Ten further profiles (Bryan Martin, Steve Marchant, John Pilling, Keith Milne,
Alan Greenbank, Brian West, David Williams, John Carrick, Malcolm Jones, Phil
Endersby) are `is_active = 0`. That is **deliberate and correct** — they are the
2003–2013 old-era set, and all fourteen flagged sections are **2020–2026**.

Two hypotheses were tested and are false, and are recorded so nobody re-runs them:

- *"An untrained zero-vector profile is depressing the margins."* No. All four
  eligible centroids are genuine and unit-normalised. #14's `samples()->count()`
  of 0 is expected — `speaker-profiles:import` deliberately carries centroids
  without `speaker_samples`.
- *"Identification ran against the wrong era's profiles."* No. The flagged
  sections are 2020–2026 and the active pool is the current-era set.

The genuine coverage question is different and unresolved: **children's talks are
not always given by preachers**, and the active pool holds four people. If a
talk's speaker has no profile at all, no threshold change can find them — the
correct answer is "not in the catalogue", and the model can only say so by
ranking everyone low. Nothing currently distinguishes *"the model could not
choose between two known people"* from *"the speaker is nobody the model knows"*.

### 3.4 The sermon side is not doing better — it is mostly not being asked

The obvious challenge to all of this is: *speaker identification works reliably
for sermons, so why not here?* Measured over live runs, **it does not**.

| sermon-side outcome | count |
|---|---|
| `skipped_id3` — preacher taken from the file's ID3 tag | **818** |
| `no_match` | 34 |
| `skipped_no_profiles` | 17 |
| `matched` | 13 |

Among runs that actually reached the detector, the match rate is **13 / 47 =
28%**. The children's-talk rate is **5 / 19 = 26%**. Within noise, they are the
same number, because it is the same detector, the same thresholds and the same
`identify()` call.

**The reliability is supplied by ID3 tags, not by the model.** 818 of 882 live
runs — 93% — never invoke identification at all, because the preacher is already
in the file metadata. Speaker ID is a backstop that is rarely consulted and
declines roughly three-quarters of what it is handed. Historic-archive recordings
are untagged mp4s, so that shortcut does not exist for them; and a children's
talk never had an ID3 preacher in the first place.

The sermon-side `no_match` margins span the same band as the children's-talk
`ambiguous` ones (0.002–0.099 against 0.009–0.092). This is one detector with one
behaviour, seen through two surfaces.

---

---

## 4. The three decisions

### Q1 — Can the model propose, and are there enough profiles?

It can already propose, and does: 5 of 19 predictions auto-resolved. What it
**cannot** currently do is propose in the ambiguous band, because the margin gate
that rejects those cases is load-bearing and the 2026-07-25 eval explicitly says
**do not lower it**.

So the honest answer is: *the model can produce a **ranked shortlist** for all 12,
but the evidence does not yet support a **single trusted proposal** in that band.*
D1's "one key confirms it" shape does not import unchanged.

**Options:**

- **(a) Surface the shortlist, do not rank-1 propose.** Carry `candidates` into
  `predicted`; the review UI shows "the model was choosing between Mark Drury
  (0.83) and Laurie Everest (0.75)" with a button per name plus *someone else*.
  Turns a blank question into a two-click pick. No calibration risk — nothing is
  auto-accepted that is not already.
- **(b) Measure the 0.08–0.10 band first, then decide.** Do (a), then measure
  rank-1 accuracy in that band. **The sample is much larger than the 12** — the
  sermon side has **34 `no_match` runs across the same margin range, 29 of them
  with shortlists already stored** (§3.4). Those need no audio re-cut at all;
  they need the true preacher confirmed once each. Combined with the 12 re-cut
  children's-talk sections that is a real calibration set, on one detector. If
  rank-1 proves reliable above ~0.08, a *second* decision can introduce a
  proposal tier for both surfaces.
- **(c) Lower the margin threshold to auto-accept the upper band.** Rejected —
  contradicts the standing calibration finding, and would silently write wrong
  speakers into published sermons.

**Recommendation: (a) now, (b) as the follow-on.** (a) is a bug fix on already
computed data and is worth doing whatever the eval later shows; (b) is what
converts a shortlist into a proposal, and it should not be guessed at.

### Q2 — Where does a proposal live, and what does confirming it write?

Largely already answered by the existing shape, which should not be redesigned:

- proposal lives at `metadata.childrens_talk_speaker.predicted`, gaining a
  `candidates` array of `{preacher_id, preacher_name, score}`;
- confirmation is written by `storeManualReview()` into
  `metadata.childrens_talk_speaker.reviewed`, which already clears
  `review_reason`, removes the flag and drops `needs_manual_review`.

**The one open sub-question:** should confirming a shown candidate record a
distinct `review_mode` — say `proposal_confirmed` — rather than reusing
`manual_override`? It costs nothing and it is the only way to later measure how
often the operator took the model's top candidate, which is the evidence Q1(b)
needs. **Recommendation: yes, add the mode.**

Per the standing project constraint, one person confirms. No witness or
second-operator gate.

### Q3 — What happens when no profile matches?

The survey's own rule applies: *review earns its cost when the pipeline made a
choice, not when it noticed a fact.* By that rule the five outcomes split:

| outcome | is it a decision the pipeline failed to make? | disposition |
|---|---|---|
| `ambiguous` | yes — it had candidates and could not choose | **stays a review flag** |
| `no_match` | yes — everyone scored low, "unknown speaker" is a finding | **stays a review flag** |
| `missing_audio` | no — an input was absent | **disposition, not review** |
| `short_audio` | no — below the 30 s floor, a fact about the item | **disposition, not review** |
| `no_profiles` | no — the system was unconfigured | **disposition, not review** |

This mirrors the `audio_only_song_segment` decision taken earlier the same day:
song audio with no transcribed lyrics is not an unreviewed decision, it is a
recorded fact, and it was given its own disposition rather than a review flag.

Note `detectAndStore()` **already half-implements this** — it removes the flag
when `$profiles->isEmpty()` — but the two live `no_profiles` rows predate profiles
existing and nothing re-runs to clear them.

**Recommendation: give the three input-fact outcomes a disposition, and add a
narrow re-run path** so a stale `no_profiles` row can be re-derived rather than
sitting forever. The 12 `ambiguous` and any `no_match` stay in the queue, because
those are genuinely open questions.

---

## 5. Feasibility — what a backfill would cost

**Section audio is gone.** All 12 `extracted_audio_path` values point at
`section-publications/…` and **0 of 12 exist on any configured disk**.

**Full-service audio survives**, on `historic_quarantine`
(`/mnt/historic-work/quarantine/sermons/audio/`), for **12 of 14** runs — roughly
10 MB each, alongside the source video. The two exceptions are runs **913 and
916**, backing the stale `no_profiles` rows §380 and §413, which have nothing
left on any disk.

So re-running identification does **not** need a re-download or a restage. Each
section's audio can be re-cut from the retained full-service audio with an ffmpeg
seek at the section's stored offsets, then passed to the existing
`identify()`. That makes Q1(b) cheap — twelve short extractions and twelve
embedding runs — and it is the only way to answer the 0.08–0.10 question with
data rather than intuition.

Disk headroom is not a constraint here: 384 GB free on `/mnt/historic-work`.

---

## 6. Deliberately not decided

- **Adding speaker profiles for children's-talk givers.** The pool of four is
  thin for 2020–2026, but enrolling new speakers needs labelled samples, which is
  operator work and a separate decision. Q1(b)'s measurement should come first —
  it will show whether the ambiguous cases are "two known people" or "nobody the
  model knows", and those want different answers.
- **Whether `needs_preacher_review` on the sermon side should follow the same
  split.** Same argument, different surface; out of scope here.
- **Anything about the margin threshold's value.** It stays at 0.10.

---

## 7. What was implemented, 2026-09-03

All three decisions landed together. Four gates green: `pint --dirty`,
`composer phpstan` (0 errors, 914 files), `artisan test --parallel` (7765 passing)
and `artisan dusk` (55 passing).

### Q1(a) — the shortlist is carried, not discarded

`ChildrensTalkSpeakerService::predictionPayload()` now passes `$result->candidates`
through on both the matched and no-match branches, and `basePrediction()` defaults
it to `[]`. A section the model cannot resolve now stores the names and scores it
was choosing between.

### Q2 — confirmations record which candidate was taken

`storeManualReview()` writes `review_mode: proposal_confirmed` plus a 1-based
`proposal_rank` when the confirmed preacher was among the offered candidates, and
`manual_override` with a null rank when they were not. Rank rather than a boolean,
so *"how often was our top candidate right?"* becomes a query over stored reviews
instead of a fresh eval — which is exactly the evidence Q1(b) needs.

### Q3 — the input-fact outcomes are dispositioned

`REVIEW_OPENING_OUTCOMES` is now `['ambiguous', 'no_match', 'error']`. Everything
else — `missing_audio`, `short_audio`, `no_profiles`, `skipped` — withdraws the
flag and lets the stored prediction carry the reason. Previously *any* eligible
profile sent every non-matching outcome down the review branch, so a 12-second
talk opened a question that its own audio was too short to answer.

`error` is deliberately kept in the review set. It is not a decision the pipeline
made, but dispositioning a failed extraction would make the failure invisible.

### A defect found while implementing

`predictionPayload()` tested only that `extracted_audio_path` was a non-empty
*string*, never that the file existed. Section audio is routinely reaped after
publication, so a reaped section spawned a Python subprocess that failed and
surfaced as `error` — which, under the new rules, would have opened a review
nobody could answer. It now checks the media disk first and reports
`missing_audio`. The extractor resolves exactly one disk
(`$disk ?? MediaAssetPath::disk()`, no fallback), so the check is equivalent to
the absence the subprocess would have hit.

### The stale rows, and the guard that protects the live ones

`services:redetect-childrens-talk-speakers` re-asks the question, dry-run by
default. **Its scope is the safety property, not a convenience:** only sections
whose stored outcome is `no_profiles`, `skipped`, `short_audio` or `missing_audio`
— or which never got a prediction — are re-asked. A row holding `ambiguous` was
answered by the model against audio since reaped; re-running it would resolve to
`missing_audio` and silently retire a genuine open question. That is the
laundering shape the unscoped song-match recompute nearly shipped the same day,
and it is covered by a test that asserts a scored row is left alone.

Executed against the live corpus:

| transition | sections |
|---|---|
| `no_profiles` → `missing_audio`, flag withdrawn | 2 (§380, §413) |
| no prediction → `missing_audio`, flag unchanged | 9 |

**`childrens_talk_speaker_review` fell 14 → 12; the live review queue 38 → 36.**
The 12 remaining are the scored `ambiguous` rows §4 decided to keep.

### Also landed on this branch — profiles are built from sermons only

`BootstrapSpeakerProfilesCommand::candidateSermons()` filtered on neither
`content_type` nor anything equivalent, and `sermons` is polymorphic: a children's
talk is a real `Sermon` row with its own audio and preacher. Nothing was
contaminated, but only incidentally — the three children's-talk rows that exist
have no audio, so `whereNotNull('audio_file_path')` was the sole thing excluding
them, and that expires the moment Phase 8 publishes one. `orderByDesc('date')`
takes the newest records, so a newly published children's talk would have gone
straight to the front of the sampling queue for the ~33% of 414 identities that
contain one.

A profile wants one person talking uninterrupted. A sermon is ~36 minutes
(median 2172 s) of that; a children's talk is 2–8 minutes of call and response.
Only the first `extraction_duration` (60 s) is embedded, so it is the purity of
that opening minute that matters, and a children's talk's opening minute is the
part most likely to hold other voices.

**This does not argue for separate children's-talk profiles — there are none.**
`ChildrensTalkSpeakerService::eligibleProfiles()` calls the same
`configuredForSpeakerIdentification()` the sermon path uses. Same people, same
profiles, same thresholds. What is separate is the *code path*, and that
separation is what dropped the shortlist in the first place.

### Still owed

Q1(b) — the calibration measurement — is **not** done. It is now cheaper than §5
described: the sermon side already stores 29 shortlists across 34 `no_match` runs
in the same margin band, needing no audio re-cut at all (§3.4). Only the true
preacher per run is missing, and that is operator work.

**Re-frame it by era before running it.** Measured 2026-09-03 on the stored
centroids, acoustic era dominates the embedding more than speaker identity does —
different people from the same era average 0.8796 similarity, while the same
population across eras averages 0.7462, and top score tracks service year at
**r = 0.839**. The twelve ambiguous sections are 2020–2023, the era with no
profile coverage at all (2003–2013 has ten deactivated profiles, 2025–26 has four,
2013–2025 has none). An eval that ignores era will mis-attribute era mismatch to
model error. Detail: `speaker-embeddings-encode-acoustic-era` in memory.

---

## 8. The executed run needs re-verifying — the media drive dropped mid-session

**The code is sound and fully tested. The one live execution is not yet trustworthy.**

The CBC drive (`/dev/disk5s1`, exFAT, holding `/mnt/historic-work` and therefore
`historic_staging` — the disk `MediaAssetPath::disk()` resolves to) dropped at
**19:46 BST** and macOS remounted it as `/Volumes/Sonnics 1`, while `.env` points
`CBC_HISTORIC_WORK_PATH` at `/Volumes/Sonnics`. Every container lost the bind,
including the long-running `laravel.test`.

`services:redetect-childrens-talk-speakers --execute` ran at **19:56 BST** — ten
minutes *after* the disk became unreachable. So its verdicts were measured against
a disk that was not there, and **every `missing_audio` it wrote is unverified**:

| what it wrote | confidence |
|---|---|
| §380, §413 `no_profiles` → `missing_audio`, flag withdrawn | **probably right, unverified** — earlier in the same session, *while the drive was mounted*, runs 913 and 916 showed `source_file_path`, `audio_file_path`, `enhanced_audio_file_path` and `video_file_path` all absent from every configured disk. Their section audio being gone too is near-certain but was not directly checked. |
| 9 sections, no prediction → `missing_audio`, no flag change | **unverified** — if their audio exists, the honest outcome is a real identification, not a disposition. No review flag moved either way, so nothing entered or left the queue on this. |

**The 12 `ambiguous` rows were never in scope and were not touched**, so the live
queue count of 36 is unaffected by the fault.

### Recovery, once the drive is back

The command's own scope makes this self-healing: `missing_audio` is a re-askable
outcome, so re-running re-asks exactly the rows the fault touched and nothing else.

```
docker compose up -d                                   # workers cannot start without the mount
vendor/bin/sail artisan services:redetect-childrens-talk-speakers          # dry run first
vendor/bin/sail artisan services:redetect-childrens-talk-speakers --execute
```

Expect 11 sections in scope. If the audio really is gone, the dry run reports no
changes and the earlier result stands. If any of it is present, those sections get
a real verdict and may legitimately re-raise `childrens_talk_speaker_review`.

### The guard that stops this recurring

The fault is now closed in code. `predictionPayload()` asks whether the disk is
*reachable* before asking about the file: for a local-driver disk the configured
root is the mount point, so a root that is not a directory means the volume is
detached and every `exists()` against it is a false negative rather than a fact
about any one file. That returns `error` — a review-opening outcome — so the
question survives the outage instead of being dispositioned away.

The ordering matters and is not cosmetic: on a missing root Flysystem's `exists()`
*throws* rather than returning false, so the reachability check has to come first.

Without this, a detached drive during Phase 8 would have withdrawn review flags
across all 414 identities, one defensible write at a time.

