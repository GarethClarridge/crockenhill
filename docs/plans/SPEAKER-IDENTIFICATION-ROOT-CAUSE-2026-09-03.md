# Speaker identification — root-cause record, 3 September 2026

**Status:** diagnosis complete, nothing implemented. §10 (added 2026-09-03) reframes the problem as clustering and supersedes the §7 recommendations — read §10 first. This supersedes the causal
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

> **Superseded by §10.** Items 1 and 3 stand. Item 2 (era-bucketed profiles) and
> item 5 (whole-sermon embedding) are both **overtaken** by the encoder result —
> do not build era-bucketed profiles. Kept here because the reasoning that led to
> them is what §10 had to displace.

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

---

## 10. First principles: it is a clustering problem, and the encoder is the defect

Added 2026-09-03 after the operator reframed the question: *"we should be able to
detect speakers solely from audio — even without names, we should be able to work
out which recordings are from the same voice."*

That is the right formulation and it changes the answer. Everything above
measures **closed-set identification** — "which of these named centroids is
this?" — which needs an absolute score to clear a threshold, and §4 shows the
absolute score is largely a domain-match score. **Open-set clustering** needs no
threshold and no names. It also uses something the current design discards: a
speaker with 193 sermons is a *trajectory* through embedding space, not a point,
and comparing to a single centroid cannot follow it.

### 10.1 Clustering already beats identification, and it tracks voice not equipment

104 archive sermons, 13 speakers, no labels used, average linkage on cosine:

| clustering agrees with | ARI (Resemblyzer, whole sermon) |
|---|---|
| **speaker** | **0.479** |
| year | 0.350 |
| 3-year era | 0.196 |

Era dominates the *score*, which identification consumes. It does **not**
dominate the *structure*, which clustering consumes.

One prediction made in-session was wrong and is recorded so it is not retried:
single linkage was expected to walk the year-by-year trajectory. It is the worst
linkage at k=13 (ARI 0.143) — it over-merges into one giant cluster. Average and
complete linkage are the ones to use.

### 10.2 The encoder is the binding constraint

Resemblyzer is a 2019 GE2E model (~4–6% EER on clean VoxCeleb; ours measures 28%
on this audio). ECAPA-TDNN (`speechbrain/spkrec-ecapa-voxceleb`, 192-dim) is
trained with heavy noise and reverb augmentation — i.e. trained to be invariant
to exactly what breaks us. Same 104 sermons, same evaluation:

| embedding | EER | rank-1 | same-spk | diff-spk |
|---|---|---|---|---|
| Resemblyzer, first 60 s (**production**) | 28.0% | 73.1% | 0.828 | 0.718 |
| Resemblyzer, whole sermon | 23.8% | 72.1% | 0.867 | 0.754 |
| ECAPA-TDNN, first 60 s | 18.0% | 83.7% | 0.654 | 0.202 |
| **ECAPA-TDNN, 5-window average** | **7.1%** | **95.2%** | 0.780 | 0.241 |

**A 4× reduction in EER and rank-1 from 73% to 95%.** Note the last two columns:
Resemblyzer puts *different* speakers at 0.718, so every embedding lives in a
narrow cone and only the margin can discriminate — which is precisely why §6
found the accept threshold inert. ECAPA puts different speakers at 0.241. The
separation goes from 0.11 to 0.54.

Robustness check — 22 dates hold two recordings (morning and evening) which share
a channel exactly. Excluding all same-date pairs:

| embedding | EER (all pairs) | EER (excl. same-date) |
|---|---|---|
| Resemblyzer, first 60 s | 28.0% | 28.4% |
| Resemblyzer, whole sermon | 23.8% | 24.2% |
| ECAPA-TDNN, 5-window average | **7.1%** | **7.3%** |

Same-session leakage accounts for 0.2–0.4 points. The result stands.

### 10.3 Clustering with a good encoder is essentially solved

Purity, no labels used at all:

| embedding | ARI @ k=13 | purity @ 13 | @ 26 | @ 35 |
|---|---|---|---|---|
| Resemblyzer, first 60 s | 0.335 | 0.587 | 0.827 | 0.837 |
| Resemblyzer, whole sermon | 0.479 | 0.702 | 0.798 | 0.875 |
| ECAPA-TDNN, first 60 s | 0.545 | 0.740 | 0.933 | 0.952 |
| **ECAPA-TDNN, 5-window average** | **0.718** | **0.856** | **0.990** | **1.000** |

**Purity 1.000 at 35 clusters over 104 recordings.** Over-cluster deliberately and
every cluster is pure — an operator names 35 clusters instead of 104 recordings,
and no name is wrong.

### 10.4 Cross-era propagation — the failure mode, and how far it moves

Hide an entire era, cluster, let hidden recordings inherit the majority name of
their cluster (k=35):

| embedding | 2003–06 | 2007–09 | 2010–13 |
|---|---|---|---|
| Resemblyzer, first 60 s | 0% cov | 75.0% @ 30.2% cov | 91.7% @ 31.6% cov |
| Resemblyzer, whole sermon | 0% cov | 100% @ 20.8% cov | 100% @ 13.2% cov |
| ECAPA-TDNN, first 60 s | 100% @ 7.7% | 94.7% @ **35.8%** | 95.8% @ **63.2%** |
| ECAPA-TDNN, 5-window avg | 0% cov | 100% @ 30.2% | 100% @ **55.3%** |

**When a cluster spans eras it is almost always right; the limit is that it often
does not span one.** ECAPA roughly doubles cross-era coverage (13.2% → 55.3% for
2010–13) at 100% accuracy. 2003–2006 (13 recordings, oldest and worst audio)
remains unreachable.

### 10.5 What this implies for the design

1. **Replace the encoder.** ECAPA-TDNN, 5 windows averaged. This is the single
   largest lever measured anywhere in this document and it subsumes §7 item 2 —
   with a channel-robust encoder there is no need to bucket profiles by era.
   *This is a dependency change and needs approval:* `speechbrain` plus a
   `torchaudio` matching the image's `torch` (2.10.0 — the default install pulls
   2.11.0 and its native library fails to load). Model is ~80 MB, CPU inference.

2. **Stop identifying; start clustering.** Embed every recording, cluster
   deliberately over-clustered, and have the operator **name clusters, not
   recordings**. §10.3 says the names will be right.

3. **Use the ID3 labels you already hold.** 800+ archive mp3s carry preacher
   names (all `preacher_source=id3`, §5). Clustering all corpora together lets
   unnamed 2020–24 video recordings inherit names from named archive audio with
   no human input. The current design cannot use this at all.

4. **Re-derive thresholds afterwards, or drop them.** §6's margin curve is a
   property of Resemblyzer's narrow cone. On ECAPA the same reasoning does not
   apply and the accept threshold may become meaningful again.

Evidence: `storage/app/spk-diag/{ecapa,multiwindow,samples}.json` and the
`cluster.py` / `propagate.py` / `ecapa_analyse.py` / `robust.py` scripts
(gitignored).
