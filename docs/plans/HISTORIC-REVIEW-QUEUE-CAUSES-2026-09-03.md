# Review queue — causes, and which are automatable

**Written 2026-09-03 for the next session.** The operator's goal for the historic
import is **maximising automation**, so this reads the review queue as diagnostic
data — every flag marks a place the pipeline could not decide — rather than as a
work list. Each cause below is characterised against the live corpus, with the
question to investigate next.

Written as a survey, with nothing changed. **§9 records what was then executed
against it on 2026-09-03** — read it alongside §0, whose counts it supersedes.

Prior context: [`HISTORIC-IMPORT-DECISIONS-2026-09-03.md`](HISTORIC-IMPORT-DECISIONS-2026-09-03.md)
(§6 for what landed), plan of record
[`HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md`](HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md).

---

## 0. The numbers, and one correction

**The operator-facing queue is 61 sections, not 79.**

A raw `needs_manual_review = true` count returns 79. **18 of those sit on
superseded runs** — earlier attempts at a recording that a later run replaced —
and `ServiceReviewDashboardQuery` already excludes them
(`ServiceReviewDashboardQuery.php:273`, `:277`, `:612`, `:615`). They are invisible
to the operator and should not be counted. Any future census must filter
`superseded_at`; an unfiltered count overstates the backlog by ~30%.

| measure | value |
|---|---|
| live `needs_manual_review` sections | **61** |
| dashboard `reviewCandidateSectionCount()` | 90 (broader — includes publication candidates) |
| dashboard `pendingMergeCount()` | 41 |
| sections at `pending_approval` | 24 |
| services carrying `needs_review` | 62 |
| obligated historic runs | 31, holding 44.0 GiB |

Live flag census (a section may carry several, so these exceed 61):

| flag | live | verdict |
|---|---|---|
| `childrens_talk_speaker_review` | 14 | **irreducible** — genuinely needs a person |
| `song_title_marker_mismatch` | 14 | **mis-targeted** — comparison is a category error |
| `unmatched_song_section` | 11 | **9 unanswerable**, 2 worth a look |
| `structure_low_confidence` | 10 | mixed; needs a closer read |
| `oos_structure_mismatch` | 6 | unexamined |
| `reading_reference_conflict` | 3 | **fossil — no raise site exists** |
| `structure_missing_preached_reading` | 2 | correct (D5 already narrowed 8 → 2) |
| `heuristic_demotion` | 1 | **fossil — no raise site exists** |
| `structure_micro_section` | 1 | unexamined |
| `structure_oos_same_type_inversion` | 1 | correct by design (6% base rate) |
| `structure_sermon_boundary_material_risk` | 1 | correct by design |

---

## 1. The structural gap behind most of this

**Nothing re-derives `review_flags`. Only `needs_manual_review` is re-derived.**

`services:recompute-section-review-flags` re-reads each section's *stored*
`review_flags` and re-applies `SectionReviewFlagPolicy` to decide
`needs_manual_review`. It never re-runs `ServiceStructureValidator` against the
stored structure, so it can never *remove* a flag the current validator would no
longer raise — it can only re-weigh flags already written.

`ServiceStructureValidator::OOS_REVIEW_FLAGS` documents itself as "cleared at the
start of each run and only re-added when still applicable", which is true —
**per run**. A section validated in August keeps August's flags for ever unless
its whole run is reprocessed.

Consequence: **every validator improvement is invisible to the existing backlog.**
Two flag classes below (`reading_reference_conflict`, `heuristic_demotion`) have
no raise site left in the codebase at all, and four live sections are still held
by them.

This is the same shape as D1's gap — a schema no existing run could populate,
fixed by `historic-import:redetect-structure`. The parallel instrument here would
re-derive flags from the stored structure without a provider call, since
validation is deterministic and the structure is already banked.

**Investigate first.** It is the cheapest lever and it changes what every other
number below means.

---

## 2. `song_title_marker_mismatch` (14) — the comparison is a category error

**This is the highest-value finding and the most reducible class.**

The flag's premise, per its docblock: *"The detector names a song twice for the
same window — once as the section's `songTitle`, once as the chapter marker
covering it."* Where they disagree, one is wrong.

The premise does not hold. `coveringChapterMarkerTitle()` takes **whatever
chapter marker overlaps the section by the most time**, and chapter markers are
usually *structural labels*, not song names. Re-applying today's comparison to
all 14 live sections:

| § | section `song_title` | covering chapter marker |
|---|---|---|
| 806 | Only A Holy God | **Opening worship** |
| 807 | Holy Holy Holy Lord God Almighty | **Opening worship** |
| 1043 | Behold Our God | **Opening worship** |
| 1045 | Holy, Holy, Holy | **Opening worship** |
| 1003 | Bless the Lord, O my soul (10,000 Reasons) | **Opening Songs** |
| 1004 | I'll praise my Maker while I've breath | **Opening Songs** |
| 872 | God, we praise you | **Opening hymn** |
| 815 | These Are The Facts | **Closing song** |
| 718 | Glory be to God the Father | **Congregational singing** |
| 625 | There's a Place Where the Streets Shine | **Closing prayer and blessing** |
| 673 | We Have Heard a Joyful Sound (Jesus Saves) | **Colossians 1:13–23** |
| 813 | My Heart Is Filled With Thankfulness | **Sermon: The Importance of Jesus' Burial** |
| 691 | Who has held the oceans in His hands | Behold Our God |
| 681 | What a Friend We Have in Jesus | Who Can Cheer the Heart Like Jesus? |

**Twelve of fourteen compare a song title against something that was never a song
title.** A song section inside a marker called "Opening worship" is exactly what
correct alignment looks like; the flag fires *because* the pipeline got it right.

Of the two genuine song-vs-song comparisons, **§691 is the same song** — "Who has
held the oceans in His hands" is the opening line of *Behold Our God*. Only
**§681** ("What a Friend We Have in Jesus" vs "Who Can Cheer the Heart Like
Jesus?") is a candidate real disagreement, and it needs listening to.

So the true rate is at most 1 in 14, and plausibly 0.

**The normaliser is not the problem** — `normaliseSongTitle()` already lowercases
and strips punctuation, and the test is two-way `str_contains`, so case, commas
and parenthetical alternates already pass. (A first pass at this mistakenly
compared against `song_title_hint`, which is the *heard* text, not the marker,
and made all 14 look identical. Use `service_structure.chapter_markers` and the
overlap rule, not section metadata.)

**Investigate:** gate the comparison on whether the covering marker plausibly
names a song at all — the OpenLP corpus knows which markers are song items — and
only then compare titles. Add first-line matching so §691's class resolves as
agreement. Expected: 14 → 0–1. Per the repo's own rule, *review earns its cost
when the pipeline made a choice, not when it noticed a fact*; here it is not even
noticing a fact.

---

## 3. `unmatched_song_section` (11) — mostly unanswerable, not unreviewed

Split by `review_reason`:

| reason | live | what it means |
|---|---|---|
| `audio_only_song_segment` | **9** | the section has audio but no lyrics in the transcript |
| `possible_musical_intro` | 1 | may not be a sung item at all |
| `structure_low_confidence` | 1 | §361, "Happy Birthday" — a birthday song, genuinely odd |

**The 9 are structurally unanswerable, not merely unreviewed.** Song audio is
present but no lyric text was transcribed, so there is nothing for the matcher to
match on. This is the same root as the standing finding that **song lyric matching
has never once matched (0 of 177)** — the matcher is asked to work from text that
does not exist for sung material.

A reviewer *can* identify these by listening, so they are not worthless — but they
are not a decision the pipeline failed to make. They should very likely be a
distinct disposition ("identification pending, no evidence available") rather than
a review flag competing with real decisions.

**Investigate:** does anything downstream actually need these identified? If song
sections publish without a catalogue link, this class may be closable outright.

### A separate, concrete gap found alongside

Three unmatched song sections carry an **OpenLP praise number in the title that
resolves exactly in the catalogue**:

| § | title | catalogue |
|---|---|---|
| 35 | My Jesus My Saviour **#319** | song 644, title identical |
| 39 | Come And See **#415** | song 179, title identical |
| 43 | Jesus I My Cross Have Taken **#843** | song 22, title identical |

`songs.praise_number` matches, the titles are character-identical, and
`service_sections.song_id` is **null** on all three. The number is right there in
the string and nothing joins on it.

(These three currently sit on superseded runs, so they are not in the live queue —
but the *mechanism* gap is live and will recur across all 414 remaining
identities in Phase 8.) **Investigate:** link by `praise_number` when the title
carries one, before falling back to fuzzy title matching.

---

## 4. `childrens_talk_speaker_review` (14) — confirmed irreducible

Checked whether the speaker is recoverable without a person. It is not:

- the linked `church_service_items` row carries only `section_summary`,
  `source_evidence`, `livestream_projection` — **no speaker field**;
- the parent `church_services` row has no preacher recorded for these;
- one section (§413) has no linked OoS item at all.

Every one has a real, specific title — *"Jehovah-Tsidkenu"*, *"William Tyndale
and the Bible"*, *"The ice cream that does not last"* — so the *content* was
detected fine. Only "who gave it" is missing, and the recording is the only place
that information exists.

This is mandatory by design and the record was right to call it irreducible.
**33% of services contain a children's talk and ~20% of runs pin their source on
this alone**, so at 414 identities this is the single largest recurring human
cost in the programme.

**Investigate (not to remove it, to reduce its cost):** speaker identification is
already live in production for sermons. Can the same voice-matching supply a
*proposal* here — detector proposes, operator confirms — exactly as D1 did for
sermon absence? That converts 14 open questions into 14 one-key confirmations.
This is the highest-leverage automation item in the whole queue.

---

## 5. Fossils — 4 sections held by flags nothing can raise

`reading_reference_conflict` (3) and `heuristic_demotion` (1) have **no raise site
anywhere in `app/`, `database/` or `config/`**. The code that wrote them is gone;
only `ServiceReviewDashboardQuery` still reads `heuristic_demotion`, to render it.

They are not in `ServiceStructureValidator::OOS_REVIEW_FLAGS`, so even a full
reprocess would not clear them — the recalculation list does not know they exist.

**Investigate:** confirm both are dead, then either add them to the recalculated
set or strip them in a one-shot. Four sections, near-zero risk. Same family as the
[heuristic aligner fossils of 2026-07-23](../../CLAUDE.md).

---

## 6. Not yet examined

Left deliberately, in rough priority order:

- **`structure_low_confidence` (10)** — sampled members are short call-to-worship
  readings (27–46 s), two confirmed songs, and §361 "Happy birthday to Alet".
  Question: is the confidence threshold mis-set for short filler items, the same
  way `structure_micro_section` already is for non-structural types?
- **`oos_structure_mismatch` (6)** — all sermon / bible_reading / childrens_talk
  with **no OoS item aligned**. Question: is this "the OoS and the recording
  genuinely disagree" or "no OoS exists for this service"? The two need different
  answers and the flag does not distinguish them.
- **`pending_approval` (24)** — publication gating rather than detection
  uncertainty. Worth confirming how many are children's talks (mandatory) versus
  items that could auto-release.
- **`structure_missing_preached_reading` (2)**, `structure_oos_same_type_inversion`
  (1), `structure_sermon_boundary_material_risk` (1) — all believed correct as
  they stand; D5 already narrowed the first from 8 to 2.

---

## 7. Suggested order for the next session

1. **Build the flag re-derivation instrument (§1).** Deterministic, no provider
   call, and it changes every count below it. Without it, fixing a validator
   improves only future runs.
2. **Fix the marker comparison (§2).** Best ratio in the queue: ~13 of 14 live
   rows are a category error, and the fix is a guard on marker type plus
   first-line matching.
3. **Propose children's-talk speakers (§4).** Largest recurring cost across the
   remaining 414 identities; the D1 propose-and-confirm pattern already exists.
4. **Link songs by `praise_number` (§3).** Small, mechanical, and it will
   otherwise recur at scale.
5. **Strip the fossils (§5).** Trivial cleanup once §1 exists.

Items 1–2 are pure code with test coverage available and no live-data risk.
Item 3 is a design question worth its own decision record.

---

## 8. Method notes for whoever picks this up

- **Filter `superseded_at`.** An unfiltered `needs_manual_review` count is 30%
  too high and the dashboard already hides those rows.
- **Chapter markers live in `media_processing_logs.processing_metadata
  ->service_structure->chapter_markers`**, not in section metadata.
  `song_title_hint` is the *heard* text; using it as the marker gives a
  confidently wrong answer.
- **Restart the worker containers after any pipeline change.** `queue:work`
  caches classes at boot; a two-day-old daemon silently ran pre-D1 code for hours
  on 2026-09-03 and produced a convincing false diagnosis.
- **Enumerate what a bulk command would write, grouped by column, before
  `--execute`.** A dry-run count of 26 concealed 13 harmful writes among 13 sound
  ones earlier the same day.

---

## 9. Outcome, and the one root cause left open

**Executed 2026-09-03. The live queue went 61 → 36 sections, 62 → 57 services.**
Items 1, 2, 4 and 5 of §7 landed on branch
`review-queue-flag-rederivation-2026-09-03`. Item 3 (children's-talk speaker
proposals) is untouched and remains the largest recurring cost.

`song_title_marker_mismatch` fell 14 → 1 (§681, the single genuine song-vs-song
disagreement §2 predicted), `unmatched_song_section` 11 → 1 (§361, the current
pipeline's real case), and both fossil classes went to 0. `services:rederive-structure-review-flags`
is the instrument §1 asked for; it is idempotent, and a second dry run over the
live corpus reports no changes.

### The praise-number gap was not what §3 described

`service_sections` has no `song_id` column — the three example rows resolve
through a different path, and all three sat on superseded runs. The live blocker
was narrower and more interesting: **the catalogue held two rows for one hymn.**

| id | title | canonical_key |
|---|---|---|
| 22 | Jesus I My Cross Have Taken #843 | `843 jesus i my cross have taken` |
| 1152 | Jesus I My Cross Have Taken #843 | `jesus i my cross have taken 843` |

Identical in every other field — same author, same song book, same
`first_line_key`, same `alternate_title`. Row 1152 is soft-deleted; nothing
referenced it, and the survivor loses nothing.

**Root cause, still open.** `Song::canonicalizeKey()` lowercases and collapses
whitespace and nothing else, so **the praise number's position in the title is
load-bearing**. A source that emits `#843 Jesus I My Cross Have Taken` and one
that emits `Jesus I My Cross Have Taken #843` produce two different canonical
keys for one hymn, and the unique index on `canonical_key` — working exactly as
designed — admits both. The dedup is title-order-sensitive.

Two candidate fixes, neither taken here because this is a catalogue-integrity
decision rather than a review-queue one:

1. **Make the key number-position-insensitive** — lift a leading or trailing
   praise number out of the title before hashing, so both forms collapse to one
   key. Changes every stored `canonical_key`, so it needs a backfill and a
   re-check of everything that joins on it.
2. **Add a uniqueness check on `praise_number`** at sync time, ahead of the
   canonical-key index. Cheaper and narrower, but it only catches numbered
   songs — two spellings of an unnumbered hymn still slip through.

Worth deciding before Phase 8 imports 414 more identities through the same sync.
