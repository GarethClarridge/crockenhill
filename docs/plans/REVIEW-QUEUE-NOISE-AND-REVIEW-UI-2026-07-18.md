# Review Queue Noise and Review UI — Findings and Resolution Plan

> **Status (2026-07-18): not started.** Findings verified against the local database, the
> July corpus runs of 2026-07-09/10 (`storage/scratch/july-test-files`, test-set-2), and the
> admin UI in a browser session on 2026-07-18. **Sequencing:** Workstreams A and B should land
> **before** Stage 3 of the promotion soak
> (`docs/operations/llm-structure-promotion-soak.md`) — the soak reviews every sample service
> through the inbox/workbench, and §5.1 review quality depends on the queue only containing
> actionable items. If the soak starts first, do not change flag semantics mid-soak without a
> scorecard note. **Agents must not** touch anything on the backlog item 1.5 deletion list
> (`SongSectionAligner`, `OosAlignmentService`, `StructuralSectionAligner`,
> `ServiceSectionClassifier`, `ClassifySpeechSections`, etc. — see
> `JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md` §1.5): every fix here targets the LLM-path
> and review surfaces that survive that deletion. Open decisions **OD1–OD3** below need
> maintainer input before their items start; everything else is agent-executable.

## Why this plan exists

The July corpus runs proved the LLM-first pipeline's detection quality (91%/89% type
accuracy), but reviewing the resulting services showed that **the review queue drowns its own
signal**. On 2026-07-18 the local inbox held **222 review-candidate sections and 79 flagged
services** (both display-capped at 50) for a database of 401 services — and the overwhelming
majority of those items have no action an operator would ever take. A typical clean corpus
service produces 5–8 review items of which 0–2 are genuinely useful (e.g. the 3 May 2026 run's
"reader announced Psalm 130 but read Psalm 113" flag — exactly what review is for — sits
among six correctly-matched songs and four low-confidence transition fillers).

The root pattern: the review predicate treats **provenance** signals ("this label was derived
from the OoS"), **doubt** signals ("the model is not sure"), and **consequence-free doubt**
("the model is not sure whether filler is `other` or `notices`") identically. A review queue
only works when every item implies an action.

The second problem is the review UI itself: the workbench states each flag three times, shows
constant-value chips on rows where they carry no information, offers no way to say "this is
fine", and renders ghost rows for failed historic runs.

## Evidence base

All numbers from the local dev database on 2026-07-18 (session: corpus runs of 2026-07-09/10,
processing ids in `july_corpus_rerun_2026_07_09` / `july_test_set_2_results_2026_07_10`
memory, plus the bulk OpenLP archive import of 2026-05-08):

| Fact | Value |
|---|---|
| Review-candidate sections (7-reason predicate) | 222 |
| Services with `needs_review = 1` | 79 |
| …of which are conf-0.5 bulk OpenLP imports from 2026-05-08 | 67 |
| Services with `canonical_conflict_state = 'detected'` | **401 of 401** |
| Sections flagged per corpus run (13–21 sections each) | 5–8 |
| …of which `song_match_type='inferred'` at confidence 0.90–1.00 | 3–6 |
| …of which `structure_low_confidence` on non-publishable filler | 2–8 |
| …`structure_oos_cross_type_inversion` on sections at 0.85–1.00 confidence | 0–4 |

## The issues

### Queue noise (N-series)

**N1 — Every LLM-path song match is a permanent review item.**
`MatchSongsFromTranscript::applySongMatch()` (`app/Jobs/MatchSongsFromTranscript.php:534`)
sets `song_match_type = Inferred` unconditionally — even when the match was verified against
the transcript at ≥ 0.75 confidence and the catalogue title written back. The enum has a
`Confirmed` case, but on the LLM path **nothing ever sets it** (the only writer is
`SongSectionAligner`, which is heuristic-path and on the 1.5 deletion list). Consequences:

- `inferred`/`unmatched` are review reasons in
  `ServiceReviewDashboardQuery::reviewReasons()` (`app/Queries/ServiceReviewDashboardQuery.php:312-326`)
  and in the base predicate (`:447`), so every matched song — including sections already
  showing a "Published" chip — sits in the inbox.
- **They are un-dismissable.** `SaveServiceSection` clears `needs_manual_review` and
  `review_flags` (`app/Actions/ServiceReview/SaveServiceSection.php:103-108`) but never
  touches `song_match_type`, so "reviewing" an inferred song leaves it a review candidate.
  The only exit is a state no code path can reach.
- **Public-facing side-effect:** `PublicSongUsageService` (`:106`) and
  `PublicSongCatalogService` (`:189`) count only `Confirmed` matches, so all LLM-path song
  usage is invisible to the public song pages. After the 1.5 deletion nothing would ever
  increment public song usage again.

**N2 — Any soft flag forces `needs_manual_review`, regardless of consequence.**
`ServiceStructure::toClassifiedSections()` (`app/Data/ServiceStructure.php:165`) maps
`reviewFlags !== []` straight to `needs_manual_review = true`. Combined with
`ServiceStructureValidator::annotateSoftFlags()` flagging every section below the 0.85
confidence threshold (`app/Services/ChurchService/Structure/ServiceStructureValidator.php:406`),
every honest low-confidence rating on filler (`other`, `notices`, transitions, Whisper
"Thank you. Thank you." ambience) becomes a manual-review item. These sections are
non-publishable (publishable handlers are only `childrens_talk` and `song` —
`config/media-processing.php:400`) and their exact type has no downstream effect. Roughly
half of all section flags in the corpus runs are this class.

**N3 — `structure_oos_cross_type_inversion` forces review of high-confidence sections.**
The validator's own comment (`ServiceStructureValidator.php:332-337`) says cross-type
inversion "is a legitimate authoring style and merely earns a review flag" — OpenLP exports
group items by type, so performed order routinely differs from printed order. But because of
N2's blanket mapping, the flag still forces `needs_manual_review` on sections at 0.85–1.00
confidence (on 2024-11-03: notices claiming `Notices2024Looped.pptx`, plus three songs at
0.95–1.00). Note the sermon auto-extraction policy already treats this flag as
non-disqualifying (`app/Support/SermonAutoExtractionPolicy.php:34-37`) — the review queue
should take the same view.

**N4 — 67 bulk-imported historic services flagged for a review nobody will do.**
`OpenLpServiceParser` (`app/Services/Song/OpenLpServiceParser.php:97-115`) subtracts 0.5
confidence when the upload filename and embedded `.osj` identity disagree, and anything below
the 0.60 `service-tracking.confidence.review_below` threshold gets `needs_review = true` at
import (`app/Services/ChurchService/ImportChurchServiceFromOpenLp.php:152`). The 2026-05-08
bulk archive import (renamed files) produced 67 such services — ~40 of the 50 visible
service rows in the inbox are 2023 items reading "Check the imported order of service, then
mark it reviewed", each clearable only one at a time. They bury the real items.

**N5 — Children's-talk speaker review fires with no speaker profiles configured.**
The `childrens_talk_speaker_review` reason (`ServiceReviewDashboardQuery.php:284-294`)
triggers whenever a prediction exists and no review is stored — even when the speaker
identification panel itself reports "No active speaker profiles are available for provider
'resemblyzer'". Locally (and on any environment without profiles) this is an unactionable
flag on every children's talk.

**N6 — A "canonical conflict" is recorded for every service at first import.**
`ChurchServiceCanonicalUpdateService::finalize()`
(`app/Services/ChurchService/ChurchServiceCanonicalUpdateService.php:38-65`) diffs the item
snapshot before/after sync and records a `canonical_conflict` whenever the diff is non-empty
— which is **always true at first import** (every item is an `added_item` change). The
derived column (`ChurchServiceReviewStateService::normalizedColumns()`, `:62-70`) then
reports `detected` forever unless review is reopened. Result: 401/401 services carry
`canonical_conflict_state = 'detected'`, `reason = canonical_changed`. Today this is mostly
latent (only the `Reopened` state feeds `needs_review`), but the column is unusable as a
signal and any future UI reading it would flag 100% of services.

### Review UI confusion (U-series)

Observed on `/admin/services/inbox` (`review-inbox.blade.php`) and the workbench
(`show-church-service.blade.php` + `partials/service-flow-row.blade.php`,
`partials/section-review-panel.blade.php`), services 797 (3 May 2026) and 605 (3 Nov 2024):

**U1 — Inbox counts contradict each other.** Tabs read "All 104 / Sections 50 / Segments 4 /
Services 50" while the line beneath reads "Showing the newest 50 of 222 sections… 50 of 79
service items. Action items to surface the rest." The tab numbers are post-cap
(`ReviewInboxQuery::SOURCE_CAP`), the sentence pre-cap, and "Action items to surface the
rest" is not a sentence an operator can parse.

**U2 — Each flag is stated three times.** A flagged section renders (1) header chips
("⚠ Review: structure low confidence", "Low confidence"), (2) a detail panel repeating
REVIEW REASON and CONFIDENCE, (3) a yellow edit box repeating the same chips again
("Manual review", "Low confidence", "Structure Low Confidence"). Same string, three
castings.

**U3 — Constant-value chips read as information.** "Not in plan" / "Not in Order of
Service" appears on prayers, sermons, welcomes — types that OpenLP plans never contain, so
the chip is true of 10+ rows on every service. "Not Applicable" (publication status) marks
every non-publishable row. Both chips only carry information on the row types where they can
vary (songs, readings, children's talks).

**U4 — There is no "this is fine" action.** The only per-section control is the type/title
form + Save (`ReviewsServiceSections::saveSection()`); clearing a flag means saving an
unchanged form, which is neither discoverable nor honest. There is no per-service "confirm
all", and `markServiceReviewed()` clears the service flag while leaving all its section
flags (the service then re-enters via the section source). Combined with N1's un-dismissable
songs, a diligent operator literally cannot empty the queue.

**U5 — The speaker picker is the raw preachers table.** The children's-talk speaker dropdown
lists ~100 entries including tape codes (`081A`…`090B`), sermon-title junk rows ("God Is
With Us", "Palm Sunday", "The Gospel Of John"), and three spellings of Dave Manderscheid.
(Data quality is its own backlog; the picker should at least filter to plausible humans.)

**U6 — Failed historic runs render ghost timelines.** On service 797 a `failed` run from two
months ago still renders a full card with eleven rows, each saying "Expected from Order of
Service — not detected in recording" *and* "Expected from Order of Service — not detected"
back to back, plus live Reclassify/Delete buttons. Nothing on the card says "this run
failed and was superseded by the completed run above".

**U7 — Sidebar and stepper disagree.** Service 797 shows stepper "Review (needs attention)"
while the sidebar shows "Review status: Ready".

## Resolution plan

### Workstream A — make the queue mean something [design]

Highest leverage; A1–A3 remove ~80% of current queue volume. Each item: failing test first
(`feedback_bug_reproduction_test_first`), then fix, then the four quality gates.

**A1 — Confirmed song matches on the LLM path.**
In `MatchSongsFromTranscript::applySongMatch()`, set
`song_match_type = Confirmed` when the transcript-verified match confidence ≥ the existing
`title_writeback_min_confidence` threshold (0.75) — i.e. exactly when the code already
trusts the match enough to overwrite the display title — and keep `Inferred` below it.
Reconcile the enum's description strings (`ServiceSectionSongMatchType.php:29-30`) with the
new semantics.
- *Tests:* extend `MatchSongsFromTranscript` feature tests: high-confidence match ⇒
  `Confirmed` + absent from `ReviewInboxQuery` output; sub-threshold match ⇒ `Inferred` +
  present. Add a public-side assertion that a `Confirmed` LLM-path match appears in
  `PublicSongUsageService` output (this is the N1 side-effect being fixed — flag it in the
  PR description as a deliberate public behaviour change).
- *Depends on OD1.*

**A2 — Soft flags stop forcing review on non-publishable filler.**
In `ServiceStructure::toClassifiedSections()` (`app/Data/ServiceStructure.php:165`), derive
`needs_manual_review` from *disqualifying* flags only:
`structure_low_confidence` / `structure_micro_section` count only when the section type is
publishable (`childrens_talk`, `song`) or is `sermon` / `bible_reading` (they gate
extraction and reading references); on `other`/`notices`/`prayer`/`welcome` they remain
metadata annotations but do not open review. Keep the flags themselves — they are still
written to `metadata.review_flags` and still visible in the workbench detail panel.
- *Guard-rail:* sermon sections keep current behaviour exactly — `SermonAutoExtractionPolicy`
  consumes `needs_manual_review` + flags, and its semantics must not shift (pin with a test
  asserting a low-confidence sermon section still blocks auto-extraction).
- *Tests:* unit tests on `toClassifiedSections()` per type × flag matrix; feature test that
  a low-confidence `other` section no longer appears in the inbox while a low-confidence
  `song` still does. Also update `ChurchServiceReviewSynchronizer::openReviewFromSections()`
  expectations if fixtures relied on filler flags rolling up to `needs_review`.

**A3 — `structure_oos_cross_type_inversion` demoted to annotation.**
Add it to the non-review set in the same `toClassifiedSections()` change (mirroring
`SermonAutoExtractionPolicy::NON_DISQUALIFYING_REVIEW_FLAGS`, which already encodes this
judgement). The flag stays in metadata and the workbench detail panel; it stops forcing
`needs_manual_review` and stops being an inbox reason on its own.
- *Tests:* section with only the inversion flag ⇒ not a review candidate; inversion flag
  plus low confidence on a song ⇒ still a review candidate.

**A4 — Gate speaker review on active profiles.**
The `childrens_talk_speaker_review` reason (and the `childrens_talk_speaker_review` review
flag written by the pipeline) should require at least one active `SpeakerProfile` for the
configured provider/model. When none exist, skip the flag and leave the prediction in
metadata for later.
- *Tests:* with no profiles ⇒ no reason, section absent from inbox; with a profile ⇒
  unchanged behaviour.

**A5 — Stop recording a canonical "conflict" at first population.**
In `ChurchServiceCanonicalUpdateService::finalize()`, skip the
`withRecordedCanonicalConflict()` write when `$beforeSnapshot === []` and there are no
`conflicts` (first population is not a conflict; the `ChurchServiceCanonicalListChanged`
event at `:93` must still fire — reconciliation depends on it). Review-reopen behaviour for
genuinely changed services is untouched.
- *Tests:* first import ⇒ `canonical_conflict_state = none`, event fired; second import with
  changes ⇒ `detected` as today; reviewed-then-changed ⇒ `reopened` as today.
- *Depends on OD2 (whether to also backfill-clear the 401 rows — see B2).*

### Workstream B — one-shot data cleanup [operational]

After Workstream A merges (order matters: cleanups must not be re-flagged by old code).

**B1 — Clear the bulk-import review flags.** One guarded artisan command (or tinker
runbook entry) clearing `needs_review` on the 67 `source='openlp'` services whose only
signal is `confidence_score = 0.5` from the 2026-05-08 archive import (no review triggers, no
section flags). Record the id list in the command output. Do **not** blanket-clear: services
with real triggers (`ambiguous_sermon_detection`, etc.) keep their flag.
- *Prod note:* the same import ran in prod; the command must be prod-safe (counts-only
  output, `--dry-run` default) per the production-audit conventions.

**B2 — Normalise historical `canonical_conflict` records** (pairs with A5, gated on OD2):
recompute the state columns for rows whose only recorded conflict is the first-population
one, clearing `detected` back to `none`. Keep `canonical_conflict_history` intact.

**B3 — Re-sync existing corpus sections** (local only): re-run the review synchronizer over
the 2026-07-09/10 corpus services after A1–A3 so the soak-era inbox reflects the new
predicates without reprocessing. If simpler, delete-and-reimport the local corpus runs —
they are throwaway.

### Workstream C — review UI clarity [design]

All C items: activate the `frontend-design` skill; British English strings; Dusk coverage
via the existing admin flows where practical, Livewire component tests otherwise. C1/C2 are
the substance; C3–C6 are small.

**C1 — One reason line per section; chips carry state, not prose.**
In `service-flow-row.blade.php` / `section-review-panel.blade.php`: a flagged section shows
its reason(s) **once** — chips on the header row, nothing repeated in the detail panel or
edit box. Suppress "Not in plan" on section types that can never be planned (everything
except song / bible_reading / childrens_talk / presentation-backed types) and suppress
"Not Applicable" publication chips on non-publishable types; both chips remain wherever
they can actually vary.

**C2 — A first-class "Confirm" action.**
Per flagged section: a one-click **Confirm** button that clears `needs_manual_review` +
`review_flags` (and for song sections marks the match reviewed) without requiring a form
save — i.e. extract the clearing half of `SaveServiceSection` into a `ConfirmServiceSection`
action the button and the save path share. Per service: "Confirm all remaining" with the
same skip-guard logic as `approvePendingPublications()` (blocked items are listed, not
silently cleared). `markServiceReviewed()` should warn when section flags remain rather
than letting the service bounce straight back into the queue.
- *Tests:* action unit tests + a Livewire test driving confirm-one and confirm-all;
  authorization stays admin-gated (add routes/actions to
  `AdminLivewireAuthorizationTest` scenarios if new endpoints appear).

**C3 — Honest inbox counts.** ~~Make the tab numbers and the summary sentence agree.~~
**Superseded (2026-07-19)** by
[SERVICE-SCREENS-CONSOLIDATION-2026-07-19.md](SERVICE-SCREENS-CONSOLIDATION-2026-07-19.md)
Phase 4, which folds the inbox into the services hub and fixes the counts there. Do not
polish the inbox's counts — the page is being retired.

**C4 — Failed-run cards say so.** A `failed`/superseded run on the workbench collapses to a
single summary row ("Run failed 2 months ago — no sections were produced") with the
Reclassify/Delete actions, instead of rendering per-item "Expected from Order of Service —
not detected" ghost rows (also fix the duplicated sentence in
`timeline-alignment-table-row.blade.php`). Skip planned-only rows entirely for runs with no
sections.

**C5 — Reconcile stepper and sidebar.** "Review status: Ready" in the sidebar and "Review —
needs attention" in the stepper must derive from the same source
(`ServiceFlowBuilder`); pick the stepper's derivation and make the sidebar echo it.

**C6 — Speaker picker hygiene.** Filter the dropdown to preachers plausibly usable as
speakers (exclude the tape-code/`^[0-9]{3}[AB]$` and known non-person rows — cheap heuristic
now, real data cleanup stays a separate backlog item), and show the free-text fallback more
prominently since it is the common case.

## Open decisions (maintainer input needed before the dependent items)

| # | Decision | Recommendation |
|---|---|---|
| OD1 | A1 threshold semantics: is transcript-verification at ≥ 0.75 (the existing writeback bar) sufficient for `Confirmed`, or should `Confirmed` require a stricter bar (e.g. 0.85)? Affects public song-usage counts. | Use the writeback bar — the code already trusts it to overwrite titles; two bars would be a new concept to maintain |
| OD2 | Backfill scope for canonical-conflict state (B2): clear only first-population records, or reset all non-reopened `detected` rows? | First-population only — anything else risks erasing genuine unreviewed diffs |
| OD3 | B1 in production: run the same clear against the prod bulk import, or leave prod flags until the operator has seen the post-A inbox? | Run it — 67 unactionable rows are worse in prod than locally, and the command is dry-run-first |

## Sequencing

1. **A1–A5** (independent of each other; A2+A3 are one change to `toClassifiedSections()`),
   each PR: failing test → fix → `pint --dirty` → `composer phpstan` → `test --compact
   --parallel` → `dusk`.
2. **B1–B3** after A merges. B1/B2 need OD2/OD3.
3. **C1–C6** any time (pure UI, no predicate dependency), but C2 lands best after A1 so
   "Confirm" never has to explain un-dismissable songs.
4. Ideally all of A + B before the promotion soak's Stage 3 review begins; if the soak is
   already running, note the flag-semantics change date in the soak scorecard
   (`llm-structure-promotion-soak.md` §5.2) so per-run "flags raised" columns stay
   comparable.

## Acceptance criteria

- A clean corpus service (e.g. reprocessed 2026-05-03) produces **0 inbox items** apart from
  genuinely actionable ones (unmatched songs, reading-reference conflicts, sermon-segment
  confirmations); the 3 May run's expected count is 1 (the Psalm 113/130 reading flag), not 8.
- The inbox on this database drops from 222 + 79 to under ~30 + ~12 without a single flag
  being lost that any operator would have acted on (spot-check list: reading conflicts,
  unmatched songs, ambiguous sermon detections, pending merges, parse-failure services).
- An operator can empty the queue: every remaining item has a visible one-click resolution.
- No sermon-extraction behaviour change: `SermonAutoExtractionPolicy` tests untouched and
  passing.
- Public song pages begin counting LLM-path confirmed matches (deliberate change, called out
  in the A1 PR).
- 401/401 `canonical_conflict_state='detected'` becomes ~0 `detected` on freshly-imported
  services (A5) and post-backfill (B2) only genuinely-changed services carry it.
