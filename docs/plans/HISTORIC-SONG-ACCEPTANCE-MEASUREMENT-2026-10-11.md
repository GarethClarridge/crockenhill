# Historic Song Acceptance Measurement — Protocol (2026-10-11, revised 2026-10-14)

**Status: agreed 2026-10-14 (no bar, §7; stages by settled run, §3). Nothing in the held-out half
had been compared when this was agreed.** The scoring rules here are fixed before any held-out
comparison is computed. It is the
"post-Tier-C song acceptance measurement" that the 2026-09-25 split reserved
(`storage/scratch/hymn-video-20260925/split.json`). It feeds historic plan §4.5 (content acceptance)
and comes before the hymn-usage apply artifact.

## 1. Question

How often does the frozen pipeline bind the right catalogue song to a song section, on services
whose independent truth has never shaped the code?

The truth source is the pianist's hand-kept hymn workbook. It proves **which songs were sung at a
service, not how many times or in what order** (HIR-D8). It is "reasonably accurate", not perfect,
so disagreements are adjudicated by listening (§6).

## 2. Frame

- **Identities:** the 143 `held_out` identities (`date|service`) in `split.json`. The list is
  **fixed**: it is not redrawn from today's run membership. Before computing, check
  `held_out_sha256 = eb3f3bd80918d50c10d3c1e8e18697bcd141ead239e0c7e4b44b201bc556f7a0`.
  By year: 2023: 29, 2024: 38, 2025: 45, 2026: 31.
- **Run per identity:** the identity's current eligible historic run (not superseded, not
  excluded). If there are two, take the highest id, as `compare.php` did.
- **Workbook statements:** read from the hash-bound `reconciliation.json`
  (`297fac755015cda32014c481de609b0734ec75b9f0431da3ab871f498350b98d`). It is **not rebuilt**:
  catalogue changes since 09-25 would shift how its rows resolve. Only statements that name a
  service count. Date-only rows are counted and reported, but stay out of the comparison.

### 2.1 Predeclared exclusions from the denominators

Each one is reported, with its count and reason:

| Code | Rule | Known now |
|---|---|---|
| E1 | The identity has no workbook statements | count at measurement |
| E2 | **2024-12-23\|evening (run 1120)**: misdated twin of discovery run 930 (same service, 2024-12-22 evening, per the workbook's carol list). Its truth was compared on 09-25 through 930, so it isn't independent, and its own date has no workbook rows. | yes |
| E3 | WebM/MKV runs not detected until the duration fix deploys after cutting: **971 (2025-01-05 morning), 1168 (2024-04-21 morning), 1195 (2023-12-25 morning), 1196 (2023-12-24 evening)**. They are measured in stage 2 (§3), not dropped. | yes |
| E4 | No eligible run (failed, excluded, superseded, refused for routing) | count at measurement |
| E5 | Any other misdated or duplicate identity, **evidenced only by dates and source records, never by a song comparison**, and recorded here before computing | none yet |

Of the 16 WebM/MKV runs, the other 12 are outside the held-out frame (discovery half, or before
2023).

## 3. Timing: two stages

**Settled** means: the run is completed, it is not a member of the current follow-up round's latest
snapshot (`storage/app/private/followup-round-*/snapshot.json`), `EnsembleReviewGate` neither
refuses its projection nor requires review, and no recompose is queued for it. A settled run's
bindings are the ones it will be released with. Cutting is **not** needed for M1 and
M3. It **is** needed for M2, because the song publication review (lyric edges, loops) can put a hold
on a section at cut time.

- **Stage 1 (2026-10-14, during the follow-up round):** M1 and M3 on the held-out identities whose
  run is settled. At the 10-14 count that is 59 of 143; 82 are round members.
- **Stage 2 (after the round, the duration-fix deploy and the questions are answered):** M1 and M3 on
  the remaining identities (round members, E3, runs with open questions), and M2 on all of them.
- **What is measured is the final output, not one code revision.** Matching, detection and
  composition code has changed since the 10-10 freeze (for example 7e5168cb4 and the rulings
  applied for the round), and may change again. Each identity is measured once, on the run state
  it will be released with, and its producing code revision is recorded. Results are pooled and
  also reported by stage. An identity measured in stage 1 is re-measured only if its run later
  changes (re-run, recompose, re-match); the re-measurement replaces the earlier one and both are
  kept in the outputs.

Runs with open questions are expected to be the harder cases, which is why they are delayed, never
dropped.

## 4. Units and matching

- **Unit:** a distinct pair of identity and hymn. A song sung twice in one service counts once.
- **Same hymn:** the same `song_id`, or catalogue twins sharing `first_line_key` (as on 09-25).
  Title variants are one `song_id`, so they cannot disagree.
- **Correction for a known resolver defect, fixed now from the discovery half:** workbook rows
  titled "Bless the Lord, O my soul (10,000 Reasons)" or "10,000 Reasons" resolve to #131 (the
  resolver drops the parenthetical and picks #132; found 09-25).
- **Unresolved workbook rows** (`song_id` null): if `candidate_song_id` equals the pipeline's
  binding, the unit counts as *agree via candidate*, reported separately. Otherwise the row is
  listed, but it isn't a unit.
- **Pipeline side:** song sections with a resolved song. Unbound sections are not wrong; they are
  counted as the refusal rate. This covers refused bindings, "none", and the ≤30 s rule from the
  10-11 bulk rulings.

## 5. Measures

- **M1, binding agreement (headline):** of the hymns the pipeline bound, the share the workbook lists
  for that identity. Reported raw, then adjudicated (§6).
- **M2, containment (stage 2):** wrong bindings split into *held* (review caught them) and *unheld*
  (they would publish). **What matters for release is the unheld wrong rate.**
- **M3, coverage (descriptive, no bar):** on **full-grade runs only**, the workbook hymns the pipeline
  did not bind. Up to 30 are drawn with seed `20261011` and classified by listening: no section,
  section unbound, bound to a different hymn, or outside the recording (for example, communion after
  the stream ends). Partial recordings are left out because the workbook gives no time positions.
- **Comparator (not independent):** the same measures on the discovery half after the re-run, set
  beside 09-25's 290 + 2 agree / 38 video-only. This shows the change; it is not evidence of
  generalisation.
- **Uncertainty:** 95% percentile interval from a bootstrap over identities (songs within one service
  are correlated), 10,000 resamples, seed `20261011`.

## 6. Adjudication

Every M1 disagreement goes on a listening page. The existing pattern applies: ask which text
matches, show the service time, play at 3× by default. Each item shows the section's middle 25 s,
the announced-title transcript, and both titles. Answers: *pipeline right*, *workbook right*,
*neither*, *can't tell*.

Adjudicated accuracy = (agree + pipeline right) / (units − can't tell), with *can't tell* reported.
The operator answers **before** seeing the aggregate.

## 7. Release decision (no bar)

There is no numeric threshold. A rate on its own doesn't say whether the errors are worth fixing:
three near-identical catalogue twins and three songs that contradict their announced title are
different findings. The release call is the operator's judgement on the classified errors.

Fixed before computing:

- The rates are reported as measured. An error left unfixed still counts as wrong; a decision not
  to fix never reclassifies it.
- The scoring rules in §4 and §6 (units, same-hymn rule, adjudication answers, *can't tell*
  handling) and the exclusions in §2.1.
- Each adjudicated wrong binding (*workbook right* or *neither*) gets a mechanism class and a
  recorded verdict: **fix** (the mechanism, through the pipeline, then re-run the affected runs)
  or **leave**, with a one-line reason.

Context: on the discovery half (09-25, before the fixes), 13 of 331 bound sections were wrong
bindings that contradicted their own announced title, 9 of them unheld (≈ 2.7%). Current code fixed
all 13 on replay.

Once measured, the held-out half is **spent**: later fixes may use it as discovery evidence, and it
is never again called held out.

## 8. Outputs

`storage/scratch/song-acceptance-20261011/`:
- a measurement script derived from `compare.php`, with a `--half=held-out` flag that refuses unless
  this protocol's commit hash is passed
- per-stage JSON (identities, units, classes, exclusions, hashes of the split, the reconciliation and
  the code revision)
- the adjudication page's rulings
- a short result appended to historic plan §4.5

The hymn-usage apply artifact is generated only after stage 2, against the converged graph.

## 9. Results

### Stage 1 (2026-10-14, provisional, not pooled)

On protocol 8e5cb9ee3, outputs in `storage/scratch/song-acceptance-20261011/` (`stage1-held-out.json`,
`score-stage1-held-out.json`, rulings in `rulings/`). Page: https://claude.ai/artifact/NH1FeCeK3MYaXXFrSSQFWF.

- **Frame:** 55 of 143 held-out services measured, all bound on the full-pass code (74ed3b78b).
  82 are deferred to stage 2 (follow-up round members). Excluded: E1 × 3, E2 × 1, E4 × 2
  (2024-11-17 morning has no run; 1108 failed).
- **M1:** 90 units. 87 agree with the workbook. 3 disagree, and the operator's listening (blind to
  source) chose the pipeline's song every time. Adjudicated: 90 / 90 right, 0 wrong (held or
  unheld), 0 *can't tell*. 4 song sections unbound.
- **Interval:** with no errors, the percentile bootstrap collapses to [0, 0]. Reported alongside it:
  the rule-of-three one-sided 95% upper bound of **3.3%** (3 / 90), which treats units as
  independent and so understates the uncertainty.
- **The three disagreements (all workbook-side, no pipeline verdict needed):**
  - 1237 (2023-03-12 morning): Timothy Dudley-Smith's "How shall they hear, who have not heard" (#389)
    was sung, as announced. The workbook names #390 ("How Shall They Hear The Word Of God"), even
    though it uses #389's own title elsewhere: a slip in the workbook.
  - 1224 (2023-06-11 morning): 10,000 Reasons (#131) was sung. The workbook row is a bare "Bless the
    Lord, O my soul", which resolves to #132. This is the same title family as the §4 correction.
    One other 2023+ row has this bare title, so it may come up again in stage 2.
  - 1200 (2023-12-10 morning): "I Will Give Thanks To You" (#438, Psalm 57) was sung, and the
    workbook doesn't list it.
- **M3:** 12 workbook hymns unbound on full-grade runs. The draw of up to 30 is taken over the
  pooled set in stage 2.
- **Selection:** settled runs are those that needed no follow-up, so they skew clean (the discovery
  half's settled runs had 0 disagreements in 43 services). Stage 2 holds the harder cases.
