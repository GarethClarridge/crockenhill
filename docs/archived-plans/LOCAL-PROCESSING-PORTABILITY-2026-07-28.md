# Local Processing Portability Plan

> **Archived 2026-07-29 — superseded before implementation by**
> [`R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md`](../plans/R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md).
> The replacement keeps the local-processing goal but transports normalized source assertions and
> reviewed canonical revisions rather than copying the per-row parse cache. The historic-video
> dependency remains owned by `HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md`.

> **Status (2026-07-28): drafted, not started.** Written after a review of the
> [R8 data convergence runbook](../operations/r8-data-convergence-runbook.md) found that the
> convergence re-runs in production the AI work local has already paid for.
>
> **Goal, in the maintainer's words:** *"we're doing it locally first then syncing with prod so we
> can take advantage of free local processing; and so that we don't accidentally break prod."*
> Today the runbook delivers the second half in full and the first half only for legacy MP3
> sermons. This plan makes "process locally, ship the result" true of the remaining two kinds of
> expensive work: **AI extraction of the historic order-of-service archive**, and **historic video
> processing**.
>
> **Agents must not, without maintainer input:** (a) run any command against production;
> (b) start WP2 — it is a dependency statement, not a work package to implement here; the video half
> belongs to [HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md](../plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
> and must not be re-designed in parallel; (c) widen `SermonPromotionBundleExporter` in place — it
> is a marked R8 one-shot (that plan's §2.1 explains why it is copied, not widened).

---

## 1. Why this exists

Two things are true at once:

- Local is where processing is free. Whisper runs natively on the host, the OoS extractor bills to
  the same key either way but only needs to run once, and a mistake here costs nothing.
- The R8 runbook's model is *re-import the same sources in production through the same commands*.
  That is a rehearsal model. It proves the commands and the operator's decisions, and it does not
  ship a single derived result.

The consequence is not just cost. A production extractor run can return a **different plan** from
the one the operator reviewed locally, because the model is not deterministic — so the local review
is not evidence about what production will hold. The same applies to a re-transcribed video: the
sections, the sermon boundaries and the song matches are all re-derived.

For legacy MP3 sermons this was already solved: Phase 6 of the runbook ships a create-only
promotion bundle and production writes rows without touching the audio. This plan applies that
shape to the other two.

### What is portable today

| Work | Where it happens now | Portable form exists? |
|---|---|---|
| OpenLP `.osz` parsing | Both; deterministic | Not needed — same input, same output |
| Legacy MP3 sermons | Local only, shipped as a bundle | **Yes** — `sermons:export-promotion-bundle` / `sermons:import-promotion-bundle` |
| Markdown OoS extraction | Both; extractor runs again in production | **No** — WP1 below |
| Historic video | Both; re-processed in production | **No** — WP2, owned by the historic-archive plan |

---

## 2. WP1 — Ship the reviewed OoS parses

### 2.1 What already exists to build on

`ImportOosArchiveCommand` already caches parses and already knows how to decide whether a cache is
usable:

```php
// ImportOosArchiveCommand::parseResult()
$cacheMatches = ! (bool) $this->option('fresh-parse')
    && is_array($parsing)
    && ($parsing['input_hash'] ?? null) === $entry->inputHash
    && ($parsing['parser_version'] ?? null) === self::PARSER_VERSION;
```

The cache lives in `inbound_emails.processing_metadata.parsing`, written by
`InboundEmailImportService::storeParseResult()` and read back by `storedParseResult()`. That pair is
the seam: `storedParseResult()` already reconstitutes a complete `OosEmailParseResult` from a plain
array, including the validator's disposition, `validation_reasons` and `consensus`.

Two properties make this genuinely portable, and both should be asserted rather than assumed:

- **`input_hash` is derived from the archive entry's own content**, not from a local row id, so the
  same markdown produces the same key in both environments.
- **`PARSER_VERSION`** already gates reuse, so a cache produced by an older pipeline shape is
  ignored rather than silently trusted.

### 2.2 Shape

Options on the existing command, not a new command pair. `ImportOosArchiveCommand` is a marked
one-shot scheduled for deletion once the archive is declared final (R8 "Remaining non-data
checks"), and options added to it die with it — where a new command pair would outlive its reason
to exist.

```text
oos:import-archive <path> --parse-cache-out=<private-json-path>
oos:import-archive <path> --parse-cache=<private-json-path> [--import]
```

- `--parse-cache-out` writes every entry that has a usable cached parse: entry key,
  `input_hash`, `parser_version`, and the stored `parsing` blob verbatim.
- `--parse-cache` seeds `processing_metadata.parsing` for a matching entry **before** the existing
  cache check runs, so the current `$cacheMatches` logic is what decides to reuse it. No second
  code path for "was this cached locally or here" — there is one rule and the file feeds it.

### 2.3 Rules the import side must hold to

1. **A mismatch is loud, not silent.** An entry whose `input_hash` or `parser_version` disagrees
   with the file is re-parsed *and reported* — the run must end with a count of how many entries
   were served from the shipped cache and how many were extracted afresh. Silently paying for 101
   model calls is exactly the failure this work package exists to prevent.
2. **Shipped parses are still validated.** The blob is model output that crossed an environment
   boundary. It carries a stored `disposition`, but the structural validation
   (`OosEmailExtractionValidator`, source-line grounding) must re-run against the production entry
   rather than being taken on trust from the file. **Open decision:** whether a shipped parse that
   fails validation in production is held for review or hard-fails the run. Held-for-review is the
   better default — it is what a locally-failing parse already does — but it means the operator can
   receive review work for an entry they already reviewed locally, so the maintainer should choose.
3. **`--parse-cache` never implies `--import`.** Seeding the cache and releasing entries into the
   inbox stay separate, exactly as evaluation and import are separate today.
4. **The file is private.** It contains subjects, plan text and unmatched titles — the same
   sensitivity as an `oos:import-archive` report. It goes in `storage/scratch/`, is never
   committed, and is transferred by the same private path the runbook uses for the sermon bundle.
5. **Idempotency is unchanged.** A second `--import` run must still report `merged` for every entry
   it imported the first time, whether or not the cache file was used.

### 2.4 Tests

- an export/import round trip serves every entry from the file and calls the extractor **zero**
  times (bind a parser double that fails the test if invoked);
- a `PARSER_VERSION` mismatch in the file re-parses that entry and reports it as re-parsed;
- an `input_hash` mismatch does the same;
- a shipped parse still runs structural validation, and a failing one takes the decided route;
- the import remains idempotent across two `--import` runs with the cache file supplied;
- the export omits local row ids, and the round trip works against a database where the synthetic
  `InboundEmail` rows have different primary keys.

### 2.5 Runbook changes this unlocks

- The R8 "What local rehearsal does and does not buy" table's third row moves from "extractor runs
  again in production" to the shipped-result column.
- §5.4's production sequence gains the transfer + `--parse-cache` step, and the extractor preflight
  (`--date=YYYY-MM-DD`) becomes a check that the *cache* is being used rather than a check that the
  API key works.
- The hard stop *"The OoS extractor model/key, API quota or network access has not been confirmed
  for the 101-entry evaluation"* narrows to the entries not covered by the shipped cache.

---

## 3. WP2 — Historic video: a dependency, not new work here

The video half is **already designed** in
[HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md](../plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md).
Its Stage B (WP2–WP7) is precisely "process locally, promote the result to production as a
create-only bundle", built by copying the sermon promotion pair as a template. Its §2.3 establishes
the key fact that makes it work: `LivestreamChurchServiceProjectionService::project()` reads only
the processing log, its `service_sections` and `processing_metadata` — no media — so the service
graph can be rebuilt in production from portable data.

**Nothing in this plan re-designs that.** What this plan adds is the reconciliation that was
missing:

1. **The R8 runbook contradicts it.** R8 §6.4/§6.5 stage historic video source files to production
   and run `sermons:import-historic-videos` there — a second full transcription and detection pass.
   That is the opposite of Stage B. Until Stage B lands, R8 §6.5 remains the fallback and should say
   so explicitly; once Stage B lands, R8 §6.5 is deleted and points at it.
2. **Stage B is gated.** The historic-archive plan's Stage A0 (WP-A1 – WP-A6, artifact durability)
   must land first, because today's pipeline deletes artifacts it cannot re-derive once the drive is
   unmounted. No amount of promotion tooling recovers a transcript that was swept 24 hours after the
   run.
3. **Order between the two plans.** WP1 here is independent of all of that and can be done at any
   time. It is also much smaller. Do it first.

---

## 4. Sequencing

| Step | Work | Depends on |
|---|---|---|
| 1 | WP1 — OoS parse cache export/import + tests | nothing |
| 2 | R8 runbook amendments from §2.5 | step 1 |
| 3 | Historic-archive Stage A0 (WP-A1 – WP-A6) | that plan |
| 4 | Historic-archive Stage B (WP2 – WP7) | step 3 |
| 5 | Delete R8 §6.4/§6.5 in favour of Stage B | step 4 |

## 5. What this plan deliberately does not do

- **It does not make production deterministic in general.** It ships the specific derived results
  that are expensive and already reviewed. A future live email still gets parsed in production, as
  it should.
- **It does not add a general-purpose environment sync.** The R8 runbook's position stands: no
  bidirectional database sync, no restoring a production backup locally, portable domain bundles
  only.
- **It does not widen the sermon promotion exporter.** Historic video gets its own bundle type.
