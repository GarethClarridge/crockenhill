# OoS Archive Import: Evaluation Harness, Pipeline Fixes, Conservative Backfill

**Date:** 2026-07-10
**Status:** Approved, not started
**Priority steer from Gareth:** learning for future imports > backfill. Sequencing decision (explicit): fix the *known* bugs first, then run one evaluation pass so every miss it reports is a genuinely new finding — do **not** run a baseline eval just to measure already-confirmed bugs.

## Context

We have `storage/scratch/crockenhill_orders_of_service_archive.md` — 102 order-of-service emails (2022–2026) compiled from Gmail into markdown (4,896 lines). Each entry has a `### <date heading>` (ground-truth date), a `**Source subject:**` line, and `####` sections (~44 heading variants: notices / morning order / evening order / specials). Most entries contain **both** morning and evening orders, mirroring the real weekly emails. The file ends with a `## Known gaps and attachment-only items` section (not entries).

The existing pipeline: `InboundEmail` → `app/Jobs/ProcessInboundOosEmail.php` → `app/Services/Email/OosEmailParserService.php` (regex date/service extraction + LLM item extraction via `app/Services/Email/OpenAiOosEmailItemExtractor.php`, gpt-4.1-nano) → `app/Services/Email/InboundEmailImportService.php` (create or merge `ChurchService` + items). Entry points today: Mailgun webhook (never configured in prod) and the admin paste form (`app/Livewire/Admin/ChurchServices/SubmitEmailText.php`). Confidence thresholds in `config/service-tracking.php`: review 0.75, auto-import 0.90.

### Confirmed findings driving this plan

1. **Single-service-per-email gap.** `OosEmailParserService::extractService()` (around line 144) returns the *first* morning/evening keyword match — one service per email — and the LLM extractor prompt asks for a single ordered list. Every real weekly email contains both services, so evening orders are never imported and morning imports can absorb evening items. **Decision (Gareth): fix the pipeline properly** (per-service extraction end-to-end), not a harness-only workaround.
2. **Numeric-date overflow bug (verified empirically).** A subject/body like `"1 Timothy 2:11-15"` matches the numeric-date regex in `extractNumericDate()` (around line 272) as day=11/month=15, and `CarbonImmutable::create(2026, 15, 11)` silently **overflows to 2027-03-11** rather than throwing — a confidently-wrong date (0.80–0.84 confidence) whenever the subject lacks a real date.
3. **Merge would degrade openlp data.** The local DB has 395 `openlp`-sourced `church_services` (2021-10-24..2026-07-05) colliding with most archive dates. `StructureMergePolicy::requiresMergePlanning()` only protects *livestream*-derived items, so an email import into an openlp service does a direct sync that overwrites items (actual projection data) with plan data and flips `source` to `email`. **Decision (Gareth): create-only backfill** — only fill missing date+service slots; a `--merge-existing` flag exists but is off by default and forces `needs_review`.

Environment: `OPENAI_API_KEY` present in local `.env`; extraction model defaults to gpt-4.1-nano (~102 calls ≈ pennies). Eval runs use real OpenAI, cached in `InboundEmail.processing_metadata['parsing']` so re-runs don't re-call.

## Phase 1 — Archive splitter (pure, unit-testable)

**New:** `app/Services/Email/OosArchiveMarkdownParser.php`, `app/Data/OosArchiveEntry.php`

Parse the archive into 102 DTOs; stop at `## Known gaps and attachment-only items` (capture bullets as `knownGaps`, not entries). Per entry:

- `groundTruthDate` (`?CarbonImmutable`): from the `###` heading. Handle suffixes (`AM`, `— CBC 225th Anniversary`), bracketed corrections (`[email title likely intended 15 February]` → flag), specials (`Easter Sunday {year}` via Easter calculation, `Christmas Morning {year}` → 25 Dec; unresolvable e.g. `Carols by Candlelight 2024` → null date + flag, excluded from date-accuracy metrics).
- `subject`: verbatim from the `**Source subject:**` line (including `Fwd:`/`Re:`).
- `bodyPlain`: reconstructed email body — `####` heading text kept as a plain line, bullets kept, `**…**` emphasis stripped, and the `###` heading + Source-subject line **excluded** so no ground truth leaks into parser input.
- `sections`: classify the ~44 `####` variants with case-insensitive keyword rules (morning / evening (incl. carols) / notices / other) → `servicesPresent` + per-service ground-truth item-line counts.
- `flags`: revised/original pair (15 March 2026 appears twice), morning-only, date-discrepancy annotations.
- Deterministic `syntheticMessageId` = `<oos-archive-{sha1_12(heading . subject)}@crockenhill.local>` — idempotent re-runs, distinct for the revised/original pair.
- `syntheticReceivedAt` = ground-truth Sunday −2 days at 09:00 Europe/London (realistically exercises the ±6-month year inference in `resolveYear()`).

## Phase 2 — Harness command

**New:** `app/Console/Commands/ImportOosArchiveCommand.php` (via `sail artisan make:command`), signature:

```
oos:import-archive {path?}
  {--dry-run}          split + validate only, no DB writes, no LLM calls
  {--import}           write to church_services (default: parse + evaluate only)
  {--merge-existing}   allow merge into existing services (forces needs_review)
  {--fresh-parse}      re-parse even when a stored parse exists
  {--limit=} {--date=*} {--from=} {--to=}
  {--report=}          default storage/scratch/oos_archive_eval_{Ymd_His}.json
```

Per entry, synchronously (no queue):

1. `InboundEmail::firstOrCreate` by `syntheticMessageId`; store ground truth under `processing_metadata['archive']` (heading, date, services present, item counts, flags, entry index) so evaluation is re-runnable from the DB alone.
2. Parse with caching: reuse `InboundEmailImportService::storedParseResult()` unless `--fresh-parse`; else `OosEmailParserService::parse()` + `storeParseResult()`. Wrap each entry in try/catch, record failures in metadata, continue → resumable and idempotent.
3. With `--import`: **create-only** — if a `ChurchService` exists for date+service, skip and record `skipped_existing` (+ existing source); gap slots go through the untouched `InboundEmailImportService::import()`. Archive order + create-only naturally keeps the revised 15 March entry and skips the original.
4. Emit console table + JSON report (Phase 3).

## Phase 3 — Evaluation report

**New:** `app/Services/Email/OosArchiveEvaluator.php` (separate class for testability; called by the command).

Per entry: ground-truth vs extracted date (match + extraction method), services present vs detected, extracted vs ground-truth item counts per service, confidence score, disposition (auto-import ≥0.90 / needs-review 0.75–0.90 / would-not-import), prospective song-link hit rate via `Song::canonicalizeKey` (no import needed; also quantifies that hymn-book numbers in titles — `546 'God has spoken…'` — are unused by `ChurchServiceSongLinker`, itself a finding).

Aggregates: date accuracy %, extraction-method histogram, false-date cases, evening coverage %, confidence distribution, disposition counts, song-link hit rate. Output: console table + JSON in `storage/scratch/` (operational artifact, not docs).

## Phase 4 — Fix the known bugs first (test-first)

Both defects are already confirmed, so fix them *before* spending LLM calls on an evaluation run — the eval's job is to surface **unknown** issues.

1. **Numeric-date overflow**: regression test with `"1 Timothy 2:11-15"` in the existing `tests/Integration/Services/OosEmailParserServiceTest.php`, then bounds-check month ≤ 12 / day ≤ 31 in `extractNumericDate()` before `safeDate()`.
2. **Multi-service pipeline fix** (Phase 5) — done now, ahead of any archive run.

## Phase 5 — Multi-service pipeline fix (approved)

- `OpenAiOosEmailItemExtractor`: schema/prompt → `{"services":[{"service":"morning|evening|unknown","items":[{type,title}]}],"confidence","notes"}`. The LLM does the splitting (44 heading variants rule out regex). Extend `app/Data/OosEmailItemExtractionResult` with `services`, keep the flattened `items` for compatibility.
- `app/Data/OosEmailParseResult` gains `servicePlans: OosEmailServicePlan[]` (new DTO: service, items, per-service confidence); legacy single-service fields stay populated (morning-first) so `storedParseResult()` round-trips old stored metadata.
- `OosEmailParserService::extractService()` becomes corroborating evidence per service rather than winner-takes-all.
- `InboundEmailImportService::import()` loops service plans (create/merge each); records `imported_church_service_ids` (plural) alongside the existing singular key.
- Update `ProcessInboundOosEmail`, `app/Actions/InboundEmail/ApproveInboundEmailImport.php`, `ReparseInboundEmail.php`, `app/Actions/PrefillChurchServiceFromInboundEmail.php`, and `app/Livewire/Admin/ChurchServices/ReviewInbox.php` + blade partials — **read these first**; the review UI is the riskiest surface; keep legacy single-service rendering working for already-stored parses.

## Phase 6 — Evaluation run on the fixed pipeline, iterate, then backfill

1. `vendor/bin/sail artisan oos:import-archive` (parse + eval only, real LLM, cached) → report. With known bugs fixed, every miss is a *new* finding (year inference, month-name false hits in notices, LLM extraction quality, unknown-service sections like "Carols", etc.).
2. For each new finding worth fixing: failing test first, fix, re-run affected entries with `--fresh-parse --date=...` (cheap, targeted — not a full re-parse).
3. When the report looks sane: `vendor/bin/sail artisan oos:import-archive --import` — create-only fills missing date+service slots (mostly evening services and gap Sundays). Never merges over the 395 openlp services. Local first; the command is environment-agnostic for a later prod run.

## Phase 7 — Tests (PHPUnit; reuse `tests/Traits/WithInboundEmailTestHelpers.php::bindExtractor()` — verified present)

- `tests/Unit/Services/OosArchiveMarkdownParserTest.php` — entry splitting, dates incl. specials/annotations, body reconstruction (assert no `###`/`####`/`**` leakage), section classification/counts, deterministic message ids, same-date pair, known-gaps exclusion. Use fixture snippets, not the full archive.
- `tests/Feature/Console/ImportOosArchiveCommandTest.php` — small fixture archive + stub extractor: dry-run writes nothing; idempotent parse (second run: no new rows, no extractor call without `--fresh-parse`); `--import` skips an existing openlp service and creates a gap slot; `--limit`/`--date` filters; report file written with expected keys; per-entry failure doesn't abort the run.
- Parser regressions (Phase 4) in the existing `tests/Integration/Services/OosEmailParserServiceTest.php`.
- Multi-service (Phase 5): two-service extraction → two `ChurchService` rows via the job and via `ApproveInboundEmailImport`; `storedParseResult()` round-trip of both new and legacy metadata shapes; ReviewInbox renders both plans.

## Reused untouched vs modified

- **Untouched**: `ChurchServiceStructureMergeService`, `StructureMergePolicy`, `ChurchServiceItemSyncService`, `ChurchServiceSongLinker`, `ChurchServiceCanonicalUpdateService`, `InboundEmail` model, Mailgun controller, `SubmitEmailText`.
- **Modified**: `OosEmailParserService` (date bounds, service corroboration), `OpenAiOosEmailItemExtractor`, `OosEmailItemExtractionResult`, `OosEmailParseResult` (+ new `OosEmailServicePlan` DTO), `InboundEmailImportService`, `ProcessInboundOosEmail`, `ApproveInboundEmailImport`, `ReparseInboundEmail`, `PrefillChurchServiceFromInboundEmail`, `ReviewInbox` + views.
- **New**: `OosArchiveMarkdownParser`, `OosArchiveEntry`, `OosArchiveEvaluator`, `ImportOosArchiveCommand`, tests above.

## Verification

1. `--dry-run`: 102 entries, all dates resolved or flagged, section classification sane.
2. Eval run on the fixed pipeline (real LLM, cached) → JSON report in `storage/scratch/`; metrics: date accuracy, evening coverage, item counts vs ground truth, confidence dispositions, song-link hit rate.
3. Targeted `--fresh-parse --date=...` re-runs after any additional fixes the report surfaces.
4. `--import` on local: assert only gap slots created (DB query: no openlp row's `source` flipped), spot-check a created evening service in the admin UI.
5. Findings summary delivered in conversation (no additional doc file).
6. Gates: `vendor/bin/sail bin pint --dirty`, `vendor/bin/sail composer phpstan`, `vendor/bin/sail artisan test --compact --parallel` (with `tee`), `vendor/bin/sail artisan dusk`.
