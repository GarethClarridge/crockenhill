# Speaker identification — root-cause record, 3 September 2026

**Status:** diagnosis complete, nothing implemented. All experiments landed (§8 closed 2026-09-03). This supersedes the causal
account in
[`CHILDRENS-TALK-SPEAKER-DECISIONS-2026-09-03.md`](CHILDRENS-TALK-SPEAKER-DECISIONS-2026-09-03.md)
§8 and corrects two conclusions carried in memory. The work that record *landed*
(the shortlist, the disposition classes, `services:redetect-childrens-talk-speakers`)
is unaffected and remains correct.

Branch `childrens-talk-speaker-decisions-2026-09-03`, clean at `70ad352be`.
CBC drive reattached mid-session as `/Volumes/Sonnics`; `laravel.test` recreated
to re-bind the stale mount, which is what made the audio experiments possible.

Opening question: *"why is children's-talk (and maybe sermon) speaker detection
not functioning well in the historic video work, when we had this working
essentially perfectly in prod?"*

---

## 1. The answer in one paragraph

The four real speaker profiles are built from a **four-month window** of
2025 audio. Cosine similarity against a Resemblyzer centroid measures recording
channel at least as strongly as it measures voice, so the score is largely a
*"recorded like my reference clip"* score with speaker identity as the residual.
Production has only ever asked the in-window question and answers it well.
The historic lane asks the out-of-window question — twenty-three years of
changing equipment — and the same thresholds reject nearly everything. Nothing
regressed; the system met its first real test.

---

## 2. Corrections to what was previously believed

**(a) "Speaker ID rarely runs because ID3 answers first" — wrong for production.**
The 818 `skipped_id3` runs in the local database are all
`processing_type=audio`: the tagged archive mp3 import. Production has ten audio
runs in total and **zero** with a speaker step. Every prod livestream invokes the
model. The operator was right; the claim was an artefact of reading a local
database that is not a prod copy.

**(b) "Era must be compensated for" — tested and false.** Memory
[[speaker-embeddings-encode-acoustic-era]] recommended era-bucketed profiles or
domain-mean subtraction. Measured on the 110 stored sample embeddings:

| transform | EER | rank-1 |
|---|---|---|
| baseline (what production does) | 28.4% | 70.9% |
| global mean subtracted | 26.5% | 70.9% |
| per-3-year-era mean subtracted | 26.5% | 72.7% |
| per-year mean subtracted | 27.6% | 65.5% |

Era compensation buys ~2 points. **Do not build era-bucketed profiles on the
strength of that finding.** Era correlates with score because the speaker signal
is weak, not because era is a separable nuisance term that subtraction removes.

**(c) "Children's talks are intrinsically harder" — false.** Five surviving
section audio files were recovered from
`/mnt/historic-work/quarantine/section-publications`. The first 60 s (the window
production embeds) against the body of the same talk:

| section | voiced | opening vs body | body self-consistency |
|---|---|---|---|
| 509 | 445 s | 0.970 | 0.974 |
| 632 | 422 s | 0.979 | 0.980 |
| 782 | 378 s | 0.952 | 0.976 |
| 809 | 393 s | 0.978 | 0.966 |
| 891 | 249 s | 0.978 | 0.976 |

The call-and-response worry does not show up. The opening is as representative as
any other window. **Children's talks are not a special case and need no separate
input policy.**

**(d) "The 0.08–0.10 margin band is unmeasured" — now measured.** See §6.

---

## 3. What production actually is

Read from prod on 2026-09-03 (three read-only `SELECT`s via
`docker compose … exec -T app php artisan tinker`).

### 3.1 The profile pool is 4 real profiles and 21 zero vectors

25 profiles, **all `is_active = 1`**. Only four carry any samples:

| profile | samples | built from |
|---|---|---|
| Mark Drury | 10 | 2025-11-09 → **2025-12-21** |
| Gareth Clarridge | 1 | 2025-12-28 |
| Peter Clarridge | 1 | 2025-11-30 |
| Laurie Everest | 1 | 2025-08-31 |

The other 21 have `sample_count = 0`, `stored_samples = 0`, `dims = 256`, and
element 0, 100 and 255 all exactly `0`. They are literal zero vectors, created by
`BootstrapSpeakerProfilesCommand.php:118`:

```php
'centroid_embedding' => array_fill(0, 256, 0.0),
'sample_count' => 0,
'is_active' => true,
```

When every extraction for a preacher fails, the row survives in exactly this
state. It is **inert for matching** —
`ResemblyzerSpeakerIdentificationService.php:246` returns `0.0` for a zero-norm
vector, so these sort to the bottom and never displace a real candidate — but 21
preachers (Aaron Flanagan, Ivan Kimble, Tarl Reeves and 18 others) are advertised
as identifiable and can never be identified.

### 3.2 The model has reached a real decision seven times

Thirteen non-skipped runs exist, covering services 2025-12-14 → 2026-01-25, all
back-processed (runs created 2026-02-18 → 2026-07-18). It is one batch, not a
weekly history.

| what happened | n | evidence |
|---|---|---|
| Numba env crash — model never ran | 4 | `cannot cache function '__o_fold': no locator available` |
| audio file missing | 1 | run 68 |
| scored against an empty pool | 1 | run 73: *"Top score 0 below accept threshold 0.75"* |
| **matched** | 6 | all Mark Drury, all correct |
| genuine decline | 1 | run 83, margin 0.0065 |

**Real prod record: 6 correct of 7 attempts, 1 declined.** That is a good record
and it matches the operator's recollection. It is also a sample of one preacher
inside one five-week window: every match sits between 2025-12-28 and 2026-01-18,
scoring 0.965–0.980 at margins 0.140–0.172 — within a month of the window Mark
Drury's own profile was built from.

Run 73 is the cleanest artefact in the set: it scored `0.00` against everything
because it ran on 2026-02-19 *before* the four real profiles were populated later
that same day. The four Numba failures were fixed by `7c70cbea3` (2026-02-19, on
master) and have not recurred; `scripts/extract_embedding.py:23` carries the
`NUMBA_CACHE_DIR` fix. **Both of those are closed** — they are not live defects.

### 3.3 Children's-talk identification has never run in production

Production holds **29 service sections in total**, of which exactly **one** is a
children's talk, and it has **no `childrens_talk_speaker` metadata**.

There is no production baseline for children's-talk speaker identification. It
did not regress. Its first ever execution was against the historic corpus.

---

## 4. Why the historic lane behaves differently

Same code, same four real profiles, same thresholds:

| | prod (2025-12 → 2026-01) | historic 2020–24 |
|---|---|---|
| real attempts | 7 | 30 |
| match rate | 86% | 13% |
| mean top score | ~0.97 | 0.778 |

The decisive measurement, needing no ground truth at all — Mark Drury's current
profile scored against Mark Drury:

| scored against | score |
|---|---|
| his own 2025–26 livestream sermons | **0.914 – 0.977** |
| his own 2013 archive sermons | **0.764 mean, 0.791 max** |
| 100 sermons by other people, 2003–2013 | 0.693 mean, **0.778 max** |

His own preaching from 2013 sits **below the 0.75 accept gate**, and a stranger
from the same era beats his average. The absolute score is close to a
domain-match score.

---

## 5. How weak the representation is

Leave-one-out identification over the 110 stored sample embeddings (known
speaker, known date, 13-way closed set, all 2003–2013 audio):

- **Equal Error Rate 28.4%.** Published Resemblyzer EER on channel-matched speech
  is ~4–6%.
- Variance decomposition: **speaker 40.7%, recording year 34.0%**.

Sample-to-sample cosine similarity, which is the clearest statement of the
problem:

| comparison | mean |
|---|---|
| same speaker, **same recording** (window to window) | **0.948** |
| same speaker, different recording, same year | 0.861 |
| different speaker, same year | 0.745 |
| different speaker, different year | 0.702 |

Changing *which service was recorded* costs 0.087. Changing *who is speaking*
costs 0.116. Session variability is nearly as large as speaker variability, and
that is the whole defect.

Ruled out as contributing causes: archive audio quality (128 kbps mono 44.1 kHz),
short windows (all 110 samples used a full 60 s of voiced audio), label quality
(all 110 labels are `preacher_source=id3` from the church's own tags), and
wrong-speaker contamination of the embedding window (26 archive sermons: the
production window agrees with mid-sermon audio at 0.930 against a 0.948
mid-to-mid baseline; only 2 of 26 showed a real intruder).

---

## 6. The margin curve, measured

Threshold sweep on the archive, leave-one-out, 110 queries:

| accept | margin | auto-accepted | precision | coverage |
|---|---|---|---|---|
| any | **0.10** (current) | 16 | 100.0% | 14.5% |
| any | **0.07** | 44 | 95.5% | 40.0% |
| any | 0.05 | 57 | 91.2% | 51.8% |
| any | 0.03 | 71 | 90.1% | 64.5% |
| any | 0.02 | 81 | 85.2% | 73.6% |

Two results:

**The accept threshold is inert in-domain.** Rows are identical from accept 0.75
down to 0.00, because in-domain every top score clears it. It does nothing except
block cross-domain queries such as Drury's 0.764.
`ResemblyzerSpeakerIdentificationService.php:156` is a gate that only ever fires
on the case we most want to answer.

**Margin 0.10 → 0.07 nearly triples coverage at 95.5% precision.** This is
in-domain, so it does not license the same move cross-domain; prod's absolute
scores sit ~0.11 higher than the historic lane's.

---

## 7. What follows

Not decided — these are the options the evidence supports, in dependency order.

1. **Deactivate the 21 zero-centroid profiles, and stop creating them active.**
   `BootstrapSpeakerProfilesCommand` should create a profile inactive and
   activate it only once a centroid exists. Its current failure mode leaves a
   live profile that can never match. Smallest change, no calibration risk.

2. **Build era-appropriate profiles from the archive.** 104 reachable archive
   sermons with trustworthy ID3 labels across 13 preachers, on the CBC drive at
   `/mnt/historic-work/reclaimed-20260826/public/sermons/audio`. This is the only
   route by which pre-2020 recordings become identifiable at all — the current
   pool cannot name anyone outside the four. Note that
   `BootstrapSpeakerProfilesCommand:251` takes `orderByDesc('date')->limit($n)`,
   the newest N, so it cannot currently produce a historic-era profile without a
   date-window option.

3. **Stop `enforce` writing "Visiting Speaker" on historic runs.**
   `IdentifySpeaker.php:274` overwrites the preacher with a fallback and sets
   `preacher_source=default` on every no-match. It destroys no prior data
   (`church_service_items` has no speaker column and `CreateSermonRecord` reads
   only ID3, so historic runs genuinely have nothing there), but it manufactures
   a confident-looking answer where the honest state is unknown, and it is why
   the 53 local "Visiting Speaker" rows carry no evidence. Shadow mode plus the
   stored shortlist would accumulate real labels; the `proposal_rank` machinery
   from the 3 September record already records them.

4. **Do not touch the margin threshold yet.** §6 measures it in-domain only.
   Item 3 is what produces the cross-domain labels needed to set it honestly.
   When it is touched, do it on multi-window embeddings — see §8.

5. **Embed the whole sermon, not its first 60 s.** §8 measures EER 28.0% → 23.8%
   for a change that is nearly free, because the expensive VAD pass already runs
   over the whole file and the result is then discarded. Independent of items
   1–4 and safe to do on its own; it does not move rank-1, so it changes no
   existing decision, only the quality of the confidence signal.

---

## 8. Multi-window embeddings — measured, modest, and nearly free

All 104 reachable archive sermons were re-embedded three ways from the same
decoded audio and scored identically (leave-one-out, 13-way):

| embedding | EER | rank-1 | same-speaker | diff-speaker |
|---|---|---|---|---|
| first 60 s (**what production does**) | 28.0% | 73.1% | 0.828 | 0.718 |
| average of 5 × 60 s windows | **24.9%** | 73.1% | 0.860 | 0.749 |
| whole sermon as one utterance | **23.8%** | 72.1% | 0.867 | 0.754 |

**EER improves by ~4 points; rank-1 does not move.** Averaging suppresses
per-window sampling noise — the same-recording ceiling was already 0.948, so a
single window was measurably noisy — but it does nothing about the channel term,
which is the dominant error. This is an improvement to the *confidence signal*,
not to identification.

Margin sweep, with counts, because the precision differences rest on very few
errors:

| margin | first 60 s | 5-window | whole sermon |
|---|---|---|---|
| 0.10 | 100.0% (16/16) | 94.7% (18/19) | 100.0% (17/17) |
| 0.07 | 95.1% (39/41) | **97.8% (44/45)** | 97.6% (41/42) |
| 0.05 | 92.7% (51/55) | **98.1% (52/53)** | 94.9% (56/59) |
| 0.03 | 87.3% (62/71) | 90.4% (66/73) | 91.8% (67/73) |

At margin 0.05 the multi-window variant makes 1 error where the production
window makes 4, at the same coverage. **That difference is not significant on its
own** — 1 versus 4 on ~54 selections. The EER figures, computed over all 5,356
pairs, are the trustworthy comparison; treat the precision column as suggestive
only.

**The improvement costs almost nothing.** `extract_embedding.py` already calls
`preprocess_wav()` on the *entire* file — the VAD and normalisation pass, which
is the expensive part — and then discards everything after the first 60 s
(`wav[:max_samples]`). Embedding the whole voiced signal adds only encoder
forward passes over audio that has already been decoded and processed. The
current code pays full price and keeps roughly 3% of what it paid for.

**Recommendation:** adopt whole-sermon embedding as the default query, and treat
it as a prerequisite for item 4 rather than as a fix. If the margin threshold is
ever lowered, it should be lowered on multi-window embeddings, not on the single
opening window.

## 9. Open

- **Cross-domain precision** at any margin below 0.10 is unmeasured, and item 3
  is the prerequisite for measuring it.
- **Prod's 38 livestream runs with no speaker step** (47 total, 9 with one) are
  unexplained. Probably predate the feature; not chased.

Scratch scripts and exported embeddings for every measurement above are in
`storage/app/spk-diag/` (gitignored).

Related: [[childrens-talk-speaker-shortlist-2026-09-03]],
[[speaker-embeddings-encode-acoustic-era]],
[[speaker-identification-live-in-prod]],
[[speaker-identification-local-bootstrap-2026-07-25]]
