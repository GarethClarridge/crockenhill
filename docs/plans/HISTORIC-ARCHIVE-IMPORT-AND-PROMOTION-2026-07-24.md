# Historic Archive Import & Promotion Plan

> **Status (2026-07-24): drafted, not started. Stage A (local import) now carries a small
> prerequisite workstream — WP-A1 – WP-A6, artifact durability — which must land *before* the first
> batch, because the drive is a one-shot resource and today's pipeline deletes several artifacts it
> cannot re-derive once the drive is unmounted (§2.5). Stage B (promotion to production) is all new
> code and is where the bulk of this plan's work lives.**
>
> **Goal, in the maintainer's words:** *"get production to the state it would be if [the historic
> videos] were all processed using today's code"* — both the sermon archive **and** the service
> structure that feeds the public song usage/catalogue pages.
>
> **This is a one-shot, irreversible-in-practice run.** Re-importing means remounting the CBC drive
> and paying the compute again. Every decision about *what to keep* has to be made before the first
> batch, not after — see §2.5 for what is currently discarded and §6 for the pre-flight checklist.
>
> **Agents must not, without maintainer input:** (a) run any command against production;
> (b) start Stage A before WP0 and WP-A1 – WP-A6 have landed (§5, §6);
> (c) start Stage B WP3+ before the WP1 song-usage baseline has been captured from production;
> (d) widen `SermonPromotionBundleExporter`'s eligibility guard in place — it is a marked
> R8 one-shot scheduled for deletion (see §2.1). ~~(e) promote children's talks before **either**
> WP8 or the private-storage move lands~~ — **no longer applies, see below.**
>
> **WP8 is CANCELLED — the storage plan landed, 2026-07-25.**
> [CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md](../archived-plans/CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md)
> is complete and archived. The `private/` prefix no longer exists anywhere in the application:
> children's-talk assets are on the ordinary sermon disk under ordinary sermon keys, and
> `MoveSermonToPrivateStorage`, the observer hook, the `childrens_talk_public` audit finding and
> `SermonPromotionAssets::guardPortablePath()`'s `private/` clause have all been deleted. **A
> children's talk is now indistinguishable from a sermon for promotion purposes**, so §2.6 and WP8
> below are dead text — retained only to explain why the WP numbering skips one. Constraint (e) is
> discharged: there is nothing to move and no dangling-path hazard. The login gate is unchanged
> (`CHILDRENS_TALKS_PUBLIC` is still `false`), so promoted talks stay members-only exactly as before.
>
> **All open questions are answered as of 2026-07-24. No item in this plan is blocked on the
> maintainer.**
>
> - **Q1 — merge as normal.** When a livestream run is added to a service that already holds
>   order-of-service items, projection merges rather than skips. See §3.2 for the (small) code
>   change this requires and §3.3 for its blast radius. → WP0.
> - **Q2 — strictly zero-loss.** No loss of currently-public song usage is acceptable during
>   promotion. A service that would drop even one qualifying song item rolls back and is reported
>   for manual handling. → WP2's acceptance test, WP4 step 7.
> - **Q3 — children's talks are promoted too.** Not sermons only. This was the most expensive answer
>   in the set when it was given, because children's-talk media was moved to **private local**
>   storage during the local import and deleted from the bucket — so §2.2's "promotion is a
>   database-row operation only" did not hold for them, and they needed a file-transfer workstream
>   (WP8, §2.6). **That cost was removed on 2026-07-25**: the children's-talk storage plan deleted
>   private storage, so their media stays in the bucket and §2.2 holds for them after all. **Q3 is
>   now the cheapest answer in the set** — a children's talk promotes as an ordinary sermon.
>   → WP3 scope only.
> - **Q4 — review locally first, promote once reviewed.** Promoted services therefore arrive in
>   production **already `reviewed`**, carrying the local review timestamp; they do not enter the
>   production review inbox. WP3 already refuses to export an unreviewed service, so review-then-
>   promote is enforced at the exporter, not by operator discipline. → WP5.
> - **Q5 — production uses `do_spaces` for thumbnails.** So WP6 takes **Strategy A**: set
>   `THUMBNAIL_STORAGE_DISK=do_spaces` locally *before* Stage A begins, and thumbnails travel like
>   audio and video with no production regeneration. → WP6, §6.1 item 3.
> - **Q6 — 32 kbps is fine.** WP-A6 archives the mono MP3 the pipeline already produces, with no
>   second encode. ~10 GB across the archive, one upload, no extra ffmpeg pass. The trade-off
>   accepted with this: full-service audio is a *reprocessing input*, not publishable master audio,
>   so congregational singing cannot later be published from the archive. → WP-A6.

---

## 1. Shape of the work

Two stages, with very different characters.

| Stage | What | New code? | Who runs it |
|---|---|---|---|
| **A0** | Make the import keep what it cannot re-derive (§2.5) | **Small** — WP-A1 – WP-A6, all pipeline changes | Agent |
| **A** | Import historic videos locally, review each service | **None** — `sermons:import-historic-videos` already does this | Operator (maintainer) |
| **B** | Promote reviewed services from local → production | **All of it** | Operator, using new commands |

The import mechanism itself is already solved; §6 is its runbook. What is *not* solved is
retention: the pipeline is built for recordings that stay available, and this one's do not (§2.5).
Stage A0 closes that gap and is the only part of this plan that genuinely cannot be done later.

Stage B is a database-row operation for sermons and a genuine file transfer for children's talks —
Q3 put the latter in scope, and §2.6 explains why that is the plan's one unavoidable exception to
"the media is already in the right bucket".

---

## 2. Current state (evidence)

### 2.1 The existing promotion pair is the right mechanism with the wrong filter

`app/Services/Sermon/SermonPromotionBundleExporter.php` + `SermonPromotionBundleImporter.php`
implement a genuinely good promotion primitive:

- portable natural keys — date + service, preacher by name/slug, no local IDs cross the boundary
- preflight classification into `already_present` / `create` / `conflict`
  (`SermonPromotionBundleImporter.php:139`)
- re-check before apply — *"Promotion preflight changed before apply; no records were committed"*
  (`SermonPromotionBundleImporter.php:66`)
- slug-collision detection (`:205`), create-only semantics, transactional apply

But it hard-rejects everything this plan needs to move:

```php
// SermonPromotionBundleExporter.php:87
if ($sermon->source_type !== SermonSourceType::AudioUpload || blank($sermon->audio_file_path)) {
    throw new RuntimeException("Sermon {$sermon->id} is not an eligible legacy audio-upload record.");
}
// SermonPromotionBundleExporter.php:149
if ($processingLog->processing_type !== MediaType::Audio) { … }
```

Historic imports are `SourceType::Livestream` / `MediaType::Livestream`. The class is also docblocked
*"Temporary R8 one-shot. Delete this command and its tests after the R8 ledger has no
unresolved/promote entries"* — so it must be **copied as a template, not widened in place**.

`sermons:generate-prod-patch` is ruled out: it parses a prod SQL dump, emits raw SQL, and only
touches six update-able fields (`GenerateProdSermonPatchCommand.php:21`). Wrong tool.

### 2.2 Media is already in production storage

`.env:94` sets `SERMON_STORAGE_DISK=do_spaces` with `DO_SPACES_BUCKET=crockenhill` — the
production bucket. `ImportHistoricVideoBatchCommand::checkStorageDisk()` actively refuses to run
on `local` storage.

**Consequence: for sermons, promotion is a database-row operation only.** Video, audio, transcripts
and (once Q5's Strategy A is applied — §2.4, WP6) thumbnails are already in the right bucket the
moment the local import completes. The corollary is that rejected/abandoned local imports leave
orphan objects in the live bucket — `audit:sermon-assets` surfaces these and cleanup is a WP7 chore.

~~**This does not hold for children's talks, which Q3 puts in scope.**~~ **It holds for them too, as
of 2026-07-25.** Their media used to be moved *out* of the bucket during the local import and
deleted from it (§2.6 is the evidence, WP8 was the fix); the children's-talk storage plan removed
that behaviour entirely. **"No file transfer is in scope" is now unqualified** — there is no
"except children's talks" anywhere in this plan.

### 2.3 Production can rebuild the service graph natively

`LivestreamChurchServiceProjectionService::project(MediaProcessingLog)` already does the entire
Tier-2 job from a processing log plus its `service_sections`:

- `findMatchingService()` (`:222`) finds an existing `church_services` row by date + service
- otherwise creates one (`:116`)
- `ChurchServiceItemSyncService::sync()` merges items with mature cross-source rules
  (stable match, position fallback, normalised song-title match, OpenLP metadata preservation)
- `linkSectionsToItems()` (`:243`) sets `service_sections.church_service_item_id`
- `linkProcessingLogToService()` (`:369`) sets `media_processing_logs.church_service_id`

Critically, `project()` reads **only** the log, its sections, and `processing_metadata`. It needs
no media files. So it can be replayed in production.

### 2.4 Not every asset the local import produces reaches DO Spaces

Disk selection is per-asset-kind, and three different config keys are involved. ~~`MediaAssetPath::diskForPath()`
adds one more rule: any path beginning `private/` resolves to the **local** disk regardless.~~

> **Corrected 2026-07-25.** `MediaAssetPath::diskForPath()` no longer exists — it was replaced by
> `MediaAssetPath::disk()`, which takes no path, because the `private/` prefix has no routing power
> any more. The two `private/` rows in the table below are struck through accordingly, and
> consequence 4 with them. `MediaAssetPath::isPrivate()` survives, but purely as a reporting label
> for the audits. Everything else in this section is unchanged and still accurate.

| Field | Disk resolved from | Value locally | In Spaces? |
|---|---|---|---|
| `sermons.audio_file_path` | `media-processing.storage.sermon_disk` | `do_spaces` | **Yes** |
| `sermons.video_file_path` | `media-processing.storage.sermon_disk` | `do_spaces` | **Yes** |
| `sermons.transcript_file_path` | `…transcript_disk` → falls back to sermon disk | `do_spaces` | **Yes** |
| `media_processing_logs.audio_file_path` / `video_file_path` | sermon_disk | `do_spaces` | **Yes** |
| `media_processing_logs.transcript_file_path` | transcript_disk | `do_spaces` | **Yes** |
| `service_sections.extracted_video_path` / `extracted_audio_path` | `MediaAssetPath::disk()` | `do_spaces` | Yes, but **ephemeral** |
| `sermons.thumbnail_file_path` + every `thumbnail_metadata` candidate | `thumbnail-generation.storage.disk` | **`public` (local)** → `do_spaces` once §6.1 item 3 is applied | **No** → Yes |
| `media_processing_logs.source_file_path` | `…temp_disk` (hardcoded `'local'`) | local | **No** |
| `media_processing_logs.rms_log_path` | temp_disk | local | **No** |
| `media_processing_logs.enhanced_audio_file_path` | temp_disk | local | **No** |
| ~~anything under `private/` (children's talks)~~ | ~~forced `local`~~ — **no longer exists (2026-07-25)**; children's-talk assets resolve exactly like a sermon's, so they are already covered by the rows above | `do_spaces` | **Yes** |

Evidence: `DeleteLivestreamUpload::collectProcessingLogFileTargets()` (`:196–208`) is the
authoritative per-field disk map — it has to be exact to delete correctly, so it is the best
single reference. `config/media-processing.php:57` hardcodes `'temp_disk' => 'local'`.
`config/thumbnail-generation.php:7` reads `env('THUMBNAIL_STORAGE_DISK', 'public')`, and
`THUMBNAIL_STORAGE_DISK` is **not present in `.env`**.

Four consequences for the bundle:

1. **Thumbnails were a gap; Q5 closes it cheaply.** Every thumbnail and thumbnail candidate a
   historic import generates lands on the local `public` disk by default, not in Spaces — promoting
   `thumbnail_file_path` as-is would give production a path with no file behind it, across the whole
   archive. Q5 confirms production resolves thumbnails from `do_spaces`, so WP6 takes Strategy A:
   one `.env` line (`THUMBNAIL_STORAGE_DISK=do_spaces`) set **before** Stage A begins makes
   thumbnails travel like audio and video. The ordering is load-bearing — anything imported before
   that line exists keeps local paths and must be re-run or manually uploaded.
2. **Temp-disk fields must be nulled, not promoted.** `source_file_path`, `rms_log_path` and
   `enhanced_audio_file_path` are local scratch, routinely reaped by
   `media:cleanup-temp-files`. Carrying the strings across would create dangling references.
3. **Candidate section media must not be promoted.** `extracted_video_path` / `extracted_audio_path`
   are review-time preview clips governed by `unpublished_expires_at`, and
   `ServiceSectionCandidateMediaController` 404s them once the section is published. They are
   Stage-A review aids with no production role.
4. ~~**Children's-talk assets need transporting, not referencing.**~~ **CANCELLED 2026-07-25.** Q3
   still puts them in scope, but they are referenced like any other sermon asset now — they are in
   the bucket, reachable from production, and need no transport. This is what deleted WP8.

`SermonPromotionAssets` already models the right discipline for the sermon tier: `KINDS`, a
`diskFor()` matching the table above (`:151`), `manifestForSermon()` recording size + sha256 and
failing if a file is missing (`:90`), `verify()` re-checking at import, and `guardPortablePath()`
rejecting empty and unsafe paths. WP3/WP4 should reuse this class directly for **all** sermon
assets — children's talks included — and extend the same pattern to the two log fields that live in
Spaces.

~~Note the last of those guards: `guardPortablePath()` **rejects `private/` outright**~~ — **that
clause was deleted on 2026-07-25.** It was the reason Q3 could not be satisfied by reusing the
class; with it gone, reuse is exactly the right answer and no parallel children's-talk path is
needed.

### 2.5 The pipeline assumes the source recording is durable. Here, it is not.

Every temp-disk decision in §2.4 is correct for the pipeline this code was written for: a
livestream lands on the OBS machine, the file stays there, and anything derived from it can be
re-derived on demand. This import inverts that assumption. The source is a mounted drive that will
be unmounted, so **temp-disk artifacts become the only surviving record of recordings that no
longer exist.**

That reframes the retention question. It is not "which files does the pipeline need?" but "after
the drive goes away, what can we still recompute?"

| Artifact | Written to | Survives the run? | Recomputable without the drive? |
|---|---|---|---|
| Sermon audio / video | sermon disk (`do_spaces`) | Yes | — |
| Sermon transcript | transcript disk (`do_spaces`) | Yes | — |
| Sections, structure, song matches, speaker decisions | database (`processing_metadata`) | Yes | from the service transcript only |
| **Full-service transcript** | temp disk | **No — 24h** (see below) | **No** |
| **Full-service compressed audio** | temp disk | **No — unlinked immediately** | **No** |
| **RMS log** | temp disk | **No — per-run cleanup** | **No** |
| **Source video (whole service)** | temp disk | **No — per-run cleanup** | **No** |
| Enhanced sermon audio | temp disk | No | approximately, from sermon audio |
| Thumbnails | local `public` disk | Yes, but not in Spaces (§2.4) | from sermon video |
| **Which drive files fed which run** | nowhere | **No** | **No** |

Five rows are irrecoverable once the drive is gone. Each is addressed by a Stage A work package
(WP-A1 – WP-A6 in §5).

#### 2.5.1 The full-service transcript is deleted 24 hours after the run — confirmed by test

`TranscribeFullService` stores one timestamped Whisper pass over the whole recording at
`temp/service_transcript_<processing_id>.json` (`TranscribeFullService.php:196`) and records the
path in `processing_metadata['service_transcript_path']` (`:113`).

`MediaProcessingLog::temporaryFilePaths()` deliberately omits that key, and says so
(`MediaProcessingLog.php:737-740`):

```php
// service_transcript_path is deliberately absent: the full-service
// transcript is a small JSON artifact that `structure:evaluate
// --processing-id` loads after the run completes, so run cleanup must
// not delete it. Re-runs overwrite it in place (keyed by processing id).
```

So the **per-run** cleanup preserves it. The **scheduled** cleanup does not.
`media:cleanup-temp-files --hours=24` runs daily (`bootstrap/app.php:42`) and sweeps the `temp`
directory at depth 1 (`CleanupOrphanedTempFiles.php:112`) — which is exactly where the transcript
sits. Its `loadProtectedPaths()` (`:153`) protects only `source_file_path`, and only for logs in
`pending` / `started` / `processing` / manual-review states. A **completed** run's transcript is
protected by nothing.

Verified rather than inferred: a throwaway feature test creating a completed log with a stored
transcript aged 48 hours, then running `media:cleanup-temp-files --hours=24`, fails on
`assertExists` — the file is gone. Two independent reapers claim the same path prefix, and the
invariant that is supposed to save the transcript exists only as a comment in the other one.

For a normal Sunday this is a non-event: re-transcribing costs one local Whisper pass over a video
that is still on disk. For this import it is the difference between an archive that can be
reprocessed forever and one that cannot be reprocessed at all.

#### 2.5.2 Transcription already produces the archival audio, then deletes it

`LocalWhisperServiceTranscriptionService::transcribeService()` compresses the whole recording to
mono MP3 before uploading it (`:41`), via
`AudioChunkingService::compressAudioForTranscription()` — 32 kbps mono by default
(`TranscriptionAudioProfile::fallback()`, `config/media-processing.php` →
`audio_extraction.fallback_compression`). It then `unlink()`s the file in a `finally` block.

That artifact is roughly 21 MB for a 90-minute service — call it ~10 GB across the whole archive.
It is the single cheapest insurance policy available, and the pipeline already builds it on every
run. WP-A6 keeps it instead of deleting it.

#### 2.5.3 Detail is discarded at the DTO boundary

Both transcription services request `response_format=verbose_json` and then keep only
`start` / `end` / `text` per segment (`LocalWhisperServiceTranscriptionService.php:138-146`,
`OpenAiServiceTranscriptionService.php:191-194`), because that is all
`ChurchServiceTranscript` models. Whisper also returns `avg_logprob`, `no_speech_prob`,
`compression_ratio`, `temperature` and `tokens` per segment — the per-segment confidence signals
that would let a future maintainer ask "which of these 400 transcripts are unreliable?" without
re-listening to anything.

`TranscribeFullService::filterTranscript()` (`:179`) then strips prompt-echo cues and
`storeTranscript()` writes the **post-filter** result, overwriting in place. The pre-filter text is
not recoverable either.

Neither loss matters when the recording is still available. Both are permanent here.

#### 2.5.4 Concatenation provenance is written to the console and nowhere else

`HistoricVideoImporter::dispatchConcatFile()` (`:845`) hands `UnifiedMediaProcessor` a single
`UploadedFile` named `2022-01-16 morning.mkv`. The facts that it was assembled from three specific
files on the CBC drive, in that order, losslessly rather than re-encoded, survive only in the
progress line printed to the terminal. `file_hash` (`UnifiedMediaProcessor.php:249`) is computed
over the *concatenated* file, so it cannot be matched back to anything on the drive.

After the drive is unmounted there is no way to answer "where did this recording come from?",
"did we import everything from that day?", or "is this the same file we skipped as `skip-small`
last time?". WP-A4 fixes this for the price of one metadata write.

### 2.6 Children's-talk media leaves the bucket during import (Q3's real cost)

Q3's answer — promote children's talks too — looks like a scope tweak to WP3. It is not. It is the
one place in this plan where promotion genuinely cannot be a database-row operation.

The mechanism is deliberate and it runs on every children's talk automatically:

1. `SermonObserver::saved()` (`:47–53`) fires `MoveSermonToPrivateStorage` whenever a sermon is a
   children's talk, its protected media changed (**including `wasRecentlyCreated`**), and
   `hasNonPrivateProtectedAsset()` finds any path not already under `private/`.
2. `MoveSermonToPrivateStorage::copyAndVerify()` (`:242–245`) reads from the *sermon/transcript/
   thumbnail* disk and writes to a **hardcoded `Storage::disk('local')`** — the local filesystem of
   whichever machine ran the job. It rewrites the row's paths to `private/…` via
   compare-and-set (`:302`).
3. `deleteScheduledSources()` then **deletes the source object from the shared disk**, once every
   path commit has been verified.

So after a local historic import of a children's talk, its audio, video, transcript, thumbnail and
every thumbnail candidate exist **only** on the import machine's `storage/app/private/…`, and the
DO Spaces copies have been removed. Nothing is left in the bucket for production to point at.

Two guards make sure this cannot be papered over:

- `SermonPromotionAssets::guardPortablePath()` (`:167`) throws on any `private/` path — the
  manifest/verify machinery WP3 and WP4 were going to lean on refuses these assets by design.
- `audit:sermon-assets` (`AuditSermonAssetsCommand.php:151`) *fails* on a children's talk whose
  asset is not private (`childrens_talk_public`), and resolves private paths against the `local`
  disk (`:158`). So "just promote them as public paths" is not available either — the audit exists
  precisely to stop that.

**A cheaper resolution exists and should be preferred.** All of the above is a consequence of
children's-talk assets being private at all.
[CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md](../archived-plans/CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md)
**was redesigned on 2026-07-25** and no longer introduces a private Spaces disk: it strips the
`private/` prefix and puts children's-talk assets on the ordinary sermon disk under ordinary sermon
keys, deleting the prefix rule, the mover job, the `childrens_talk_public` audit finding and
`SermonPromotionAssets::guardPortablePath()`'s `private/` clause along with it. The login gate stays
(`CHILDRENS_TALKS_PUBLIC` is untouched) — only the storage location changes.

**Every one of the three blockers above is removed by that plan, not worked around.** A children's
talk becomes indistinguishable from a sermon for promotion purposes, so this entire section stops
applying and WP8 does not need to exist.

> **That plan landed and was archived on 2026-07-25, so everything from here to the end of §2.6 is
> dead text.** It is retained because it explains why the work-package numbering skips WP8. Do not
> implement any of it: `MoveSermonToPrivateStorage` and the `SermonObserver` hook it describes have
> both been deleted, and no path in the application begins with `private/`.

The resolution is that production's own move job is the transport. Restore the files to their
pre-private keys in Spaces, promote the sermon row with **non-private** paths, and production's
`SermonObserver` fires `MoveSermonToPrivateStorage` on creation — which pulls each asset down to
production's local private disk, rewrites the paths, and deletes the staging object. Production
ends up in exactly the state it would reach had it processed the talk itself, using its own code,
and `audit:sermon-assets` passes afterwards. WP8 builds this; the ordering constraint (upload
before promote, or the job throws `Private-media source is missing` and burns its three retries) is
the whole reason it is a work package rather than a footnote.

---

## 3. The risks that shape the design

### 3.1 Headline risk: promotion can *delete* currently-public song usage

`PublicSongUsageService::baseQualifyingUsageItemsQuery()` (`:80–117`, mirrored in
`PublicSongCatalogService:185`) encodes the **Phase 6.1 policy**. A `church_service_items` row of
type `songs` is publicly counted if **either**:

- **(a)** a *completed livestream log* exists for the service **and** a `service_sections` row with
  `section_type = song`, `song_match_type = confirmed` links to that item; **or**
- **(b)** **no** completed livestream log exists for the service at all — OoS/planned items are
  eligible by default.

Read those two branches together and the failure mode is stark:

> Attaching a completed livestream log to a service **switches that service from branch (b) to
> branch (a)**. If the section→item links do not also land correctly, the service satisfies
> *neither* branch, and every song it contains **silently disappears from public song usage**.

So a botched promotion does not merely fail to add historic song data — it removes song data that
is public today. This is a live-data regression vector and it is why WP1 (baseline capture) must
precede any production write, and why WP2's gate is a strict pre/post diff.

### 3.2 OoS-backed dates: why today's code skips them, and what merging changes

`project()` deliberately refuses to project items into a service that already holds
non-livestream items:

```php
// LivestreamChurchServiceProjectionService.php:88
if ($churchService !== null && $this->hasNonLivestreamItems($churchService)) {
    $this->persistStructureContent($churchService, $structureContent);
    $this->linkProcessingLogToService($processingLog, $churchService);   // ← log IS attached
    $this->reviewSynchronizer->openReviewFromSections($churchService, $sections);
    return $this->skipped('Matching service contains non-livestream items; skipping projection', …);
}
```

Note it attaches the log but skips `linkSectionsToItems()`. That is **exactly the §3.1 trigger**:
branch (b) stops applying, branch (a) cannot apply, song usage goes dark for that date.

In the live pipeline there is a repair path — `ReconcileServiceSections` re-dispatches
`DetectServiceStructure` when `hasStoredServiceTranscript()` is true (`ReconcileServiceSections.php:64`).
The transcript lives on the sermon disk, i.e. the production bucket, so this path *is* available
in production. But relying on it means **re-running LLM structure detection in production**, which
(i) costs money per service, and (ii) may produce a different structure from the one reviewed
locally — defeating the point of reviewing.

**Design decision:** transplant the reviewed section→item linkage explicitly rather than
re-deriving it in production. The locally-reviewed state is the ground truth being promoted;
re-detection would discard it. This makes promotion deterministic and diffable, and removes the
production LLM dependency entirely.

**Q1's answer (merge as normal) resolves the §3.1 exposure at source**, because merging is what
makes the section→item links exist. The change needed is small — narrow or remove the early
return quoted above so `projectItems()` runs for OoS-backed services. The machinery behind it is
already built for exactly this case:

- `shouldDeleteUnmatchedItem()` (`ChurchServiceItemSyncService.php:433`) contains
  `if ($incomingSource->isDetected() && ! $existingSource->isDetected()) { return false; }` — a
  livestream (detected) sync **never deletes** OoS, email or manual items. For song-type items it
  returns `$replaceMode`, which projection leaves at its `false` default.
- `shouldPreserveOpenLpSongMetadata()` keeps the curated song identity — `song_id`, titles,
  `openlp_search_title` — when livestream data merges over an OpenLP item.
- `findStableMatch()` plus `shouldUseCrossSourceSongTitleMatch()` /
  `hasMatchingNormalisedSongTitle()` exist specifically to pair a *detected* song with its
  *planned* counterpart across sources.
- **The linchpin:** `updateMatchedItem()` (`:346–352`) writes `livestream_service_section_id` onto
  **matched existing items**, not only newly created ones. That is precisely the back-reference
  `linkSectionsToItems()` (`LivestreamChurchServiceProjectionService.php:243`) consumes to set
  `service_sections.church_service_item_id`.

So once merging is allowed, a planned OoS song item acquires a section link, `song_match_type =
confirmed` applies to it, and branch (a) of the Phase 6.1 policy is satisfied — the §3.1 failure
mode closes itself. The guard is a blunt gate standing in front of machinery that already handles
the case correctly and non-destructively.

### 3.3 Blast radius: this is a live-pipeline change, not an import-only one

`hasNonLivestreamItems()` runs on **every** livestream projection, so changing it changes how next
Sunday behaves too. Two options:

- **(i) Remove the guard outright.** Every service, historic or future, merges. Recommended: the
  stated goal is production looking "as if processed by today's code", and if only the importer
  bypassed the guard, promoted history would be inconsistent with everything processed after it.
- **(ii) Config-flag it,** default off, enabled for import. Smaller immediate blast radius, but
  it institutionalises exactly the inconsistency the goal rules out, and leaves dead config to
  remove later.

Take (i), with tests covering both directions of the merge. One knock-on to plan for: with merging
enabled, `ChurchServiceCanonicalUpdateService::finalize()` will now see genuine cross-source
conflicts on these services and may set `canonical_conflict_state`, which surfaces them for
review. That is the correct behaviour, and Q4 confines its cost to Stage A: the conflicts surface
in the **local** inbox during review, which is where they should be dealt with, and WP5 makes sure
they do not re-open in production after promotion. The interaction with
`docs/archived-plans/REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md` is therefore about local review ergonomics during
a several-hundred-service import, not about production inbox volume.

---

## 4. Architecture

**Rejected: copy the five-table graph verbatim.** `service_sections` alone carries five foreign
keys (`church_service_item_id`, `expected_item_id`, `matched_item_id`, `media_processing_log_id`,
`published_sermon_id`), and `church_services`/`church_service_items`/`media_processing_logs` add
more. Remapping all of them across databases is high-surface and easy to get subtly wrong — and
§3.1 means "subtly wrong" is a public-data regression.

**Chosen: promote the *run*, then replay production's own projection, then transplant linkage.**

One bundle per service carries:

| Layer | Contents | Identity strategy |
|---|---|---|
| Sermon | `sermons` row, preacher, scripture filters | natural key: date + service + content_type; preacher by name/slug |
| Run | `media_processing_logs` row (status, timings, `processing_metadata`, `file_hash`, asset paths) | natural key: `processing_id` UUID + `file_hash` |
| Sections | `service_sections` rows, minus all item FKs | natural key: `(processing_id, section_order)` |
| Item linkage | for each song/sermon section: position, section type, normalised title, song natural key (`praise_number` / `canonical_key` / `slug`) | resolved against production items at import |
| Assets | manifest of every referenced asset: kind, path, disk, size, sha256 | verified in place for sermons; for children's talks the manifest also drives the step-0 restore (§2.6) |
| Review state | local `review_state`, `needs_review`, and `import_metadata['manual_review']` | replayed verbatim in step 8 (Q4) |

Import sequence in production. Steps 1–8 run inside one transaction per service.

> **Steps 0 and 9 are CANCELLED (2026-07-25).** Both existed only to shuttle children's-talk media
> into and out of private storage. The children's-talk storage plan removed private storage
> entirely, so a children's talk's assets are already on the sermon disk under ordinary sermon keys,
> already in the bucket, and need no restore before the transaction and no settle after it.
> **The whole sequence is now transactional**, steps 1–8, with no pre- or post-transaction phases —
> which also deletes the §8 rollback exception and the idempotency carve-outs that existed for them.
> Step numbering is preserved so the cross-references in §6.1, §8 and WP4 still resolve.

0. ~~**(Children's talks only, per §2.6.)** Restore the talk's `private/…` assets to their
   pre-private keys in Spaces and verify each against the bundle manifest's sha256.~~ **CANCELLED**
   — the assets never leave the bucket now, so there is nothing to restore.
1. Preflight-classify (`already_present` / `create` / `conflict`) — no writes.
2. Create the `sermons` row + preacher link + scripture filters (Tier 1). A children's talk is
   written exactly like a sermon; there are no private paths and no observer to wait for.
3. Create the `media_processing_logs` row, `sermon_id` remapped to step 2.
4. Create `service_sections` rows with `media_processing_log_id` from step 3 and
   `published_sermon_id` from step 2, carrying each section's reviewed `needs_manual_review` value;
   item FKs left null for now.
5. Run `LivestreamChurchServiceProjectionService::project()` on the new log — production builds
   `church_services` / `church_service_items` itself, using the same code the pipeline uses.
6. Resolve section→item linkage from the bundle's natural keys against the resulting production
   items, reusing `ChurchServiceItemSyncService`'s matching helpers. **Assert every song section
   that was `confirmed` locally is linked**, or roll back.
7. Re-run the §3.1 song-usage query for the affected service and assert no song lost eligibility,
   or roll back (Q2: strictly zero-loss).
8. Apply the promoted review state (Q4): the service lands `reviewed`, carrying the local review
   timestamp. Assert `needs_review` is false, or roll back — see WP5.
9. ~~**After commit**, let the queue settle: for children's talks, `MoveSermonToPrivateStorage` runs,
   pulls the assets onto production's private disk and deletes the staging objects.~~ **CANCELLED**
   — that job no longer exists. Still run `audit:sermon-assets` before declaring the service done;
   it is now an ordinary post-import check rather than a settle step.

Only **two** FK remappings cross the database boundary (`sermon_id`, `media_processing_log_id`).
Everything else is derived in production by production's own code — which is precisely the stated
goal of "the state it would be in if they were processed by today's code".

---

## 5. Work packages

Two groups. **WP-A\* must all land before the first Stage A batch** — they change what the import
*keeps*, and none of them can be applied retroactively to a service whose source recording is no
longer mounted. **WP0–WP7** are the Stage B promotion work.

### Stage A prerequisites — artifact durability (§2.5)

| WP | What | Kind | Blocked by |
|---|---|---|---|
| WP-A1 | Move the full-service transcript off the temp disk onto the transcript disk | pipeline change | — |
| WP-A2 | Persist the raw verbose_json transcription payload alongside the normalised cues | pipeline change | WP-A1 |
| WP-A3 | Request and retain word-level timestamps | pipeline change | WP-A2 |
| WP-A4 | Record source-file provenance on the processing log | importer change | — |
| WP-A5 | Retain the RMS log for the duration of the import | pipeline change | WP-A1 |
| WP-A6 | Keep the full-service compressed audio in Spaces (32 kbps, per Q6) | pipeline change | — |

### Stage B — promotion

| WP | What | Kind | Blocked by |
|---|---|---|---|
| WP0 | Allow livestream projection to merge into OoS-backed services (§3.2/§3.3) | pipeline change | — |
| WP1 | Song-usage baseline + diff tooling (read-only) | new command | — |
| WP2 | Bundle format + validator, with the §3.1 gate as an acceptance test | design | WP1 |
| WP3 | Exporter: `sermons:export-archive-bundle` (sermons **and** children's talks, per Q3) | new code | WP2 |
| WP4 | Importer: `sermons:import-archive-bundle`, steps 0–9 above | new code | WP0, WP2 |
| WP5 | Review-state and idempotency semantics — promoted services land `reviewed` (Q4) | design | — |
| WP6 | Thumbnail strategy — Strategy A, one `.env` line before Stage A (Q5) | ops/code | — |
| WP7 | Operator runbook + orphan-asset cleanup | docs/chore | WP4 |
| ~~WP8~~ | ~~Children's-talk asset transfer (§2.6)~~ — **CANCELLED 2026-07-25**, the storage plan removed its reason to exist | — | — |

**WP0 lands first among the Stage B items, and before Stage A too.** It is a live-pipeline
improvement in its own right, it is small, and every Stage-A import run after it produces
correctly-merged local data — so doing it before Stage A begins avoids re-importing services that
were projected under the old guard.

**A note on scope discipline.** WP-A1 – WP-A6 are all live-pipeline changes, so they affect next
Sunday as well as the historic archive. That is intentional and, in every case, an improvement:
the pipeline currently deletes evidence it took real compute to produce. But it does mean these
land with the same test rigour as any other pipeline change, not as import-only special cases.

### WP-A1 — Full-service transcript survives, permanently

The §2.5.1 fix. Today's storage path is temp-disk-relative and swept daily.

- `TranscribeFullService::storeTranscript()` writes to
  `config('media-processing.storage.transcript_disk')` (already resolves to `do_spaces` via
  `SERMON_STORAGE_DISK`) under a stable, human-legible key:
  `service-transcripts/{date}/{service}-{processing_id}.json`. Date-first so the bucket is
  browsable by service date, `processing_id`-suffixed so re-runs of the same service do not
  collide.
- Update every reader in lockstep — `MediaProcessingLog::serviceTranscriptPath()`,
  `hasStoredServiceTranscript()` (`:567`, currently hardcodes the temp disk),
  `TranscribeFullService::filterStoredTranscript()`, `DetectServiceStructure`, and
  `StructureEvaluateCommand`.
- Handle the mixed estate: `hasStoredServiceTranscript()` must resolve a legacy
  `temp/service_transcript_*.json` value on the temp disk **and** a new-style key on the transcript
  disk, so runs already completed locally keep working. A one-shot
  `media:migrate-service-transcripts` command copying any surviving legacy files onto the
  transcript disk is worth writing before the sweep next fires.
- **Delete the misleading comment** in `temporaryFilePaths()` (`:737-740`) — once the file is not
  in `temp/` at all, the omission is no longer load-bearing and the comment describes a guarantee
  the code never actually made.
- Tests: `tests/Feature/Jobs/TranscribeFullServiceTest.php` — transcript written to the transcript
  disk; legacy temp-disk path still resolvable; and a **regression test in
  `tests/Feature/Console/CleanupOrphanedTempFilesCommandTest.php`** asserting that a completed
  run's stored transcript survives `media:cleanup-temp-files`. That last test is the point of the
  work package: it converts a comment into an enforced invariant.

**Acceptance:** the regression test fails against current `master` and passes after the change.

### WP-A2 — Keep the raw transcription payload

Per §2.5.3, everything Whisper reports beyond `start`/`end`/`text` is dropped at the DTO boundary,
and prompt-echo filtering overwrites the stored file in place.

- Write the unmodified `verbose_json` payload to
  `service-transcripts/{date}/{service}-{processing_id}.raw.json` on the transcript disk, before
  any normalising or filtering. `ChurchServiceTranscript` stays exactly as it is — this is an
  archival sidecar, not a DTO change, so nothing downstream needs to know about it.
- Record the path in `processing_metadata['service_transcript_raw_path']`, and alongside it the
  provenance of the pass: transcription service, model, endpoint, and the compression profile
  used. Re-runs must not silently overwrite a payload produced by a different model.
- Both implementations need it (`LocalWhisperServiceTranscriptionService`,
  `OpenAiServiceTranscriptionService`); the OpenAI path chunks, so it stores the per-chunk payloads
  with their offsets rather than one document.
- Tests: raw payload written and path recorded; the normalised transcript is unchanged by the
  addition; a filtered prompt-echo cue is absent from the stored transcript but **present** in the
  raw payload.

**Acceptance:** for any completed run, the exact bytes the transcription service returned can be
recovered from Spaces.

### WP-A3 — Word-level timestamps

Segment-level cues run 5–10 seconds. That is the hard resolution limit on every future boundary
decision: re-cutting a sermon, clipping a song, aligning lyrics to audio, highlighting a search
match in-page. Precision cannot be added later without the audio.

- Request word timestamps from both backends — `timestamp_granularities[]=word` on the OpenAI API;
  whisper.cpp exposes equivalent token timings on the OpenAI-compatible endpoint. Verify the local
  server build actually returns them before relying on it; if it does not, WP-A2 still captures
  whatever it does return, and this WP degrades to "confirmed unavailable" rather than blocking.
- With WP-A2 in place the words are already archived, so the DTO change is optional and can be
  deferred: add a nullable `words` field to `ChurchServiceTranscript` only when a consumer needs
  it. **Do not** let a DTO redesign block the import.
- Tests: a fixture with word timings round-trips; a backend that omits them still produces a valid
  transcript.

**Acceptance:** word timings for a real service are present in the archived raw payload, or their
unavailability is recorded in the run metadata.

### WP-A4 — Source provenance on the processing log

The §2.5.4 gap, and the cheapest item in this plan.

- `HistoricVideoImporter` writes a `processing_metadata['historic_import']` block on the log it
  creates, containing: the work item's `tag` and `label`; every source file's absolute path,
  size, mtime and sha256, **in concat order**; whether concatenation was lossless or re-encoded;
  the codec fingerprint from `probeCodecInfo()`; the drive volume name; and the import timestamp.
- Single-file items get the same block with a one-entry list — uniformity matters more than
  brevity here.
- Also emit a run-level JSON report (`--report=`) covering **every** work item including the
  skipped ones. `skip-small`, `skip-no-date` and `skip-unclassified` decisions are currently
  invisible in the database, so without this there is no record of what the importer chose not to
  import. This is the audit trail for "did we get everything?".
- Tests: `tests/Feature/Services/Media/Video/HistoricVideoImporterTest.php` (or the existing
  command test) — a multi-segment item records all sources in order with hashes; a skipped item
  appears in the report with its reason.

**Acceptance:** for any imported service, its source files on the drive can be identified by hash
without the drive being mounted.

### WP-A5 — Retain the RMS log

`GenerateRmsLog` writes `rms_log_path` to the temp disk (`:96`) and
`temporaryFilePaths()` (`:727`) deletes it at the end of every run. It is the only route to
re-snapping section boundaries to silence without the source video.

- Same mechanism as WP-A1: write it to the transcript disk beside the service transcript
  (`service-transcripts/{date}/{service}-{processing_id}.rms.json`) and drop it from
  `temporaryFilePaths()`. RMS logs are small.
- `SilenceSnapService` and anything else reading `rms_log_path` move with it.
- If retaining them for every future Sunday is unwanted, prefer a retention *policy* (age-based
  pruning) over deletion-at-completion — but the default should be to keep, since regenerating
  requires source media that may not exist.
- Tests: RMS log present after a completed run; boundary re-snapping works from the stored log
  with no source video available.

### WP-A6 — Full-service audio in Spaces

Per §2.5.2 the pipeline already produces a 32 kbps mono MP3 of the whole service and deletes it
inside a `finally` block. Keeping it makes almost every conceivable future reprocessing possible
without remounting the drive: re-transcribing with a better model, extracting a children's talk
the classifier missed, re-cutting a sermon whose boundaries were wrong, diarising speakers.

- Upload to the sermon disk under `service-audio/{date}/{service}-{processing_id}.mp3` and record
  it as `processing_metadata['service_audio_path']`. Keep the local `unlink()` — the temp copy
  still goes, it is just uploaded first.
- **Q6: 32 kbps, no second encode.** Keep the existing mono profile exactly as the pipeline already
  produces it, so this WP adds an upload and nothing else — no extra ffmpeg pass, no new config.
  Record the profile used in `processing_metadata` alongside the path so a future reader knows what
  they are getting without probing the file.
- The accepted trade-off, stated once so it is not rediscovered later: 32 kbps mono is
  transcription-grade. It is entirely adequate for re-transcription, diarisation, classification and
  boundary work — every *machine* use this archive exists for — but it is poor for music. **Once the
  drive is gone, congregational singing from these services can never be published at usable
  quality.** That is a deliberate decision, not an oversight.
- Size: ~21 MB per 90-minute service, so ~10 GB for a ~500-service archive.
- This is deliberately **audio only**. Whole-service *video* is ~2–4 GB per service (1–1.5 TB
  across the archive) and is out of scope; if video insurance is wanted, a cold archive of the
  drive itself is the better instrument than routing multi-terabyte uploads through the pipeline.
- Tests: audio uploaded and path recorded on a completed run; the temp copy is still cleaned up;
  a transcription failure does not leave a partial upload.

**Acceptance:** every imported service has a playable full-service audio file in Spaces reachable
from its processing log.

### WP0 — Merge livestream projection into OoS-backed services

- Remove the `hasNonLivestreamItems()` early return in
  `LivestreamChurchServiceProjectionService::project()` (`:88–104`) so those services reach
  `projectItems()`. Delete `hasNonLivestreamItems()` if it has no other caller.
- The `openReviewFromSections()` call the guard performed still needs to happen for services that
  fall through other skip paths — check the remaining branches still cover it.
- Tests, in the namespaced suite:
  `tests/Feature/Services/ChurchService/LivestreamChurchServiceProjectionServiceTest.php`
  - OoS service with planned songs + livestream run → items merged, **no OoS item deleted**,
    matched items carry `livestream_service_section_id`, sections carry `church_service_item_id`
  - the same service's songs remain publicly eligible under the §3.1 query (the regression that
    motivated this plan)
  - OpenLP song identity (`song_id`, titles) survives the merge
  - a livestream-only service still behaves exactly as before
- **Acceptance:** the WP1 diff shows zero song-usage loss when a livestream run is projected onto
  a service that already has order-of-service items.

### WP1 — Song-usage baseline and diff (do this first)

The safety net for §3.1. Read-only; safe to run against production.

- New command `songs:usage-snapshot {--output=} {--json}` emitting, per song, the set of
  qualifying `church_service_items` (service date, service, item id) using the *existing*
  `PublicSongUsageService` query path — do not reimplement the policy, call it.
- A `--compare=` mode diffing two snapshots and failing non-zero on any **loss**.
- Tests: `tests/Feature/Console/SongsUsageSnapshotCommandTest.php` — cover both Phase 6.1
  branches, plus the exact §3.1 regression (attach a completed livestream log with unlinked
  sections → assert the diff reports loss).

**Acceptance:** running it twice against an unchanged database produces an empty diff; the
constructed §3.1 scenario is reported as a loss.

### WP2 — Bundle format and validator

- `App\Services\Sermon\ArchiveBundle\*`, modelled on the R8 classes but **new files**.
  `FORMAT = 'crockenhill-archive-promotion'`, `VERSION = 1`.
- Eligibility guard is the inverse of R8's: require `SourceType::Livestream` and a completed
  `MediaType::Livestream` provenance log.
- Validator asserts: bundle self-consistency, every `confirmed` song section carries a resolvable
  song natural key, scripture filters match the current reference parser (R8 already does this —
  `SermonPromotionBundleExporter.php:107`), `processing_id` is a UUID, `file_hash` is 64 hex chars.
- **Asset manifest (`ArchiveBundleAssets`).** Reuse `SermonPromotionAssets` unchanged for **all**
  assets, children's talks included. *(Simplified 2026-07-25: this bullet previously required a
  second class because `guardPortablePath()` rejected `private/` by design. That clause is gone
  along with the prefix, so there is no children's-talk special case to carve out and no guard to
  relax.)* The class still records size + sha256 per asset and still fails on a missing file.
- **The §3.1 gate is an acceptance test, and Q2 sets its threshold at zero.** Not "no material
  loss", not "loss below a tolerance": a single qualifying `church_service_items` row dropping out
  of `PublicSongUsageService`'s result set fails the bundle and rolls the service back. Encode that
  as an equality assertion on the pre/post item-id sets, not a count comparison — a simultaneous
  gain and loss would net to zero and slip through a count check.
- Tests: `tests/Unit/Services/Sermon/ArchiveBundle/` — round-trip, and one rejection test per guard.

### WP3 — Exporter

- `sermons:export-archive-bundle {--ids=} {--from=} {--until=} {--output=}`.
- Refuse to export a service whose local review state is not `reviewed`
  (`ChurchServiceReviewState::Reviewed`) — promotion must follow review, never precede it. **Q4
  makes this guard the enforcement point** for "review first, then promote": the operator cannot
  promote an unreviewed service even by mistake, and the exporter's refusal message should say so.
- **Children's talks are in scope (Q3).** A date's children's talk exports alongside its sermon —
  they share date + service and are distinguished by `content_type`, which the preflight already
  keys on. *(Corrected 2026-07-25: their assets are ordinary in-place references now, like a
  sermon's. The manifest-for-WP8's-restore arrangement this bullet used to describe is gone.)*
- Carry the service's review state and `import_metadata['manual_review']` block so WP5 can replay
  it (§4 step 8).
- Tests: feature test over a factory-built livestream service; assert the emitted bundle contains
  no local primary keys except the declared `local_id` provenance field; assert a not-yet-reviewed
  service is refused; assert a date carrying both a sermon and a children's talk exports both, the
  talk's assets referenced the same way the sermon's are.

### WP4 — Importer (the substance)

- `sermons:import-archive-bundle {bundle} {--dry-run}`; **dry-run is the default posture** —
  require an explicit `--apply`.
- Implement steps 1–8 of §4 — **steps 0 and 9 are cancelled** (see the banner there; they existed
  only for private storage, which no longer exists). The whole sequence is one transaction per
  service, with no pre- or post-transaction phase to reason about. Steps 6, 7 and 8 are hard gates:
  failure rolls the service back and reports it, without aborting the remaining services in the
  bundle.
- Reuse rather than reimplement: `LivestreamChurchServiceProjectionService`,
  `ChurchServiceItemSyncService` matching helpers, `MediaProcessingIdentityResolver`,
  `SermonPromotionAssets::verify()`.
- **Field handling per §2.4** when creating the `media_processing_logs` row: promote
  `audio_file_path`, `video_file_path`, `transcript_file_path`; write `null` to
  `source_file_path`, `rms_log_path`, `enhanced_audio_file_path` (local temp scratch). When
  creating `service_sections`, write `null` to `extracted_video_path`, `extracted_audio_path`,
  `extracted_at` and `unpublished_expires_at` — candidate preview media does not travel.
- The §3.1 gate at step 7 still applies even with WP0 landed: if a service would lose public song
  usage, roll it back and report it for manual handling rather than degrade silently. Per Q2 the
  tolerance is exactly zero.
- Tests, all feature-level: create-path, `already_present` idempotency (re-importing the same
  bundle is a no-op), slug conflict, date-collision with an existing OoS service (both Q1
  branches), a dedicated §3.1 regression test asserting zero song-usage loss, a promoted service
  landing `reviewed` with `needs_review` false, and a children's-talk import ending with **ordinary
  sermon-disk paths** (not private ones — see §2.4's correction) and a clean `audit:sermon-assets`.

### WP5 — Review state and idempotency

**Q4 is answered: review locally, then promote — so promoted services arrive `reviewed`.** They
*were* reviewed, just in a different database, and re-queueing 200+ historic services in production
would swamp the review-queue work done in `docs/archived-plans/REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md`.

The mechanism mostly falls out of existing code rather than needing new state handling:

- `ChurchServiceReviewSynchronizer::openReviewFromSections()` (`:92–107`) sets `needs_review` only
  when some section has `needs_manual_review = true`. Reviewed sections have had that cleared
  locally, so §4 step 5's `project()` call **will not** open a review — provided step 4 carries the
  post-review `needs_manual_review` values rather than the values the detector originally wrote.
  That is the whole trick, and it is why WP4 step 4 spells it out.
- Step 8 then applies the service-level state the way the UI does: reuse
  `ChurchServiceReviewStateService::normalizedReviewColumns()` with an
  `import_metadata['manual_review']` block carrying the **local** `reviewed_at` and reviewer id, as
  `MarkServiceReviewed::execute()` (`app/Actions/ServiceReview/MarkServiceReviewed.php:36–50`)
  does. Do not hand-write `review_state`/`needs_review`/`review_reason` — the normaliser owns their
  consistency.
- Assert, don't assume: step 8 fails the service if `needs_review` is true after projection. A
  service that wants review in production is a signal that something diverged between the reviewed
  local state and what production rebuilt, and that is exactly the case worth stopping on.
- One knock-on from §3.3: `ChurchServiceCanonicalUpdateService::finalize()` can set
  `canonical_conflict_state` during step 5 on OoS-backed dates. `MarkServiceReviewed` unsets
  `import_metadata['canonical_conflict']` — step 8 must do the same, or promoted services reopen
  for a conflict their operator already dispositioned locally.

Idempotency: re-running an identical bundle must classify every entry `already_present` and write
nothing. The step-0 and step-9 carve-outs this previously called out are gone with those steps.

- Tests: a promoted service lands `reviewed` with `needs_review` false and the local timestamp
  preserved; a bundle carrying a section still flagged `needs_manual_review` is rejected at export
  (WP3) rather than silently opening a production review; a canonical conflict raised during
  projection does not leave the promoted service in the inbox; a second identical import is a
  no-op.

### WP6 — Thumbnail strategy

Per §2.4, thumbnails default to the local `public` disk. **Q5 confirms production resolves
thumbnails from `do_spaces`, so this WP is Strategy A** — the cheapest option, and effectively one
line of configuration:

```dotenv
THUMBNAIL_STORAGE_DISK=do_spaces
```

Thumbnails then travel exactly like audio and video, `SermonPromotionAssets::diskFor()` (`:151`)
resolves them correctly at both ends with no code change, and no production regeneration is needed.

Two constraints, both about ordering:

- **Set it before Stage A begins** (§6.1 item 3). Thumbnails generated before that line exists stay
  on the local `public` disk and would need re-running or a manual upload. This is the single
  cheapest item on the pre-flight checklist and the easiest to forget.
- ~~**Children's talks are unaffected by it.**~~ **Inverted 2026-07-25 — children's talks now
  depend on this line exactly like sermons do.** Their thumbnails were previously privatised onto
  the local disk regardless of the setting and travelled via WP8; with private storage removed and
  WP8 cancelled, they follow `THUMBNAIL_STORAGE_DISK` like everything else. Forgetting the line no
  longer merely misses sermon thumbnails — it misses every thumbnail in the import.

The exporter must **refuse** to emit a bundle whose thumbnail assets fail `manifestForSermon()`'s
existence check, rather than silently promoting dangling paths — that guard is what turns a
forgotten `.env` line into a loud failure instead of an archive-wide broken-image problem.

Strategy B (regenerate in production from the promoted `video_file_path`, which *is* in Spaces —
`GenerateThumbnail.php:46–51`) is recorded here as the fallback if the `.env` line is missed for
some batch. It costs ffmpeg time in production and pulls full videos back out of Spaces, so it
wants throttling, but it needs no file transfer and it re-applies
`SermonExposurePolicy::shouldGenerateVideoThumbnail()` (`:111`) natively. It is a repair tool now,
not the plan.

### WP7 — Runbook and cleanup

- `docs/operations/historic-archive-promotion.md`: the operator sequence, the WP1 gate, and the
  rollback procedure.
- Orphan-asset sweep: `audit:sermon-assets` after each promotion round to catch bucket objects
  from locally-rejected imports (§2.2). The "confirm every promoted children's talk privatised
  cleanly" half of this check is **gone** — there is no privatisation step any more (§2.6).

### ~~WP8 — Children's-talk asset transfer~~ — CANCELLED 2026-07-25

> **Do not build this.**
> [CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md](../archived-plans/CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md)
> **landed and was archived on 2026-07-25.** It stripped the `private/` prefix entirely and put
> children's-talk assets on the ordinary sermon disk under ordinary sermon keys, so **WP8 is
> unnecessary in full** — not "nearly all". A children's talk's assets are now indistinguishable
> from a sermon's: there is no manifest to relax, no `guardPortablePath()` exception to carve out,
> no staging dance, and no local-disk capacity question. Promotion is the same database-row
> operation as for any other sermon.
>
> The text below is a **snapshot of work that will never be done**, kept so the WP numbering and
> §2.6 make sense. Several classes it names (`MoveSermonToPrivateStorage`, the `SermonObserver`
> privatise hook) no longer exist.

The work Q3 created, **assuming the storage plan has not landed**. §2.6 is the evidence; this is
the build. Everything here exists only because children's-talk media is on the import machine's
local disk and has been deleted from the bucket.

- **Manifest at export (WP2's `ArchiveBundleAssets`).** For each children's-talk asset — audio,
  video, transcript, `thumbnail_file_path`, the three `thumbnail_metadata` paths, and every
  thumbnail candidate — record the `private/`-stripped target key, size and sha256, reading from the
  `local` disk. The set of fields to walk is already enumerated twice in the codebase
  (`MoveSermonToPrivateStorage`'s `$moveOperations` list at `:69–77` plus `moveCandidateAssets()`,
  and `SermonObserver::hasNonPrivateProtectedAsset()`); mirror one of them rather than inventing a
  third list, and add a test that fails if they drift apart.
- **Restore command** — `sermons:restore-childrens-talk-assets {bundle} {--apply}`. Uploads each
  manifested asset to its pre-private key on the sermon/transcript/thumbnail disk and verifies size
  + sha256 after upload. Idempotent: an object already present and matching is a no-op. This is §4
  step 0, and it must complete before the importer touches the database — the sequencing is not
  cosmetic, because `MoveSermonToPrivateStorage` throws `Private-media source is missing` and burns
  its three retries (`$backoff = [10, 60, 300]`) if the row lands first.
- **Let production privatise.** The importer writes non-private paths and stops there.
  `SermonObserver::saved()` fires on `wasRecentlyCreated`, `MoveSermonToPrivateStorage` copies each
  asset to production's local disk, compare-and-sets the paths, and deletes the staging object. No
  privatisation logic is reimplemented in the importer — production applies its own policy, which is
  what the whole plan is trying to achieve.
- **Verify, then sweep.** After the queue drains, `audit:sermon-assets` must report zero
  `childrens_talk_public` and zero `missing` for the promoted ids. Any staging object still in the
  bucket means the move job did not complete and the talk is exposed — treat a non-empty sweep as a
  failure, not a cleanup chore.
- **Capacity check.** Every promoted children's talk consumes production *local* disk permanently,
  not bucket storage. Estimate the total from the local private directory before the first
  promotion batch — this is the one asset class where promotion has a hard server-side ceiling.
- Tests: manifest covers every field the move job moves (the drift test above); restore is
  idempotent and rejects a sha256 mismatch; an end-to-end feature test promotes a children's talk,
  runs the queued move, and asserts private paths plus a clean audit; a restore failure leaves no
  database rows behind.

**Acceptance:** a promoted children's talk is indistinguishable from one production processed
itself — private paths on production's local disk, nothing left in the bucket, `audit:sermon-assets`
clean.

---

## 6. Stage A runbook (the import loop itself needs no new code — the prerequisites do)

The CBC drive mounts at `/Volumes/CBC Drive/ServiceVideos`; it was **not mounted** when this plan
was written (`/Volumes/` showed only `Macintosh HD`).

The command in §6.2 is ready today. What is not ready is the pipeline's retention behaviour, so
§6.1 gates the first batch. Mounting the drive and running §6.2 without §6.1 produces an archive
that cannot be reprocessed afterwards — which is recoverable only by mounting the drive again and
paying the compute a second time.

### 6.1 Pre-flight checklist

Everything here is cheap now and impossible-or-expensive to retrofit across hundreds of services.
Work through it in order; do not start batch 1 with any item outstanding.

**Code that must be merged first**

1. **WP0** — services imported under the current `hasNonLivestreamItems()` guard get no
   section→item links on OoS-backed dates, so they would need re-importing later.
2. **WP-A1 – WP-A6** (§5, Stage A prerequisites) — the retention fixes. WP-A1 (transcript
   survival) is the one that changes "we must remount the drive to change anything" into "we can
   re-run structure detection, song matching and section classification forever". If time forces a
   subset, WP-A1, WP-A4 and WP-A6 are the ones that buy the most.
3. **Add `THUMBNAIL_STORAGE_DISK=do_spaces` to `.env`** (WP6, Strategy A — Q5 confirmed production
   uses `do_spaces`). Thumbnails generated before that line exists stay on the local disk and will
   not promote. Verify it took effect with the `config:show` check in item 4 below rather than
   trusting the file.

**Environment verification — run these, do not assume**

4. **Confirm transcription and detection are not mocked.** `SERVICE_TRANSCRIPTION_SERVICE` and
   `SERVICE_STRUCTURE_DETECTOR` both **default to `mock`** in config, and a mocked run *completes
   successfully* with fabricated content — there is no error to notice. `.env` currently sets
   `SERVICE_TRANSCRIPTION_SERVICE=local` and `SERVICE_STRUCTURE_DETECTOR=openai`
   (`.env:86-91`), but verify against the resolved config rather than the file, because a cached
   config or a stale `.env` produces a silently worthless archive:

   ```bash
   vendor/bin/sail artisan config:show media-processing.service_structure
   vendor/bin/sail artisan config:show media-processing.storage
   vendor/bin/sail artisan config:show thumbnail-generation.storage
   ```

   Check in one pass: `transcription_service` ≠ `mock`, `detector` ≠ `mock`,
   `sermon_disk` = `do_spaces`, `transcript_disk` = `do_spaces`, and — per item 3 — thumbnail
   `disk` = `do_spaces`.
5. **Confirm the local Whisper server is up and reachable from the container** before a long batch
   — it is a native host process on `host.docker.internal:2022`, not a Sail service, so it does
   not come up with `sail up`.
6. **Check temp disk headroom.** `--temp-disk-min-free-gb` brakes the importer, but it skips work
   rather than waiting, so a low disk turns a batch into a no-op that looks like progress. Check
   inside the container (`sail exec laravel.test df -h /`), not on the host.

**Capture before you start, and between batches**

7. **Save the dry-run inventory as the corpus manifest.** Commit it. It is the only record of what
   the drive contained as the importer saw it — including the items it classified `skip-small`,
   `skip-no-date` and `skip-unclassified`, which leave no database trace at all. WP-A4's
   `--report=` output supersedes this once built; until then the dry-run output is the record.
8. **Snapshot the database before batch 1 and after each batch.** All the reviewed structure that
   Stage B promotes lives only in the local database, so a lost local database means re-importing
   from the drive — the exact thing this plan is trying to do only once.
   `spatie/laravel-backup` is already wired to the `do_spaces_backups` disk:

   ```bash
   vendor/bin/sail artisan backup:run --only-db
   ```

   Per-batch, not per-run: batches are the natural rollback unit, and reviewing a batch is what
   makes its data worth keeping.
9. ~~**Protect `storage/app/private/` on the import machine.**~~ **OBSOLETE 2026-07-25 — do not do
   this.** It assumed the import moved children's-talk media to a local private disk and deleted it
   from the bucket. The children's-talk storage plan removed that behaviour entirely: the mover job
   and its observer hook are gone, `storage/app/private` no longer exists, and children's-talk media
   stays on the sermon disk in Spaces exactly like a sermon's. There is no local-only copy to
   protect and no separate backup to take. The item number is kept because §8 refers to it.

### 6.2 The import loop

```bash
# 1. Inventory only — free, no writes. Save this (checklist item 7).
vendor/bin/sail artisan sermons:import-historic-videos --dry-run

# 2. Calibration batch. --limit picks the highest-value items first:
#    prioritiseWorkItems() sorts processable-before-skips, then services with
#    no existing sermon, then newest-first (HistoricVideoImporter.php:253).
vendor/bin/sail artisan sermons:import-historic-videos --limit=6

# 3. Review at /admin/services (needs-review filter), disposition each service.

# 4. Re-run the identical command. checkExistence() (:880) skips completed,
#    in-flight, and awaiting-review services, so the same command advances.
```

Three properties make this a safe loop:

- **Idempotent across runs** — re-running the same command never duplicates work.
- **Self-braking** — undispositioned services are skipped as `skip-pending-review`, so the queue
  cannot outrun review.
- **Serial by default** — `--parallel=1` makes `waitForInflight()` block until each item reaches a
  terminal state, so a batch returns only once it has genuinely processed.

Nothing in Stage A is publicly visible: the local database is separate from production, and
`SermonRepository::basePublicSermonQuery()` (`app/Services/Public/SermonRepository.php:28`) has no
publish gate — the database boundary *is* the safety mechanism, which is why Stage B is gated the
way it is.

Calibration context: this pipeline was scored against hand-annotated truth over 8 Sundays in the
July corpus work (`docs/operations/livestream-corpus-testing.md`) — 0 out-of-service false alarms,
5/6 clean on test-set-2. Known-weak areas to weight review toward: sermon boundary extraction on
unusual services, children's-talk misclassification, and title derivation.

---

## 7. Risks

| Risk | Mitigation |
|---|---|
| **Promotion deletes public song usage (§3.1)** | WP1 baseline + WP4 step-7 per-service gate; Q2 sets the tolerance at exactly zero, asserted as a set equality not a count |
| ~~**Children's-talk media exists only on the import machine (§2.6)**~~ | **Closed 2026-07-25** — the children's-talk storage plan landed, so their media stays in the bucket like a sermon's. No local-only copy, no separate backup, no restore |
| ~~Children's talk promoted before WP8 lands → dangling private paths and a failing `audit:sermon-assets`~~ | **Closed 2026-07-25** — WP8 is cancelled and there are no private paths to dangle |
| Children's-talk restore leaves staging objects public in the bucket if the move job fails | WP8 treats a non-empty post-promotion sweep as a failure, not a chore; move job retries 3× with backoff |
| Production local disk fills as children's talks accumulate | WP8 capacity check before the first promotion batch — bucket storage does not absorb these |
| Promoted services re-open for review in production despite local review (Q4) | WP5: carry post-review `needs_manual_review` into step 4, clear `canonical_conflict`, and fail the service if `needs_review` is true after projection |
| `THUMBNAIL_STORAGE_DISK` line forgotten before a batch → thumbnails stay local | §6.1 items 3–4 verify resolved config; exporter refuses bundles with unverifiable thumbnail assets; WP6 Strategy B is the repair path |
| Full-service audio archived at 32 kbps cannot later publish congregational singing | Accepted trade-off (Q6), recorded in WP-A6 so it is not rediscovered as a defect |
| **Full-service transcripts swept 24h after each run (§2.5.1)** | WP-A1 moves them to the transcript disk; regression test pins the invariant |
| Full-service audio and RMS logs deleted at run completion (§2.5.2) | WP-A6, WP-A5 |
| Transcription confidence signals and pre-filter text discarded at the DTO boundary (§2.5.3) | WP-A2 archives the raw payload before normalising |
| Boundary precision permanently capped at segment resolution (§2.5.3) | WP-A3, riding on WP-A2's raw archive |
| No record of which drive files produced which run, or of what was skipped (§2.5.4) | WP-A4 provenance block + run report; dry-run inventory saved as the interim record (§6.1) |
| **Whole archive imported with mocked transcription/detection** — mock is the config default and completes without error | §6.1 item 4: verify resolved config, not `.env`, before batch 1 |
| Local database lost after review but before promotion — Stage B's entire input | §6.1 item 8: `backup:run --only-db` per batch |
| Batch silently no-ops because the temp disk is low | §6.1 item 6: check headroom inside the container before each batch |
| Production already has an OoS service for the date (§3.2) | WP0: merge as normal; sync service already refuses to delete OoS items |
| WP0 changes live-pipeline behaviour for future Sundays too (§3.3) | Deliberate (option i); covered by WP0's test matrix |
| Thumbnails never reach Spaces (§2.4) | WP6 Strategy A (Q5); exporter refuses bundles with unverifiable assets |
| Dangling temp-disk paths promoted into production (§2.4) | WP4 nulls `source_file_path`, `rms_log_path`, `enhanced_audio_file_path` |
| Merging raises canonical-conflict volume in the review inbox (§3.3) | Q4 confines it to the local inbox during Stage A; WP5 clears `canonical_conflict` on promotion |
| Re-detection in production diverges from reviewed structure | Design decision: transplant linkage, never re-detect (§3.2) |
| Slug collisions with existing production sermons | R8's preflight already models this (`SermonPromotionBundleImporter.php:205`) |
| Orphan bucket objects from rejected local imports | WP7 sweep via `audit:sermon-assets` |
| Bulk arrival swamps the production review inbox | Q4: promoted services land `reviewed` and never enter the inbox (WP5) |
| `sermons` is not unique on date+service (holds children's talks too) | Preflight keys on date + service + `content_type` |

## 8. Rollback

Each service promotes inside one transaction, so a failed service leaves no partial rows. To undo
a *committed* service: delete its `service_sections`, then its `media_processing_logs` row (the
`service_sections` FK is `on delete cascade`), then the `sermons` row
(`published_sermon_id` is `on delete restrict`, so sections must go first), then re-run the WP1
diff to confirm song usage returned to baseline. Bucket assets are left in place deliberately —
they are re-usable if the service is promoted again.

~~**Children's talks are the exception, in both directions.**~~ **OBSOLETE 2026-07-25 — children's
talks are no longer an exception at all.** The three bullets below assumed the private-storage move
job and the staging dance that WP8 was going to build. Both are gone: a children's talk's assets sit
on the sermon disk under ordinary sermon keys, so it rolls back exactly like any other sermon, and
the "public copy of something meant to be private" disclosure state cannot arise. Retained struck
through because §6.1 item 9 and §2.6 refer to it.

- ~~If the failure happened before step 9, the restored staging objects are still in the bucket.
  Delete them — until the move job runs, they are *public* copies of assets that are supposed to be
  private, which is the one rollback state with a disclosure consequence rather than just an
  untidiness one. Check for them explicitly rather than assuming the rollback was clean.~~
- ~~If the failure happened after step 9, production already holds the private copies on its local
  disk. Deleting the `sermons` row orphans them; sweep them with `audit:sermon-assets` and remove
  them manually.~~
- ~~The import machine's `storage/app/private/` copy is untouched by any of this and remains the
  master, which is why §6.1 item 9 protects it until promotion is verified.~~
