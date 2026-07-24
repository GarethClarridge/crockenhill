# Move Children's-Talk (Private) Asset Storage to Spaces

> **Status (2026-07-24): drafted, not started.**
>
> **Why this exists:** children's-talk media is written to the `local` disk under `private/`, and
> **production does not persist that directory**. `storage/app/private` is neither created by the
> Dockerfile nor mounted as a volume (`docker-compose.prod.yml:36-43`), so it lives in the
> container's writable layer and is **destroyed on every deploy**. This is not a cleanliness
> improvement — §2.1 is a live data-loss bug, and it has been losing files for as long as private
> storage and containerised deploys have coexisted.
>
> **Scope clarification from the maintainer (2026-07-24):** there is **no safeguarding
> sensitivity** here. Children's talks are private only because they have not been *published*
> yet; making them public was always the eventual intent. That materially changes the design —
> §3.1 explains how. This plan moves the storage and leaves the publish decision to WP7, which it
> sets up rather than pre-empts.
>
> **Agents must not, without maintainer input:** (a) run any command against production;
> (b) flip `PRIVATE_STORAGE_DISK` before WP3 and WP4 have landed — the serving path calls
> `Storage::disk()->path()`, which only exists on local disks, so flipping first returns 500s on
> every children's-talk asset (§2.3); (c) treat WP0 as optional — it is the stopgap that stops
> the bleeding while the rest lands.
>
> **Related:** [HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md](HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
> §2.6 and WP8. That plan has to build a whole file-transfer workstream *because* children's-talk
> media is local-only. **If this plan lands first, that plan's WP8 mostly evaporates** — promotion
> goes back to being a database-row operation for children's talks too. See §5.1.

---

## 1. What this changes, in one paragraph

Children's-talk assets keep their `private/…` paths and their guarded, authorisation-gated
delivery routes. What changes is the **disk those paths resolve to**: today an unconditional
`'local'`, after this a configured `private_disk` that production points at a new
`do_spaces_private` filesystem — the same Spaces bucket and credentials as `do_spaces`, but with
private visibility and no CDN. Because the stored path strings already begin with `private/` and
the new disk uses no root prefix, **not one database row changes**. The migration is a file copy
plus a config flip.

---

## 2. Current state (evidence)

### 2.1 Production destroys children's-talk media on every deploy

The `local` disk is rooted at `storage_path('app')` (`config/filesystems.php:46-48`), so a stored
path of `private/2024/talk.mp3` resolves to `/var/www/html/storage/app/private/2024/talk.mp3`.

Production runs a container image pinned to a git SHA and mounts exactly four persistent volumes
(`docker-compose.prod.yml:36-43`):

```yaml
volumes:
  # Persist local storage (pages, temporary files - sermons are in Spaces)
  - app-storage:/var/www/html/storage/app/public
  - app-temp:/var/www/html/storage/app/temp
  - app-livewire-tmp:/var/www/html/storage/app/livewire-tmp
  - app-logs:/var/www/html/storage/logs
```

`storage/app/private` is not among them. The Dockerfile does not create it either — it makes
`storage/app/livewire-tmp`, `storage/app/temp` and `storage/app/public` and stops there
(`Dockerfile:92`), i.e. precisely the three paths that *are* mounted. So Laravel creates
`storage/app/private` lazily on first write, into the container's ephemeral writable layer.

A deploy sets a new `IMAGE_TAG` and replaces the container. The writable layer goes with it.

**Every children's-talk audio file, video, transcript, thumbnail and thumbnail candidate written
since the last deploy is lost at the next one.** The database rows survive and keep pointing at
paths with nothing behind them, which is why this has been able to run undetected: the sermon
still lists, the page still renders, and only the asset routes 404. The repo is already aware of
this class of bug in another place — `deploy.yml:468` notes a path is "not a persisted volume, so
a fresh image has no sitemap file".

Second population, same fault: `PrepareSectionPublicationCandidates` writes review-time preview
clips to `private/section-publications/{id}/` on a hardcoded `'local'` disk (`:277-283`). Those
are lost on deploy too, which would present to an operator as preview audio/video silently
disappearing from the review UI between deploys.

**WP1 quantifies the damage before anything is changed. Do not assume it is small, and do not
assume it is large.**

### 2.2 `do_spaces_backups` is the template, and it already proves the pattern works

The repo already runs a private disk against the same bucket
(`config/filesystems.php:91-105`), with a comment that reads like it was written for this plan:

```php
// Same Spaces bucket/credentials as do_spaces, but private visibility and
// a dedicated prefix. Backup archives must never inherit the public-read
// visibility (or CDN exposure) the sermon-serving disk requires, and
// throw=true lets a failed upload surface as a BackupHasFailed alert
// instead of a silent false return.
```

So the two properties this plan needs — private visibility on a bucket whose default disk is
`'visibility' => 'public'` with a `cdn_endpoint`, and loud failures via `throw => true` — are
already configured, exercised, and known to work in production against DigitalOcean Spaces.

One deliberate divergence: `do_spaces_backups` sets `'root' => 'backups'`. **The private disk must
not set a root**, because the stored paths already carry their own `private/` prefix. Adding a root
would produce `private/private/…` keys and force a rewrite of every path in the database. Keeping
root empty makes the migration a pure file copy with **zero database writes** — see §3.2.

### 2.3 The serving path is hard-wired to local disks

This is the blocker that dictates work-package order. Private assets are served by reading an
**absolute filesystem path** and returning a `BinaryFileResponse`:

```php
// SermonAssetController.php:85
$path = Storage::disk($fileInfo['disk'])->path($fileInfo['path']);
…
return response()->file($path, [...]);
```

`Storage::disk()->path()` is a local-driver method. On an S3 disk it does not return a fetchable
absolute path, so **flipping the disk config without changing this code breaks every children's
talk asset**. The same pattern appears at `SermonAssetController.php:122` (video) and `:250`
(thumbnails), plus two admin preview controllers (`SermonThumbnailCandidateController.php:43`,
`ServiceSectionCandidateMediaController.php:57`) and one non-serving caller,
`ThumbnailGenerationService.php:562`, which does `Image::decode(Storage::disk($disk)->path($plainPath))`
to render overlay/card variants from a stored plain thumbnail.

There is a second, quieter reason `response()->file()` matters: Symfony's `BinaryFileResponse`
handles HTTP `Range` requests automatically. That is what makes seeking work in the audio and
video players. Any replacement must preserve range support or children's-talk video becomes
unseekable — §3.3 covers this.

### 2.4 The private-vs-local decision is duplicated across eight sites

Every consumer re-derives the disk from the path prefix, independently:

| Site | Shape |
|---|---|
| `SermonAssetController.php:111, 151, 183, 222, 243` | `str_starts_with($path, 'private/') ? 'local' : <configured disk>` |
| `SermonStorageService.php:274` (`resolveThumbnailDisk`) | same ternary |
| `SermonStorageService.php:122` (`resolveFileInfo`) | returns `['type' => 'private', 'disk' => 'local', …]` |
| `ThumbnailGenerationService.php:825, 840` | same ternary |
| `MediaAssetPath::diskForPath()` (`:14-20`) | `isPrivate($path) ? 'local' : $publicDisk ?? sermon_disk` |
| `PrepareSectionPublicationCandidates::candidateDisk()` (`:277`) | hardcoded `return 'local';` |
| `MoveSermonToPrivateStorage::copyAndVerify()` (`:244`) | hardcoded `$target = Storage::disk('local');` |
| `AuditSermonAssetsCommand.php:158` | `$expectedDisk = $isPrivate ? 'local' : $kindDisk;` |

Eight places encoding one rule is the reason this is a work package rather than a one-line change,
and it is also the opportunity: collapsing them onto a single configured seam is what makes the
production flip a single environment variable (§3.2).

### 2.5 Two different things live under `private/`

Worth stating explicitly, because the prefix is overloaded and the plan treats them differently:

| Population | Written by | Lifetime | Why private |
|---|---|---|---|
| Children's-talk sermon assets | `MoveSermonToPrivateStorage` | permanent | not published yet (maintainer, 2026-07-24) |
| Section publication candidates | `PrepareSectionPublicationCandidates` | ephemeral; deleted on publish, governed by `unpublished_expires_at` | review-time preview clips, never public |

Both resolve through the same prefix rule, so both move together on the shared seam. That is a
feature, not collateral: candidates are lost on deploy today for exactly the same reason, and
fixing them costs nothing extra once the seam exists.

### 2.6 Existing test coverage is substantial

26 test files reference `private/`, including `tests/Feature/SermonPrivateAssetTest.php`,
`tests/Feature/Security/ChildrensTalkAssetSecurityTest.php`,
`tests/Feature/Security/SermonPrivateStorageMoveTest.php`,
`tests/Integration/Jobs/MoveSermonToPrivateStorageTest.php` and
`tests/Feature/Console/AuditSermonAssetsCommandTest.php`.

That is good news — the behaviour being preserved is well pinned. It is also the bulk of the
mechanical work: many of these assert against `Storage::disk('local')` or
`Storage::fake('local')` specifically, and must move to the configured private disk so they
exercise the real code path in both configurations.

---

## 3. Design

### 3.1 What "private" means here, and what it does not

The maintainer's clarification rules out the design this would otherwise need. There is no
safeguarding requirement, no confidentiality obligation, and no need to defend against a
determined attacker with a leaked URL. Children's talks are unpublished, not sensitive, and the
long-term intent is to publish them.

Two consequences:

1. **Short-lived signed URLs are an acceptable delivery mechanism.** Had this been safeguarding
   material, handing out a URL that works without an authenticated session — even briefly, even
   once — would have been the wrong shape, and the plan would have had to stream every byte
   through the application to keep authorisation attached to each request. It is not, so it does
   not. This is the single decision that makes §3.3 simple.
2. **The end state is deletion of this machinery, not elaboration of it.** WP7 makes children's
   talks public, at which point `MoveSermonToPrivateStorage`, the `private/` prefix rule, and the
   guarded asset routes for this content type all become removable. Nothing in WP2–WP6 should
   make that harder — no new database columns, no new path conventions, no per-asset state.

**The access gate does not change in this plan.** `SermonAssetController::authorizeAssetAccess()`
still runs `exposurePolicy->canAccessChildrensCorner($user)` before anything is served, and admins
are still exempt. Only what happens *after* authorisation succeeds changes.

### 3.2 One configured seam, no database writes

Add `media-processing.storage.private_disk`, read from `PRIVATE_STORAGE_DISK` and **defaulting to
`local`**, then route all eight sites in §2.4 through it. Default-`local` means WP2 is a pure
refactor that changes no behaviour anywhere — it ships and proves itself before the disk it
enables is ever used.

The new disk mirrors `do_spaces_backups` with the root removed:

```php
// config/filesystems.php
'do_spaces_private' => [
    'driver' => 's3',
    'key' => env('DO_SPACES_KEY', env('DO_SPACES_ACCESS_KEY_ID')),
    'secret' => env('DO_SPACES_SECRET', env('DO_SPACES_SECRET_ACCESS_KEY')),
    'region' => env('DO_SPACES_REGION', env('DO_SPACES_DEFAULT_REGION', 'nyc3')),
    'bucket' => env('DO_SPACES_BUCKET'),
    'endpoint' => env('DO_SPACES_ENDPOINT', 'https://nyc3.digitaloceanspaces.com'),
    // No 'root': stored paths already carry their own `private/` prefix, so the
    // object key is `private/…` and no database path has to be rewritten.
    // No 'cdn_endpoint': signed URLs must be issued against the bucket endpoint,
    // and this content must not be CDN-cached while it is unpublished.
    'use_path_style_endpoint' => false,
    'throw' => true,
    'visibility' => 'private',
    'bucket_endpoint' => true,
],
```

Because the object key is byte-identical to the stored path, migration is:

1. copy `private/…` from local to `do_spaces_private`, verifying size and hash;
2. set `PRIVATE_STORAGE_DISK=do_spaces_private`;
3. delete the local copies once verified.

No migration, no path rewrite, no downtime window, and a rollback that is just flipping the
variable back (§8).

### 3.3 Delivery: signed redirect, not streamed bytes

Replace `Storage::disk()->path()` + `response()->file()` with a redirect to a
`temporaryUrl()` on the private disk. Per §3.1 this is available to us, and it is materially
better than the alternatives:

- **HTTP Range comes back for free.** S3 handles `Range` natively, so seeking in audio and video
  works exactly as `BinaryFileResponse` made it work. A naive `response()->stream()` would not,
  and would have silently broken scrubbing on every children's talk.
- **No application bandwidth.** Serving a 2 GB video through PHP-FPM ties up a worker for the
  duration of the download. Today that is masked because the file is on local disk and
  `response()->file()` can hand off to the web server; on S3 it would not be.
- **The authorisation gate stays exactly where it is.** The controller still authorises, then
  mints a URL instead of bytes.

Design notes that matter:

- **TTL must exceed a viewing session, not a request.** A signed URL is checked when a request is
  made, so a viewer who starts a 25-minute talk and seeks at minute 24 issues a *new* request
  against the same URL. Too short a TTL turns that into a 403 mid-playback. Start at **6 hours**;
  it is long enough that no realistic session outlives it and short enough that a pasted link
  stops working the same day.
- **Sign against the bucket endpoint, never the CDN.** The private disk deliberately omits
  `cdn_endpoint`. `SermonStorageService::resolvePublicUrl()` (`:522-525`) swaps in the CDN host for
  `do_spaces`; the private path must not go near that branch.
- **Non-private assets are unaffected.** Those already redirect to public URLs
  (`SermonAssetController.php:81`, `:118`) — the private branch converges on the same shape, which
  simplifies the controller rather than complicating it.
- **Verify `temporaryUrl()` against Spaces before relying on it.** The disk sets
  `bucket_endpoint => true`; presigned URL generation under that flag should be confirmed against
  a real bucket in WP3, not assumed. If it misbehaves, the fallback is a streamed response with
  explicit `Range` handling — more code, same outcome — and WP3 should not be considered done
  until one of the two is demonstrated working.

### 3.4 Non-serving readers need a temp download

`ThumbnailGenerationService::createRenderedAssetsFromStoredPlainPath()` (`:562`) decodes a stored
plain thumbnail from an absolute path to render the branded variants. That has no URL to redirect
to — it needs actual bytes locally. `StorageAdapterHelper` already implements exactly this
download-to-temp-then-clean-up pattern for S3 sources (`:68-81`, `:290-300`), and
`PrepareSectionPublicationCandidates` already uses it with an `$isS3TempDisk` flag and a `finally`
cleanup (`:216`, `:269-272`). Reuse it; do not invent a second mechanism.

### 3.5 `MoveSermonToPrivateStorage` becomes same-bucket, and that is a footnote not a rewrite

The job currently copies from the sermon disk to a hardcoded `Storage::disk('local')`
(`:242-245`), then deletes the source. Pointing the target at the configured private disk makes it
a Spaces→Spaces copy within one bucket. Its verify-then-delete discipline, compare-and-set path
commits and `isPathReferenced()` guard all still hold, because they are expressed in terms of the
`Storage` API rather than the filesystem.

Two things to note rather than fix in this plan:

- The copy is a read-stream/write-stream round trip through the application. Within one bucket
  a server-side `COPY` would be strictly better, but correctness first — call it out in WP5 and
  leave it as an optimisation.
- With `throw => true` on the private disk, `writeStream()` raises instead of returning `false`,
  so the `$written !== true` check at `:277` becomes unreachable. Harmless, and the surrounding
  `try/catch` already handles throwables — but leave the check, because the *source* disk in a
  local-disk configuration still returns `false`.

---

## 4. Work packages

| WP | What | Kind | Blocked by |
|---|---|---|---|
| **WP0** | **Stopgap: mount `storage/app/private` as a volume** | ops | — |
| WP1 | Quantify the loss: audit private assets in production (read-only) | ops | — |
| WP2 | `do_spaces_private` disk + `private_disk` config seam (default `local`, no behaviour change) | refactor | — |
| WP3 | Serving paths off `->path()` — signed-URL redirect | code | WP2 |
| WP4 | Non-serving readers off `->path()` — thumbnail rendering + admin previews | code | WP2 |
| WP5 | `MoveSermonToPrivateStorage` + candidate writer target the configured disk | code | WP2 |
| WP6 | Migration command, production flip, runbook | ops/code | WP1, WP3, WP4, WP5 |
| WP7 | Publish children's talks — retire the private mechanism | design/code | WP6 |

**WP0 ships first and alone.** Everything else is a week or more of careful work; WP0 is a
two-line change to `docker-compose.prod.yml` that stops files being destroyed in the meantime.
Shipping it does not reduce the case for the rest — a volume on one host is still a single point
of failure with no backup story, which is the argument for Spaces — but there is no reason to keep
losing data while the proper fix lands.

### WP0 — Stop the bleeding

- Add the missing volume to the `app` service in `docker-compose.prod.yml`, alongside the existing
  three:

  ```yaml
  # Children's-talk and section-publication assets (see PRIVATE_STORAGE_DISK).
  # Interim: superseded by do_spaces_private once WP6 lands.
  - app-private:/var/www/html/storage/app/private
  ```

  …and declare `app-private:` in the top-level `volumes:` block.
- Create the directory in the `Dockerfile` alongside the others (`:92`) so ownership and
  permissions are set at build time rather than by whichever process writes first.
- Comment both to say they are interim, referencing this plan — a volume that outlives its reason
  is how the next person concludes the local disk was a deliberate choice.
- **Acceptance:** deploy, write a children's talk asset, deploy again, asset still present.
  Verify by deploying twice rather than by reading the compose file.

### WP1 — Quantify the loss

Read-only, and it produces the number that tells the operator what WP6 has to reconstruct.

- `audit:sermon-assets` already reports `missing` per kind and resolves private paths against the
  `local` disk (`AuditSermonAssetsCommand.php:145-176`). Run it in production via the
  approval-gated `production-audit.yml` workflow — **counts only, never paths or ids**, per the
  repo's production-audit convention.
- Report: how many children's talks exist, how many have referenced private assets, and how many
  of those assets are missing. Expect the missing count to be high and to correlate with deploy
  recency rather than with anything about the talks themselves.
- Do the same for `service_sections.extracted_audio_path` / `extracted_video_path` under
  `private/section-publications/`.
- **Acceptance:** a number, and a decision recorded against it. If assets are gone, the source
  recordings determine whether they are re-derivable: a children's talk extracted from a livestream
  can be regenerated from the processing log if the source media survives, and cannot if it does
  not. That question belongs to whoever reads WP1's output, not to this plan.

### WP2 — The config seam

- Add `'private_disk' => env('PRIVATE_STORAGE_DISK', 'local')` to `config/media-processing.php`
  alongside `sermon_disk`/`transcript_disk`/`temp_disk`, and the `do_spaces_private` disk from
  §3.2 to `config/filesystems.php`.
- Route all eight sites in §2.4 through it. Prefer one accessor over eight `config()` calls —
  `MediaAssetPath` is the natural home, since it already owns `isPrivate()` and `diskForPath()`.
  Give it a `privateDisk(): string` and have the ternaries call it.
- `AuditSermonAssetsCommand` moves too, or the audit checks the wrong disk immediately after the
  flip and reports the entire archive missing.
- **Default stays `local`, so this WP changes no behaviour.** That is the point: it lands and bakes
  independently of the risky part.
- Tests: existing coverage should pass untouched. Add a test asserting the resolved disk follows
  the config in both settings — that single test is what proves the seam is real and not eight
  ternaries that merely look alike.

### WP3 — Serving off `->path()`

- `SermonAssetController::serveAudio()`, `serveVideo()`, `serveThumbnail()`, `servePlainThumbnail()`,
  `serveCardThumbnail()` and `serveStoredThumbnail()`: after authorisation, redirect to
  `Storage::disk($privateDisk)->temporaryUrl($path, now()->addHours(6))`.
- Keep local-disk support: when the configured private disk is a local driver, `temporaryUrl()`
  is unavailable, so retain the `response()->file()` branch. Both configurations must work, because
  development, CI and Dusk all run on the local disk and WP6 flips only production.
- Preserve `Cache-Control: private, no-store` semantics on the redirect response so an unpublished
  talk is not cached by intermediaries.
- Confirm `temporaryUrl()` works against Spaces with `bucket_endpoint => true` (§3.3). If it does
  not, implement the streamed fallback **with explicit `Range` support** and say so in the WP.
- Tests: `SermonAssetControllerTest`, `SermonPrivateAssetTest`, `SermonVideoServingTest`,
  `SermonThumbnailServingTest`, `Security/ChildrensTalkAssetSecurityTest` — each asserting, on
  both disk configurations, that **authorisation still runs first**. The security tests are the
  ones that matter: an unauthenticated request must still redirect to login and must never receive
  a signed URL. Add an explicit test that a signed URL is not issued to an unauthorised caller.

### WP4 — Non-serving readers off `->path()`

- `ThumbnailGenerationService::createRenderedAssetsFromStoredPlainPath()` (`:562`) downloads to
  temp via `StorageAdapterHelper` before `Image::decode()`, cleaning up in a `finally` (§3.4).
- `Admin/SermonThumbnailCandidateController.php:43` and
  `Admin/ServiceSectionCandidateMediaController.php:57` take the same treatment as WP3 — these are
  admin-only previews, so a signed redirect is equally appropriate.
- Tests: `Admin/SermonThumbnailCandidatePreviewTest`,
  `Admin/ServiceSectionCandidateMediaControllerTest`, plus a thumbnail-rendering test proving
  overlay/card variants still generate when the plain thumbnail is on a non-local private disk.

### WP5 — Writers target the configured disk

- `MoveSermonToPrivateStorage::copyAndVerify()` (`:244`) uses the configured private disk instead
  of `Storage::disk('local')`. Same for `verifyCommittedTarget()` (`:297`) and
  `isPathReferenced()`'s disk assumptions.
- `PrepareSectionPublicationCandidates::candidateDisk()` (`:277`) returns the configured disk
  instead of the hardcoded `'local'`. Check the publish/cleanup side
  (`PublishApprovedServiceSection`, `DeleteLivestreamUpload`) moves with it — a candidate written
  to Spaces and deleted from local is a leak, and `DeleteLivestreamUpload`'s per-field disk map is
  the authoritative list of what must agree.
- Note the same-bucket server-side `COPY` optimisation and leave it undone (§3.5).
- Tests: `MoveSermonToPrivateStorageTest`, `Security/SermonPrivateStorageMoveTest`,
  `PrepareSectionPublicationCandidatesTest`, `PublishApprovedServiceSectionTest` — parameterised
  over both disk configurations. Explicitly assert the **source deletion still happens** after a
  verified copy: that is the step which, if it silently no-ops on S3, leaves public copies behind.

### WP6 — Migrate and flip

- New command `media:migrate-private-assets {--to=} {--apply} {--delete-source}`:
  - walks every referenced private path — children's-talk sermon assets (all seven fields plus
    thumbnail candidates) and section-publication candidates;
  - copies each from the current private disk to the target, verifying size and sha256;
  - idempotent: an object already present and matching is a no-op;
  - `--delete-source` is a **separate later invocation**, never the same run as the copy;
  - dry-run by default, consistent with the repo's other one-shot commands.
- Reuse the field enumeration rather than writing a third copy of it — §2.4 of the archive plan
  makes the same point about `MoveSermonToPrivateStorage`'s `$moveOperations` list and
  `SermonObserver::hasNonPrivateProtectedAsset()` already disagreeing in shape. Add a test that
  fails if the lists drift.
- Operator sequence: back up → run copy → verify with `audit:sermon-assets` → set
  `PRIVATE_STORAGE_DISK=do_spaces_private` → deploy → verify again → **only then** delete sources
  → remove WP0's volume mount in a later deploy.
- `docs/operations/private-asset-storage.md`: the sequence above, the rollback (§8), and a note
  that `PRIVATE_STORAGE_DISK` is now a load-bearing production variable.
- **Acceptance:** `audit:sermon-assets` reports zero `missing` and zero `childrens_talk_public`
  after the flip, and a children's talk plays end-to-end with working seek for a verified member.

### WP7 — Publish children's talks (the actual destination)

Recorded here because it is the stated intent and because WP2–WP6 should not make it harder — not
because it must follow immediately.

- The publish operation is `MoveSermonToPrivateStorage` **in reverse**: copy each object from the
  `private/` key prefix to its public key, compare-and-set the paths, delete the private object.
  Within one bucket that is a server-side copy. The job's existing verify-then-delete structure is
  directly reusable, and building it as `MoveSermonToPublicStorage` alongside its inverse is
  cheaper than generalising the existing job.
- Everything downstream then flows through the already-existing non-private branches: public URLs,
  CDN delivery, `SermonExposurePolicy` for visibility. There is no new delivery path to write.
- The guards that enforce today's arrangement must be retired *deliberately and together*:
  `AuditSermonAssetsCommand`'s `childrens_talk_public` finding (`:151`) currently treats a public
  children's talk as a **failure**, and `SermonObserver` (`:47-53`) will re-privatise any talk
  whose assets go public. Publishing without changing both means the observer immediately undoes
  the publish and the audit reports the attempt as a fault — a loop that would look like a bug.
- `SermonPromotionAssets::guardPortablePath()`'s `private/` rejection (`:167`) can also go, and
  with it the archive plan's WP8 entirely.
- **This is a content/policy decision, not a technical one.** The plan is ready when the maintainer
  says children's talks are public; nothing above should be built speculatively.

---

## 5. Interactions

### 5.1 This plan substantially deletes the archive plan's WP8

`HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md` §2.6 exists solely because children's-talk
media ends up on the import machine's local disk and is deleted from the bucket. Its WP8 builds a
manifest, a restore command, a staging-and-privatise dance, and a capacity check for production's
local disk.

**If this plan lands first, almost all of that becomes unnecessary.** With `PRIVATE_STORAGE_DISK`
pointing at Spaces on the import machine, a locally-imported children's talk writes straight to the
shared bucket under `private/…`, exactly where production will look for it. Promotion returns to
being a database-row operation, `SermonPromotionAssets` needs only its `private/` guard relaxed for
the archive bundle's manifest, and WP8 collapses to "verify the objects exist".

The remaining ordering question for whoever schedules both: the archive plan's Stage A is gated on
its own WP-A\* retention workstream, and this plan is gated on nothing. **Doing this one first is
strictly cheaper.** If the import starts first, WP8 has to be built and then the talks re-handled
after this lands.

### 5.2 Local development and CI are unaffected

`PRIVATE_STORAGE_DISK` defaults to `local`, so development, the parallel suite, and Dusk keep
today's behaviour with no `.env` change. WP3's dual-branch requirement (§WP3) is what keeps that
true, and it is the reason the tests must be parameterised over both configurations rather than
simply switched to the new disk.

---

## 6. Risks

| Risk | Mitigation |
|---|---|
| **Assets already lost in production (§2.1)** | WP1 quantifies before anything changes; WP0 stops further loss within one deploy |
| Flipping the disk before WP3/WP4 → 500s on every children's-talk asset (`->path()` on S3) | Explicit ordering; WP6 blocked on WP3, WP4, WP5; header prohibition (b) |
| Signed URL replaces authorisation instead of following it | WP3's security tests assert an unauthorised caller receives a redirect to login and never a signed URL |
| Video seeking breaks (Range lost with `BinaryFileResponse`) | Signed redirect keeps Range at S3 (§3.3); streamed fallback must implement Range explicitly |
| `temporaryUrl()` misbehaves under `bucket_endpoint => true` | Verified in WP3 against a real bucket, not assumed; documented fallback |
| Signed URL leaks and outlives the session | Accepted: 6h TTL, and the content is destined to be public (§3.1). Revisit only if that premise changes |
| Private objects inherit the bucket's public visibility | Disk sets `'visibility' => 'private'` and omits `cdn_endpoint`, mirroring the proven `do_spaces_backups` config; WP6 verifies with an unauthenticated fetch of a known key |
| Source deletion silently no-ops on S3, leaving public copies | WP5 asserts deletion after verified copy; `--delete-source` is a separate WP6 invocation after the audit passes |
| Path rewrite bugs during migration | There is no path rewrite — the disk has no root and keys equal stored paths (§3.2) |
| Field lists drift between the mover, the observer and the migrator | WP6 adds a drift test; the same hazard is already flagged in the archive plan's WP8 |
| WP0's volume outlives its purpose and is mistaken for design | Comment references this plan; WP6's final step removes it |
| Section-publication candidates move unintentionally | Deliberate (§2.5) — they are lost on deploy today for the same reason; WP5 covers their publish/cleanup path |

## 7. What this plan does not do

- It does not publish children's talks. That is WP7 and a maintainer decision.
- It does not change who can access a children's talk. The `canAccessChildrensCorner` gate is
  untouched.
- It does not move any other asset class. Sermon audio, video and transcripts are already in
  Spaces; temp-disk artifacts are addressed by the archive plan's WP-A\* workstream.
- It does not introduce a second bucket. One bucket, one prefix, private visibility — the
  arrangement `do_spaces_backups` already runs.

## 8. Rollback

Rollback is a configuration change, which is the main argument for the §3.2 design:

- **Before sources are deleted:** set `PRIVATE_STORAGE_DISK=local` and deploy. The local copies are
  still present and byte-identical, and no database row was ever changed. Recovery is one deploy.
- **After sources are deleted:** re-run `media:migrate-private-assets --to=local` in the reverse
  direction, then flip. The Spaces objects are the source of truth at that point.
- **WP0's volume is the floor.** Keep it mounted until the flip has survived several deploys and
  `audit:sermon-assets` is clean; removing it is the last step, not part of the flip.
- Objects are never deleted in the same operation that copies them, in either direction. That is
  what makes both rollbacks non-destructive.
