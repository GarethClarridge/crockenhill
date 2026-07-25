# Move Children's-Talk Asset Storage to Spaces

> **Status (2026-07-25): redesigned. WP0 + WP3a + WP1 tooling + WP2 code are all DEPLOYED
> (run 30159923493, master `2e417d313`). WP0's two-deploy acceptance check is still unverified;
> WP1's and WP2's production runs are outstanding; WP3b–WP4 not started.**
>
> ## Deploy note — 2026-07-25
>
> Actions recovered and the `Deploy` workflow ran green on `2e417d313`, so production now has WP0's
> `app-private` + `app-livestream` mounts, WP3a's observer removal, WP1's audit tooling, and WP2's
> command. Consequences, in order of what matters:
>
> 1. **The bleeding has stopped, by two independent mechanisms** — the mounts persist the private
>    directory, and WP3a means new children's-talk media never goes there in the first place.
> 2. **§3.3 is now live for new content.** From this deploy, a newly processed children's talk keeps
>    its Spaces keys, and the existing prefix-based delivery switch renders CDN URLs for it inside the
>    still-login-gated page. The gate, sitemap exclusion and API exclusion are unchanged.
> 3. **WP1's audit is now runnable** — `production-audit.yml` execs into the running container, and the
>    commands finally exist there. This is the next action.
> 4. **WP0's acceptance is NOT met yet.** It requires *two* deploys with a written asset and an
>    uploaded recording surviving in between (§4 WP0). Only the first deploy has happened. Do not mark
>    WP0 done on the strength of this run.
>
> The CI failure on the first attempt at this run was an unrelated pre-existing flake:
> `CleanupReviewQueueNoiseCommandTest` creates three `ChurchService` rows via a factory that generates
> `date` randomly, against a `(date, service)` unique constraint, in a class using
> `DatabaseTransactions`. It passed on rerun. Worth hardening separately with explicit dates.
>
> ## Progress note — 2026-07-25 (later)
>
> GitHub Actions was down, so WP0's deploy is still outstanding. The code-only work that does not
> depend on it was brought forward:
>
> - **WP3a landed early, and ships in the same deploy as WP0.** The observer hook was not merely an
>   obstacle to WP2 — it was the *cause* of the children's-talk data loss. `SermonObserver` was the
>   only dispatch site for `MoveSermonToPrivateStorage` (verified), so removing it means new talks
>   stay on Spaces, where a container replacement cannot reach them. WP0 makes the private directory
>   survive a deploy; WP3a makes it unnecessary. Accepted consequence, per §3.3: from that deploy,
>   newly processed children's-talk media lands on public-read keys, and the existing prefix-based
>   serving branches render CDN URLs for it. The login gate, sitemap and API exclusion are untouched.
> - **WP2's code is complete**: the mover takes `toPrivate` and `deleteSource` flags (declared with
>   defaults rather than promoted, so jobs queued before the change still deserialise);
>   `referencedAssetIndex()`'s disk-keying defect is fixed via `MediaAssetPath::diskForPath()`, with a
>   test confirmed to fail without it while its forward-direction twin still passes; and
>   `media:publicise-childrens-talk-assets` is dry-run by default. The command **refuses** a
>   `--delete-source` pass while any talk still references a private path, so the plan's
>   "never the same run as the copy" rule is enforced in code rather than in a runbook.
> - `ChildrensTalkPublicationWorkflowTest` was rewritten per §2.6 to assert the gate
>   (`canAccessChildrensCorner` / `shouldExposeOnSermonApi` / `shouldIncludeInSitemap`) instead of the
>   storage location. It was the only suite failure caused by WP3a.
> - Both audit commands were smoke-tested locally ahead of their production debut and match the
>   baseline recorded in §4 WP1 (144 missing, zero private; sections clean).
>
> **Still blocked on the deploy:** WP1's production run, WP2's production run, WP3b, and removal of
> the `app-private` volume.
>
> ## Redesign note — 2026-07-25
>
> **This plan previously routed children's-talk media through a new private Spaces disk
> (`do_spaces_private`) with signed-URL delivery, and deferred making the files public to a
> speculative WP7. That design is superseded.** The files now go **straight to the existing sermon
> disk**, under ordinary sermon keys, in one move.
>
> The reason, in the maintainer's words (2026-07-25): *"The only reason talks are currently private
> is because the children's-talk area is behind a login until we're happy enough with it to publish.
> It's basically a feature toggle. That shouldn't need to affect the storage of the media files.
> This plan moves them twice, which feels unnecessary. They're basically just a different type of
> sermon."*
>
> Verified against the code, and correct on every count — see §2.2 and §2.4. The login gate is
> `SermonExposurePolicy::canAccessChildrensCorner()`, driven by `CHILDRENS_TALKS_PUBLIC`, and it is
> **completely independent of storage**. The `private/` path prefix buys exactly one property on top
> of that gate: bytes unreachable without an authorised application request. The superseded design
> **deliberately gave that property away** in its own WP3 (6-hour signed URLs; risk table: *"Signed
> URL leaks and outlives the session — Accepted"*) while still paying for a new disk, a new delivery
> path, a migration command, a runbook, and a second data move to undo it all.
>
> What the old plan spent five work packages building, this one deletes. Net diff is negative.
>
> **`CHILDRENS_TALKS_PUBLIC` stays `false`. Nothing in this plan publishes anything.** The
> children's-corner pages remain behind the members' login exactly as today. What changes is where
> the bytes live.
>
> ### What survives from the previous version
>
> - **WP0 in full** (both volume mounts), unchanged and still the urgent part.
> - **WP1 in full** (rescue-then-measure ordering, both audit commands), unchanged, and it still
>   gates everything downstream.
> - The old §2.1 evidence on production data loss, verbatim — that analysis was right.
> - The old WP7's *content*, promoted from a speculative appendix to the substance of the plan.
>
> ### What is deleted from the previous version
>
> WP2 (`do_spaces_private` disk + `private_disk` config seam), WP3 (signed-URL delivery), WP4
> (non-serving readers off `->path()`), WP5 (writers target the configured disk), WP6 (migration
> command + flip + runbook). None of it was started; there is nothing to unwind. Confirmed
> 2026-07-25: no `private_disk` key exists in `config/media-processing.php` and no
> `do_spaces_private` disk exists in `config/filesystems.php`.
>
> **Agents must not, without maintainer input:** (a) run any command against production; (b) flip
> `CHILDRENS_TALKS_PUBLIC` — this plan does not publish children's talks and does not need to;
> (c) run WP2's migration before WP3's observer-hook removal has landed (§4 WP2, ordering trap);
> (d) treat WP0 as optional — it is what stops the bleeding while the rest lands.
>
> **Related:** [HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md](HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
> §2.6 and WP8. Under the superseded design that plan's WP8 "mostly evaporated". Under this one it
> **disappears entirely** — see §5.1.

---

## 1. What this changes, in one paragraph

Children's-talk assets stop being special. Their `private/` prefix is stripped, their files move
from the local disk to the same `do_spaces` sermon/thumbnail disks every other sermon already uses,
and every code path that asked "does this path start with `private/`?" is deleted. Access control
does not move: `EnsureChildrensCornerAccess` middleware still guards both children's-corner routes
and `SermonExposurePolicy::canAccessChildrensCorner()` still gates the asset controller, both driven
by the unchanged `CHILDRENS_TALKS_PUBLIC=false`. Section-publication preview candidates move off
`private/` in the same direction, so the prefix rule can be removed outright rather than kept alive
for one remaining tenant.

---

## 2. Current state (evidence)

### 2.1 Production destroys children's-talk media on every deploy

*(Unchanged from the previous version of this plan. This analysis was correct and is the reason
WP0 and WP1 come first regardless of the design above them.)*

The `local` disk is rooted at `storage_path('app')` (`config/filesystems.php:46-48`), so a stored
path of `private/2024/talk.mp3` resolves to `/var/www/html/storage/app/private/2024/talk.mp3`.

Production runs a container image pinned to a git SHA and mounted exactly four persistent volumes
(`docker-compose.prod.yml:36-43` as drafted):

```yaml
volumes:
  # Persist local storage (pages, temporary files - sermons are in Spaces)
  - app-storage:/var/www/html/storage/app/public
  - app-temp:/var/www/html/storage/app/temp
  - app-livewire-tmp:/var/www/html/storage/app/livewire-tmp
  - app-logs:/var/www/html/storage/logs
```

`storage/app/private` was not among them, and the Dockerfile did not create it either. So Laravel
created it lazily on first write, into the container's ephemeral writable layer. A deploy sets a new
`IMAGE_TAG` and replaces the container. The writable layer goes with it.

**Every children's-talk audio file, video, transcript, thumbnail and thumbnail candidate written
since the last deploy is lost at the next one.** The database rows survive and keep pointing at
paths with nothing behind them, which is why this ran undetected: the sermon still lists, the page
still renders, and only the asset routes 404.

Second population, same fault: `PrepareSectionPublicationCandidates` writes review-time preview
clips to `private/section-publications/{id}/` on a hardcoded `'local'` disk (`:277-283`).

**Third population, found 2026-07-25 — and this one is worse.** The *original uploaded recordings*
are equally unpersisted. `VideoStorageService::storeUploadedVideo()` (`:32-34`) stores them to
`livestream/temp/{uuid}.{ext}` on the temp disk, which is `'local'`
(`config/media-processing.php:57`), rooted at `storage_path('app')` — so they land in
**`storage/app/livestream/temp/`**, a directory in neither the Dockerfile `mkdir` nor the prod
volume list. Confirmed against production data shapes: every `media_processing_logs.source_file_path`
sampled is of the form `livestream/temp/{uuid}.mkv|mp4`.

Note the near-miss: `storage/app/temp` **is** mounted, and `media-processing.paths.temp` is
`temp/media-processing`, so *derived* processing artifacts persist. Only the original upload does
not, because it alone sits under `livestream/`.

Two consequences beyond this plan's scope:

1. **Re-derivability is largely nil.** A children's talk cannot be regenerated from a source
   recording that the same deploy destroyed. WP1's recoverability columns quantify this; expect
   `source recording gone` to dominate.
2. **A deploy during processing destroys the recording being processed**, not just the queued job.
   That is a live availability bug, independent of children's talks.

Both mounts landed 2026-07-25 as the same three-site change (compose mount + `volumes:`
declaration, Dockerfile `mkdir`, entrypoint chown/chmod).
`tests/Feature/Config/ProductionStoragePersistenceTest.php` guards the arrangement: every path in
`PERSISTED_STORAGE_PATHS` must be mounted, declared, `mkdir`'d and chown/chmod'd. The chown and
chmod argument lists are checked separately rather than by scanning the file, so a path present in
one but absent from the other fails — precisely the omission WP0 originally made.

**The `app-livestream` volume is permanent** (source uploads are not moving to Spaces in this plan).
**The `app-private` volume is interim**, and its purpose changes under this redesign: it is no
longer a stopgap for storage that will keep living locally, it is what protects the files WP2 is
about to move. It comes out after WP2 has been verified in production.

### 2.2 The `private/` prefix is one switch, and it is not the access gate

This is the finding that supersedes the previous design. Two mechanisms are at work, and the old
plan treated them as coupled:

**The access gate.** `SermonExposurePolicy::canAccessChildrensCorner()`
(`app/Services/Sermon/SermonExposurePolicy.php:57`) is:

```php
return $this->childrensTalksArePublic() || ($user instanceof User && $user->hasVerifiedEmail());
```

`childrensTalksArePublic()` (`:44`) reads `config('church.sermons.childrens_talks.public')`, which is
`env('CHILDRENS_TALKS_PUBLIC', false)` (`config/church.php:41-43`). It is enforced in three places,
none of which consult a file path:

| Enforcement point | Covers |
|---|---|
| `EnsureChildrensCornerAccess` middleware (`:23`), on `routes/web.php:63-64` | both `/christ/childrens-corner` pages |
| `SermonAssetController::authorizeAssetAccess()` (`:289-293`) | every asset route, redirecting guests to login |
| `SermonExposurePolicy::shouldIncludeInSitemap()` (`:157`) / `shouldExposeOnSermonApi()` (`:91`) | sitemap, public API, and therefore crawler discovery |

**The storage switch.** `SermonStorageService::requiresGuardedDelivery()` (`:623`) is, in its
entirety, `return str_starts_with($path, 'private/');`. That single boolean is what makes
`getAudioDeliveryUrl()` return `route('sermons.audio', …)` instead of a CDN URL, and it is what
`resolveFileInfo()` (`:122`) consults to pick the `local` disk.

**The feature toggle the maintainer described already exists and already works without private
storage.** Flipping `CHILDRENS_TALKS_PUBLIC` to `true` would publish the talks whether or not a
single byte moved; leaving it `false` keeps them gated whether or not a single byte moved.

### 2.3 The prefix rule is duplicated across nine sites

Every consumer re-derives the disk from the path prefix, independently:

| Site | Shape |
|---|---|
| `SermonAssetController:110, 150, 182, 221, 242` | `str_starts_with($path, 'private/') ? 'local' : <configured disk>` |
| `SermonAssetController:81, 118, 158, 190, 229, 308` | the *early-return* prefix checks — each already has a working public-URL branch |
| `SermonStorageService:623` (`requiresGuardedDelivery`) | the guarded-vs-CDN switch |
| `SermonStorageService:122` (`resolveFileInfo`) | returns `['type' => 'private', 'disk' => 'local', …]` |
| `SermonStorageService:273` (`resolveThumbnailDisk`) | same ternary, memoised |
| `ThumbnailGenerationService:824, 839` | same ternary |
| `MediaAssetPath::diskForPath()` (`:14-21`) | `isPrivate($path) ? 'local' : $publicDisk ?? sermon_disk` |
| `PrepareSectionPublicationCandidates::candidateDisk()` (`:281`) | hardcoded `return 'local';` |
| `PrepareSectionPublicationCandidates:250` — **the ninth site** | passes a literal `'local'` as `extractOptimizedAudio()`'s `$permanentDisk` (signature at `VideoExtractionService:518-524`), *bypassing* `candidateDisk()` |
| `MoveSermonToPrivateStorage:245, 299, 406` | hardcoded `Storage::disk('local')` as the copy target |
| `AuditSermonAssetsCommand:158` | `$expectedDisk = $isPrivate ? 'local' : $kindDisk;` |
| `SermonPromotionAssets::guardPortablePath()` (`:167`) | rejects `private/` paths from promotion bundles outright |

The old plan's response was to route all nine through a new config seam. This plan's response is to
delete all nine, because after WP2 no path begins with `private/`.

Note what the early-return checks in the first two rows mean in practice: **every one of the six
serving methods already has a fully working non-private branch**, exercised in production for
regular sermons on every request. De-privatising does not require writing a delivery path. It
requires deleting the *other* arm of an existing if/else.

### 2.4 What private storage actually buys, and what the old plan did with it

Given §2.2, the `private/` prefix adds exactly one property on top of the access gate: **the bytes
are unreachable without an authorised application request.** Access to the *pages* and to the
*asset routes* is already gated without it.

The superseded WP3 traded that property away on purpose. It replaced `response()->file()` with a
6-hour presigned S3 URL, and its own risk table recorded: *"Signed URL leaks and outlives the
session — **Accepted**: 6h TTL, and the content is destined to be public."*

Once a shareable URL is acceptable, the private disk is protecting nothing that is still being paid
for — and the bill was a new S3 disk, a dual local/S3 delivery branch (needed because development,
CI and Dusk all run on the local disk), a `temporaryUrl()`-under-`bucket_endpoint` unknown to verify
against a live bucket, a migration command with a field-list drift test, an operations runbook, a
new load-bearing production environment variable, and a second data move to undo it in WP7.

**Keys are not enumerable, which is what makes the direct route defensible.** Stored asset
filenames carry a UUID: `Str::uuid().'_sermon_optimized.mp3'` (`AudioCompressionService:72`),
`Str::uuid().'_sermon.mp3'` (`VideoExtractionService:426`), under `sermons/audio` and `sermons/video`
(`config/media-processing.php:65-66`). So public storage does not make talks discoverable — it makes
them fetchable *by anyone holding a URL that an authorised member handed over*. §3.3 states the
residual difference honestly.

### 2.5 The candidate population is already disk-agnostic

Checked 2026-07-25, and this is better news than the old plan assumed. Every consumer of
`service_sections.extracted_video_path` / `extracted_audio_path` already resolves its disk through
`ServiceSection::extractedAssetDisk()` (`:217`) and already handles S3 sources via
`StorageAdapterHelper::downloadToTemp()`:

| Consumer | Already disk-agnostic? |
|---|---|
| `SongPublicationHandler:64, 132, 145, 216, 276` | yes — including the `isS3CompatibleDisk()` temp-download branch at `:154-158` |
| `SermonPublicationHandler:126, 129, 209, 216-232` | yes |
| `CleanupUnpublishedSectionAssetsCommand:137-140` | yes |
| `ServiceSectionSyncService:353` | yes |
| `DeleteLivestreamUpload:218, 223` | yes |
| `ExtractedSectionMediaChecker:26-27` | yes, via `MediaAssetPath::diskForPath()` |
| `Admin/ServiceSectionCandidateMediaController:57` | **no** — `Storage::disk($disk)->path($path)` + `response()->file()` |

**Exactly one site breaks on a non-local candidate disk.** WP4 is therefore much smaller than the
old WP4+WP5 pair implied.

`Actions/Publication/ExpireSectionPublicationAssets` only nulls the path columns; file deletion is
the cleanup command's job. So for candidates, **path set + file absent is unambiguous loss**, never
legitimate expiry — which is what makes WP1's `audit:section-assets` numbers meaningful.

### 2.6 Existing test coverage is substantial

27 test files reference `private/`. The ones that go away with the machinery:

- `tests/Integration/Jobs/MoveSermonToPrivateStorageTest.php` (433 lines)
- `tests/Feature/Security/SermonPrivateStorageMoveTest.php` (140 lines)
- `tests/Feature/SermonPrivateAssetTest.php` (98 lines)

The ones that must be **kept and rewritten to assert the gate rather than the storage** — these are
the important ones, because they are what proves the login gate survived the change:

- `tests/Feature/Security/ChildrensTalkAssetSecurityTest.php`
- `tests/Feature/SermonAssetSecurityTest.php`
- `tests/Feature/ChildrensCornerPagesTest.php`
- `tests/Feature/Operations/ChildrensTalkPublicationWorkflowTest.php`
- `tests/Integration/Observers/SermonObserverTest.php`
- `tests/Feature/Console/AuditSermonAssetsCommandTest.php` (`:139`, `:161` assert on
  `childrens_talk_public`, whose meaning inverts — see WP3)

**Note for whoever implements this:** the local development database has 3 children's talks and
**none of them has any asset path set** (verified 2026-07-25). The migration cannot be exercised
against real local data; WP2's tests must construct it via factories.

---

## 3. Design

### 3.1 The decision

Children's-talk assets live on the ordinary sermon disks under ordinary sermon keys. There is no
private disk, no signed URL, no `PRIVATE_STORAGE_DISK`, and no second move. Access control stays
where it already is, in `SermonExposurePolicy` and the two middleware/controller enforcement points.

The end state is **less** code than today, in a codebase whose active tracker is a simplification
backlog.

### 3.2 What gets deleted

| Thing | Lines | Fate |
|---|---|---|
| `MoveSermonToPrivateStorage` | 519 | delete after WP2 has run in production |
| its tests (`MoveSermonToPrivateStorageTest`, `SermonPrivateStorageMoveTest`) | 573 | delete with it |
| `SermonObserver::saved()` re-privatise hook (`:47-53`) + `hasNonPrivateProtectedAsset()` (`:81-104`) | ~30 | delete — **before** WP2 runs (§4 WP2) |
| `SermonStorageService::requiresGuardedDelivery()` (`:623`) + its two call sites (`:getAudioDeliveryUrl`, `:getVideoDeliveryUrl`) | ~12 | delete; both methods collapse to their public branch |
| `SermonStorageService::resolveFileInfo()` private branch (`:122-128`), `resolveThumbnailDisk()` ternary (`:273`) | ~12 | collapse |
| `SermonAssetController` — the `->path()` + `response()->file()` tail of `serveAudio`, `serveVideo`, `serveThumbnail`, `servePlainThumbnail`, `serveCardThumbnail`, and `serveStoredThumbnail()` entirely | ~90 | delete; each method becomes authorise-then-redirect, which its first branch already does |
| `SermonAssetController::authorizeAssetAccess()` private-path branch (`:308-314`) | ~7 | delete; the children's-talk gate at `:289-293` **stays** |
| `MediaAssetPath::isPrivate()` + `diskForPath()`'s `'local'` branch | ~10 | `diskForPath()` collapses to the configured disk; `isPrivate()` goes |
| `ThumbnailGenerationService:824, 839` ternaries | ~6 | collapse |
| `SermonPromotionAssets::guardPortablePath()`'s `private/` rejection (`:167`) | 1 clause | delete — this is what removes the archive plan's WP8 |
| `AuditSermonAssetsCommand`'s `childrens_talk_public` finding (`:64, 158, 207-208, 277, 293, 337`) | ~10 | delete — it currently treats the desired end state as a **failure** |
| `PrepareSectionPublicationCandidates::candidateDisk()` (`:281`) and the `'local'` literal at `:250` | ~4 | point at the sermon disk (WP4) |
| `docker-compose.prod.yml` `app-private` mount + declaration, `Dockerfile` `mkdir`, `entrypoint.sh` chown/chmod | 4 sites | remove in a later deploy, after WP2 is verified |

### 3.3 What is given up, stated plainly

**1. Asset URLs become permanently shareable.** Today a children's-talk page renders
`route('sermons.audio', …)`, which authorises every request. After WP2,
`SermonUrlBuilder::audioUrl()` → `getAudioDeliveryUrl()` → `getPublicUrl()` renders the CDN URL
directly into the (still login-gated) page. A member who copies that URL can share it, and it works
without a session, indefinitely.

Weighed against the alternatives:

| Delivery | Guessable? | Expires? | Cost |
|---|---|---|---|
| Today: streamed via guarded route | n/a | every request re-authorised | 4 sites of `->path()` + local-disk-only storage |
| Superseded WP3: 6h presigned URL | no | after 6h | new disk, dual-branch delivery, migration, runbook, second move |
| **This plan: public CDN URL** | **no** (UUID keys, §2.4) | **never** | none — it is the existing path |

The superseded design is genuinely stronger on one axis: a leaked URL dies within six hours. The
judgement recorded here is that a six-hour window on non-sensitive, soon-to-be-public content does
not justify five work packages and two data moves — and that the old plan had already conceded the
principle by accepting leak-tolerant URLs at all.

**2. The move is the one step a config flip cannot undo.** Rollback of everything else is a deploy
(§8). But once an object has been public-read in a CDN-fronted bucket, anything that fetched, cached
or crawled it is beyond recall. **A children's-talk video may show or name identifiable children.**
The maintainer's position, recorded 2026-07-24 and reaffirmed 2026-07-25, is that there is no
safeguarding sensitivity here and that publication was always the intent; this plan proceeds on that
basis. It is noted rather than argued because it is the only irreversible step in the plan and
because sitemap and API exclusion (§2.2) mean *discovery* remains gated even afterwards.

**3. Candidate keys are enumerable, unlike sermon keys.** `candidateAudioDirectory()` (`:285`)
produces `private/section-publications/{section->id}`, and the video is `…/{id}/video.mp4` — a
sequential integer, not a UUID. On the local disk that does not matter. On a public bucket it means
unpublished review clips could be walked by section id. WP4 therefore adds a random component to
the directory (§4 WP4); this is the one place where moving to public storage needs a small design
change rather than a deletion.

### 3.4 The migration is the existing job, reversed by a parameter

`MoveSermonToPrivateStorage::sourceAndTargetPaths()` (`:233-240`) is **already bidirectional** — it
returns `[stripped, prefixed]` for a private path and `[path, prefixed]` for a public one, because
it needs both to recognise an already-completed move. Only three lines hardcode the direction:
`copyAndVerify()`'s `$target = Storage::disk('local')` (`:245`), `verifyCommittedTarget()` (`:299`),
and `deleteSourceAfterCommit()` (`:406`).

So the reverse migration is a target-disk parameter on the existing job, not a new job. Everything
that makes it safe is direction-agnostic because it is expressed against the `Storage` API:
verify-before-delete (`:408-410`), compare-and-set path commits under `lockForUpdate()`
(`:304-389`), the stale-unreferenced-target healing path (`:251-270`), per-asset failure collection
so one failure cannot leave the rest half-moved (`:68-87`), and the deferred-deletion snapshot
(`:449-467`).

**One defect must be fixed for the reverse direction to be safe.** `referencedAssetIndex()`
(`:480-518`) pairs every referenced path with its *kind's public disk*:

```php
$assets = [
    [$sermonDisk, $sermon->audio_file_path],
    …
];
$index['disk_paths'][$disk.'|'.$path] = true;
```

A row still holding `private/sermons/audio/x.mp3` is therefore indexed as
`do_spaces|private/sermons/audio/x.mp3`, never `local|private/…`. Forward, the source key is
`do_spaces|sermons/audio/x.mp3` and matches correctly. **Reverse, the source key is
`local|private/sermons/audio/x.mp3` and can never match**, so the "retained referenced source" guard
at `:412-420` would silently never fire and the job could delete a private object another sermon row
still points at. Fix: build the index's disk through `MediaAssetPath::diskForPath()` so private paths
index against the private disk. A test must cover it — two children's talks sharing one asset path,
migrate one, assert the source survives.

---

## 4. Work packages

| WP | What | Kind | Blocked by |
|---|---|---|---|
| **WP0** | **Mount `storage/app/private` and `storage/app/livestream`** — code done 2026-07-24/25, **awaiting deploy** | ops | — |
| WP1 | Rescue + quantify the loss in production (read-only) — tooling done 2026-07-25, **run outstanding** | ops | WP0 rescue step first |
| WP3a | Remove the observer re-privatise hook — **done 2026-07-25**, ships with WP0 | refactor | — |
| WP2 | De-privatise children's-talk assets — **code done 2026-07-25**, production run outstanding | code/ops | WP1, WP3a |
| WP3b | Delete the rest of the `private/` machinery | refactor | WP2's production run |
| WP4 | Section-publication candidates off `private/` | code | WP3b |

Two work packages of code where there were five, and no new configuration surface.

### WP0 — Stop the bleeding — **code implemented; deploy outstanding**

Unchanged from the previous version. Persisting a storage path in production takes **three** changes
per path, all in the repo for both `private/` and `livestream/`:

- `docker-compose.prod.yml` — mounts `app-private:/var/www/html/storage/app/private` and
  `app-livestream:/var/www/html/storage/app/livestream`, and declares both in the top-level
  `volumes:` block.
- `Dockerfile` (`:97`) — both paths added to the `mkdir -p` alongside `livewire-tmp`, `temp`,
  `public`.
- `docker/production/entrypoint.sh` — both added to the boot-time `chown -R www:www` / `chmod -R
  775` of mounted storage paths. **This is the site the plan originally missed.** A root-owned volume
  is a silent write failure rather than a loud one.

The Dockerfile and entrypoint steps are not redundant. Docker seeds a *new* named volume from the
image directory it covers, including ownership — but only if that directory exists in the image; if
not, Docker creates it `root:root`. The `mkdir` makes seeding correct for fresh volumes; the
entrypoint makes ownership correct regardless of the order in which volume and image directory came
into existence.

`tests/Feature/Config/ProductionStoragePersistenceTest.php` guards all of it, verified by deleting
each site in turn and confirming the suite goes red.

- **Acceptance (NOT YET MET — operator action):** deploy, write a children's-talk asset **and upload
  a recording**, deploy again, both still present. Verify by deploying twice, not by reading the
  compose file. **Production keeps losing files on every deploy until this ships** — the code landing
  is not the fix, the deploy is.
- Both mounts ship in the **same** deploy. Deploying one without the other leaves source recordings
  being destroyed, which is the loss no later import can undo.
- **Redesign note:** `app-livestream` is permanent. `app-private` is now interim in a different
  sense than before — it protects the files WP2 moves, and comes out after WP2 is verified.

### WP1 — Rescue, then quantify — **tooling landed 2026-07-25; production run outstanding**

Unchanged, and under this redesign it matters *more*, because it answers a question WP2 cannot
start without: **is there anything left to move?**

Two audit commands, both whitelisted in `production-audit.yml` under a `private-assets` choice
(`:25`, `:75`, `:81`) — **counts only, never paths or ids**, per the repo's production-audit
convention:

- `audit:sermon-assets` — carries `private_referenced` / `private_missing` per asset kind, plus a
  per-talk summary (total children's talks, how many reference private assets, how many have at
  least one private asset missing). Per-talk, not per-asset, because recoverability is decided one
  talk at a time. Without this split a production run reports e.g. "144 missing" with no way to tell
  deploy-destroyed private files from public files absent for unrelated reasons — on the local dev
  database that exact run reports 144 missing of which **zero** are private.
- `audit:section-assets` — covers `service_sections.extracted_video_path` / `extracted_audio_path`
  through `ServiceSection::extractedAssetDisk()`, same split. Nothing audited this population before.

#### Rescue step discharged — 2026-07-25

**`storage/app/private` does not exist in the production container.** Checked inside the running
container (not on the host, which would prove nothing since the app lives in the image), it returned
`No such file or directory` and a count of 0.

So there is nothing to rescue and the ordering constraint below is satisfied trivially: **step 1 is a
no-op and the deploy can go first.** It also settles the scope question WP2 could not start without —
whatever children's-talk media production once had is *already* gone from an earlier deploy, and the
directory was never even recreated since. Expect WP1's audit to report the loss as historic, and
WP2's production run to have little or nothing to move. That does not make WP2 pointless: it still
strips the prefix off the surviving database rows so that the machinery in WP3b can be deleted.

The per-deploy loss figure the rescue tree was meant to provide is therefore unavailable. WP1's
`missing` counts are the remaining measure.

#### Ordering — rescue first, measure second

WP0's deploy **destroys the private files that are still there**: they live in the running
container's writable layer, and the deploy replaces the container with a fresh, empty `app-private`
volume. But measurement is gated on that same deploy, because `production-audit.yml` runs
`docker compose exec app php artisan` inside the *running* container, so the audit tooling does not
exist on production until it ships.

The step that preserves files needs no deploy, so it goes first:

1. **Rescue, before any deploy.** On the production host:
   `docker compose -f docker-compose.prod.yml cp app:/var/www/html/storage/app/private ./private-rescue`
   Do this even if the expectation is that it finds nothing — the cost is a directory listing.
2. **Deploy**, shipping WP0's two volume mounts and WP1's audit tooling together.
3. **Restore** the rescued tree into the now-persistent `app-private` volume.
4. **Run the audits.** With WP0 mounted, the number is final and stable rather than decaying at the
   next deploy.

Step 1's output is also the honest measure of ongoing loss: file count and total size in
`private-rescue` is what a single deploy was costing.

#### The recoverability columns answer the decision

Both audits partition their "missing" total three ways, and a test asserts the buckets sum back to
the total so a future asset kind cannot quietly fall outside all three:

| Bucket | Meaning |
|---|---|
| `missing_and_source_media_present` | re-derivable — the run's source recording is still on disk |
| `missing_and_source_media_gone` | source referenced but absent; unrecoverable |
| `missing_and_no_source_reference` | no processing run or no source path recorded (historic/manual) |

`SourceMediaPresence` resolves `source_file_path` in both shapes it occurs in (temp-disk-relative
`livestream/temp/…`, and absolute for historic imports), memoising per path because several talks and
sections routinely share one run. A sermon reaches its run either directly
(`media_processing_logs.sermon_id`) or through the section that published it
(`ServiceSection::published_sermon_id`).

**Given §2.1's third population, expect `source recording gone` to dominate.** If it does, WP2's
scope shrinks to whatever survives, and the honest record is that the rest is recoverable only
through the historic-archive import.

- **Acceptance:** a number, and a decision recorded against it — how many talks WP2 has files for,
  and whether the unrecoverable remainder is worth re-importing from another source.

#### WP1 RESULT — MET, 2026-07-25 (run on the server)

Both audits were run against production. **Both private populations are empty:**

| Measure | Production |
|---|---|
| Children's talks, total | **0** |
| Talks referencing private assets | 0 |
| `private_referenced`, all ten sermon asset kinds | **0** |
| Service sections with referenced candidate assets | **0** |
| `private_referenced` / `private_missing`, both candidate kinds | 0 |

**Decision recorded: skip WP2's production run and proceed straight to WP3b.** There is nothing to
migrate — not merely no bytes, but no rows. Nothing to re-import from another source either, so the
recoverability buckets are all zero and moot. WP2's code stays as the tested rollback/repeatability
tool and is not run in production. The "declaration of loss" variant considered for rows pointing at
dead `private/` paths is **cancelled** — no such rows exist.

**So WP0 and WP3a were preventative, not remedial.** The data-loss mechanism in §2.1 was real and
correctly diagnosed, but it never had a victim: the children's-corner feature has never produced
content in production, which is also why `storage/app/private` did not exist in the container. State
this plainly rather than implying anything was rescued.

**Caveat on the section figure.** Candidates are ephemeral — created at review time and swept by
`CleanupUnpublishedSectionAssetsCommand` — so zero is a point-in-time reading, not a durable property.
WP4's cutover note about in-flight candidates resolving to the sermon disk and presenting as needing
re-extraction still applies to whatever exists when WP4 ships. The children's-talk zero *is* durable,
because those are persistent rows.

**Unrelated finding, out of scope, not folded in:** the sermon audit reports **91 missing** assets,
none of them private. Audio (700/700) and video (36/36) are fully present; transcripts are 35 missing
of 41, and every thumbnail sub-kind is at or near zero present (`plain_thumbnail` 0/6,
`card_thumbnail` 0/2, `overlay_thumbnail` 0/6, all three candidate kinds 0/14). The local run shows the
same total wipe of the same asset family, and two environments failing identically and completely on
one family points more at `audit:sermon-assets` resolving the thumbnail disk differently from where
thumbnails are actually written than at a coincidental double loss. **Treat the 91 as unverified until
that is checked** — it is the same command relied on to verify future migrations.

### WP2 — De-privatise children's-talk assets

**Ordering trap, read this first.** `SermonObserver::saved()` (`:37-53`) dispatches
`MoveSermonToPrivateStorage` whenever a children's talk's `audio_file_path`, `video_file_path`,
`transcript_file_path`, `thumbnail_file_path` or `thumbnail_metadata` changes and
`hasNonPrivateProtectedAsset()` (`:81-104`) finds any path lacking the `private/` prefix. That is
exactly the state WP2 commits. **The observer hook must be removed before the migration runs**, or
every talk is re-privatised the instant it is un-privatised. The job's own
`isMovingSermon()` re-entrancy guard (`:121-124`) does not help — it only suppresses dispatch while
the forward job itself is running.

So WP3's observer removal is a **prerequisite of WP2's production run**, not a follow-up. Land them
in this order: observer hook removed → migration run → remaining machinery deleted.

Work:

- Parameterise `MoveSermonToPrivateStorage`'s copy target: add a target disk to `copyAndVerify()`
  (`:245`), `verifyCommittedTarget()` (`:299`) and `deleteSourceAfterCommit()` (`:406`), and a
  constructor flag selecting direction. `sourceAndTargetPaths()` (`:233`) needs no change (§3.4).
- **Fix `referencedAssetIndex()`'s disk keying** (§3.4) — resolve each path's disk through
  `MediaAssetPath::diskForPath()` rather than assuming the kind's public disk. Without this the
  shared-source guard silently never fires in the reverse direction.
- New command `media:publicise-childrens-talk-assets {--apply} {--delete-source}`:
  - dry-run by default, consistent with the repo's other one-shot commands;
  - iterates children's talks, dispatching the reversed job per talk;
  - idempotent — a target object already present and size-matched is a no-op, which the existing
    `copyAndVerify()` already implements;
  - `--delete-source` is a **separate later invocation**, never the same run as the copy.
- Evict caches after the run. `SermonObserver::saved()` calls `clearCachedMetadata()` on path change
  (`:28-34`), so per-sermon metadata is handled — but `forgetPublicListingCaches()` fires only on
  `SermonExposurePolicy::EXPOSURE_ATTRIBUTES` (`:59-61`), not on path changes. Children's talks are
  excluded from public listings and the podcast feed anyway, so the exposure is small; clear
  explicitly rather than reason about it.
- Tests, parameterised over both directions:
  - a talk with all seven direct/metadata assets plus thumbnail candidates migrates, every path
    loses its prefix, every object exists on the correct kind disk;
  - **two talks sharing one asset path** — migrate one, assert the source survives (the
    `referencedAssetIndex()` fix);
  - a partially-migrated talk resumes cleanly (mixed prefixed/unprefixed paths);
  - **source deletion happens after a verified copy** — the step that, if it silently no-ops,
    leaves orphans behind;
  - a concurrent path change aborts via compare-and-set rather than committing a mismatch.

- **Acceptance:** `audit:sermon-assets` reports zero `missing` and zero `private_referenced`, and a
  children's talk plays end-to-end with working seek **for a verified member and not for a guest**.
  The guest half of that check is the one that proves the gate survived.

### WP3 — Delete the `private/` machinery

Split so the observer removal can land ahead of WP2's run:

**WP3a (before WP2's production run):** remove `SermonObserver`'s re-privatise hook (`:47-53`) and
`hasNonPrivateProtectedAsset()` (`:81-104`). Update `tests/Integration/Observers/SermonObserverTest.php`.
Nothing else changes; talks with existing `private/` paths keep being served by the still-present
private branches.

**WP3b (after WP2's production run is verified):** everything in §3.2's table. The two that must move
together with the rest, because leaving either behind produces something that looks like a bug:

- `AuditSermonAssetsCommand`'s `childrens_talk_public` finding (`:207-208`, and its inclusion in the
  failure condition at `:277`) currently reports a public children's talk as a **fault**. Left in
  place it would flag the entire migrated archive.
- `SermonPromotionAssets::guardPortablePath()`'s `private/` clause (`:167`) — this is the one that
  deletes the archive plan's WP8 (§5.1).

Rewrite rather than delete the tests in §2.6's second list. `Security/ChildrensTalkAssetSecurityTest`
in particular must keep asserting that an unauthenticated request to a children's-talk asset route
redirects to login and that a verified member gets the asset — the storage change must not weaken
either. Delete only the three test files whose subject is the mover itself.

- **Acceptance:** `grep -rn "private/" app` returns nothing outside WP4's scope; the security tests
  pass unchanged in intent.

### WP4 — Section-publication candidates off `private/`

Small, because §2.5 found the population is already disk-agnostic everywhere but one site.

- `PrepareSectionPublicationCandidates::candidateDisk()` (`:281`) returns the sermon disk instead of
  `'local'`, **and** the literal `'local'` at `:250` — `extractOptimizedAudio()`'s `$permanentDisk` —
  moves with it. Changing only `candidateDisk()` splits a section's pair across two disks (video on
  Spaces, audio on local) and `DeleteLivestreamUpload`'s per-field disk map would then be right about
  one and wrong about the other.
- `candidateAudioDirectory()` (`:285`) drops the `private/` prefix **and gains a random component**:
  `section-publications/{id}-{random}/`. Per §3.3, the current key is a bare sequential section id,
  which on a public bucket would let unpublished review clips be walked. The paths are stored in
  `extracted_video_path` / `extracted_audio_path` so nothing needs to recompute them; the random
  component only has to be stable within one extraction, not derivable afterwards.
- `Admin/ServiceSectionCandidateMediaController::serveAsset()` (`:57`) is the only reader that breaks
  on a non-local disk. It becomes authorise-then-redirect to the public URL, keeping its
  `publication_status` / `published_sermon_id` guard (`:37-43`) ahead of the redirect.
- `MediaAssetPath::isPrivate()` and `ServiceSection::extractedAssetDisk()`'s prefix dependence can
  then go; `extractedAssetDisk()` collapses to the configured disk.
- One-shot migration for candidates in flight is **not** needed: they are ephemeral and regenerable,
  and `ExtractedSectionMediaChecker` (`:26-27`) already reports a missing pair at review time. Rows
  pointing at old `private/…` paths after the change resolve to the sermon disk, find nothing, and
  present as needing re-extraction — which is the correct outcome and the same one a deploy has been
  producing all along. Note it in the WP so it is not mistaken for a regression.
- Tests: `PrepareSectionPublicationCandidatesTest`, `Admin/ServiceSectionCandidateMediaControllerTest`,
  `AdminSectionPublicationCandidateMediaTest`, `SongPublicationHandlerTest`,
  `SermonPublicationHandlerTest`, `ExtractedSectionMediaCheckerTest`,
  `CleanupUnpublishedSectionAssetsCommand`'s test — asserting the audio and video of one section land
  on the **same** disk, and that publish and cleanup both delete from where the write happened.

- **Acceptance:** `audit:section-assets` clean; a section's candidate pair previews in the admin UI;
  publishing a section still promotes and still deletes its source.

---

## 5. Interactions

### 5.1 This plan deletes the archive plan's WP8 entirely

`HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md` §2.6 exists solely because children's-talk
media ends up on the import machine's local disk and is deleted from the bucket. Its WP8 builds a
manifest, a restore command, a staging-and-privatise dance, and a capacity check for production's
local disk.

Under the superseded design that became "verify the objects exist", because talks would still live
under a distinct `private/` prefix requiring `guardPortablePath()` to be relaxed. **Under this design
it disappears completely**: a children's talk's assets are on the sermon disk under ordinary sermon
keys, indistinguishable from a sermon's, so promotion is the existing database-row operation with no
special case and no relaxed guard.

Ordering for whoever schedules both: the archive plan's Stage A is gated on its own WP-A\* retention
workstream, and this plan is gated on nothing. **Doing this one first is strictly cheaper**, and more
so than before.

### 5.2 The publish decision is now genuinely independent

Under the old plan, publishing children's talks was WP7 — a second data move that could only happen
after WP6. Under this one, publishing is `CHILDRENS_TALKS_PUBLIC=true` and a deploy, with **no file
movement at all**. The bytes are already where a public talk's bytes belong; only the gate changes.

That is worth stating because it changes the shape of the decision: it stops being a migration to
schedule and becomes a switch to throw whenever the content is judged ready. This plan does not
throw it.

### 5.3 Local development and CI

No new configuration, so no `.env` change anywhere. Development, the parallel suite and Dusk resolve
the sermon disk exactly as they do for regular sermons today — which also removes the old plan's
requirement that every serving test be parameterised over two disk drivers.

---

## 6. Risks

| Risk | Mitigation |
|---|---|
| **Assets already lost in production (§2.1)** | WP1 quantifies before anything changes; WP0 stops further loss within one deploy — **its code landed but loss continues until that deploy happens** |
| Observer re-privatises assets as fast as WP2 un-privatises them | WP3a lands **before** WP2's run; §4 WP2 states the ordering as a prerequisite |
| Reverse migration deletes a source another sermon row still references | `referencedAssetIndex()` disk-keying fix (§3.4) plus a two-talks-one-path test; `--delete-source` is a separate invocation after the audit passes |
| Source deletion silently no-ops, leaving orphaned private objects | Existing `deleteSourceAfterCommit()` verifies then re-checks existence (`:408-428`); WP2 asserts it |
| Leaked CDN URL grants indefinite access | Accepted (§3.3): keys are UUID-named so not enumerable, sitemap/API exclusion keeps discovery gated, and the content is destined to be public |
| **A public object cannot be un-published from caches and crawlers** | The one irreversible step; flagged in §3.3 against the maintainer's recorded position rather than silently accepted |
| Candidate keys enumerable by section id on a public bucket | WP4 adds a random component to the candidate directory |
| Candidate audio and video split across two disks | WP4 changes `candidateDisk()` **and** the `:250` literal together; tests assert both land on one disk |
| Access gate weakened while storage changes | The gate is untouched code (§2.2); `Security/ChildrensTalkAssetSecurityTest` and `SermonAssetSecurityTest` are kept and must still prove guest→login, member→asset |
| `childrens_talk_public` audit finding flags the migrated archive | Removed in WP3b, together with the observer hook and the promotion guard |
| WP0's `app-private` volume outlives its purpose | Comment references this plan; removed after WP2 is verified, as the last step (§8) |
| Candidates in flight break at the WP4 cutover | Accepted and documented: they are regenerable and already present as needing re-extraction after any deploy |

## 7. What this plan does not do

- **It does not publish children's talks.** `CHILDRENS_TALKS_PUBLIC` stays `false`; the login gate is
  untouched. See §5.2 for what publishing would then cost.
- It does not change who can access a children's talk, at the page or the asset route.
- It does not introduce a private disk, a second bucket, a new environment variable, or a signed-URL
  delivery path.
- It does not move any other asset class. Sermon audio, video and transcripts are already in Spaces;
  source recordings stay on the local disk behind WP0's permanent `app-livestream` volume;
  temp-disk artifacts are the archive plan's WP-A\* workstream.

## 8. Rollback

- **WP0** is additive and has no rollback — an unmounted volume is the bug.
- **WP2, before sources are deleted:** the local `private/…` copies are still present and
  byte-identical. Re-run the migration in the forward direction (the job is bidirectional by
  construction, §3.4) and revert WP3a. Recovery is one deploy plus one command.
- **WP2, after sources are deleted:** the Spaces objects are the source of truth. Reverting means
  running the forward direction back onto local, which requires `app-private` to still be mounted —
  hence the ordering below.
- **`app-private` is the floor.** Keep it mounted until WP2 has survived several deploys and
  `audit:sermon-assets` is clean. Removing it is the last step of the whole plan, not part of WP2.
- **WP4** is a config-shaped change plus one controller; candidates are ephemeral, so rollback is a
  revert and a re-extraction.
- Objects are never deleted in the same operation that copies them, in either direction. That is what
  makes rollback non-destructive up to the point where sources are explicitly removed.
