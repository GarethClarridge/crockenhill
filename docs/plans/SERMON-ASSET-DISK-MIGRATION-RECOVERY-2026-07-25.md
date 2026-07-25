# Recover Sermon Assets Orphaned by the Spaces Disk Migration

> **Status (2026-07-25): evidence gathered, nothing started.** Discovered while running WP1 of
> [CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md](../archived-plans/CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md)
> against production. Nothing here is urgent: the loss stopped when the disk config changed, and no
> further assets are being orphaned.
>
> **Agents must not, without maintainer input:** (a) write to production storage — WP2 is the only
> work package that does, and its run needs explicit sign-off; (b) delete or tidy
> `storage/app/transcripts` on the maintainer's laptop, which is currently the **only** surviving copy
> of the material WP3 might restore; (c) "fix" `audit:sermon-assets` — it is correct, see §2.5.

---

## 1. What this is, in one paragraph

Sermon audio and video were migrated to `do_spaces` and are intact. Transcripts and thumbnails had
their disk *configuration* repointed at Spaces without their *files* being moved, so 91 of 839
referenced production assets resolve to keys that do not exist. The two families then diverged on
whether their old home survived deploys: thumbnails lived on the `public` disk, which production
mounts, so 61 objects are stranded but intact and can simply be copied to Spaces under keys the
database already records. Transcripts lived on the `local` disk at `storage/app/transcripts`, which
production has never mounted, so those 35 were destroyed — the same mechanism the children's-talk plan
was written to fix, at a path nobody had enumerated. This plan measures the overlap with a local
transcript corpus, restores what can be *proven* to match, and adds a stranded-asset finding to the
audit so the next disk repointing is caught immediately rather than a year later.

---

## 2. Current state (evidence)

All figures measured against production on 2026-07-25.

### 2.1 The 91 missing, and how they split

`audit:sermon-assets` on production, counts only:

| Asset kind | Referenced | Present | Missing |
|---|---|---|---|
| audio | 700 | 700 | **0** |
| video | 36 | 36 | **0** |
| transcript | 41 | 6 | **35** |
| thumbnail | 34 | 6 | 28 |
| plain_thumbnail | 6 | 0 | 6 |
| card_thumbnail | 2 | 0 | 2 |
| overlay_thumbnail | 6 | 0 | 6 |
| candidate_plain | 10 | 0 | 10 |
| candidate_card | 2 | 0 | 2 |
| candidate_overlay | 2 | 0 | 2 |
| **total** | **839** | **748** | **91** |

Zero private references and zero check errors. Audio and video are perfect; the 91 are entirely
transcripts (35) and the thumbnail family (56).

### 2.2 Thumbnails: stranded but intact

`thumbnail-generation.storage.disk` is `env('THUMBNAIL_STORAGE_DISK', 'public')`
(`config/thumbnail-generation.php`), and production now resolves it to `do_spaces`. The objects
predating that change were written to the `public` disk and never moved.

**Production has 61 files under `storage/app/public/sermons/thumbnails`.** That path is mounted
(`app-storage` in `docker-compose.prod.yml`), which is why they survived every deploy. 61 available
against 56 missing, so the population is plausibly complete — but that is a count, not a per-path
match, and WP1 must establish the real overlap rather than assume it.

The database paths need no rewriting: a row holding `sermons/thumbnails/sermon_842_…_overlay.webp`
becomes correct the moment that key exists on `do_spaces`.

### 2.3 Transcripts: destroyed, because their old home was never mounted

`media-processing.storage.transcript_disk` resolved to the `local` disk before the migration, whose
root is `storage_path('app')`, so `transcripts/sermon_39.md` lived at
`/var/www/html/storage/app/transcripts/sermon_39.md`.

`storage/app/transcripts` is **not** in `PERSISTED_STORAGE_PATHS`
(`tests/Feature/Config/ProductionStoragePersistenceTest.php`, whose `PERSISTED_STORAGE_PATHS` lists
`storage/app/public`, `storage/app/temp`, `storage/app/livewire-tmp`, `storage/app/livestream` and
`storage/logs`) and is not mounted in `docker-compose.prod.yml`. So every transcript written there was
destroyed at the next deploy — precisely the §2.1 mechanism of the children's-talk plan, applied to a
path that plan never enumerated.

**Production confirms total loss: 0 files under `storage/app/transcripts` and 0 under
`storage/app/public/transcripts`.**

### 2.4 The fallback chain that made this look live, and why it is not

`config/media-processing.php:56`:

```php
'transcript_disk' => env('TRANSCRIPT_STORAGE_DISK', env('SERMON_STORAGE_DISK', env('FILESYSTEM_DISK', 'local'))),
```

That chain terminates at `'local'`, so an environment setting none of the three would silently write
transcripts to an unpersisted path and lose them on every deploy. Production sets
`SERMON_STORAGE_DISK`, so it inherits `do_spaces`:

```
sermon_disk ......... do_spaces
transcript_disk ..... do_spaces
temp_disk ........... local        (paths.temp = temp/media-processing → under the mounted storage/app/temp)
```

**So the loss is historic and no new volume mount is required.** Adding `storage/app/transcripts` to
`PERSISTED_STORAGE_PATHS` was considered and rejected: nothing writes there any more, so the guard
would protect a dead path. The *config* is the thing worth guarding — see WP4.

### 2.5 `audit:sermon-assets` is correct — do not "fix" it

The first hypothesis was that the audit mis-resolved the thumbnail disk, because production and local
showed the same total wipe of the same asset family. That was wrong, twice over:

1. **Writer and readers share one config key.** `ThumbnailGenerationService:95` writes to
   `config('thumbnail-generation.storage.disk')`, and every reader resolves the same key:
   `SermonStorageService:83`, `SermonAssetController:152/184/223/244`, `SermonPromotionAssets:160`,
   `AuditSermonAssetsCommand:83`. There is no disagreement in code to find.
2. **Local and production are not independent evidence.** They share the `do_spaces` bucket, so
   identical audit shapes are expected and corroborate nothing.

Probing a concrete key settled it: `sermons/thumbnails/sermon_842_2026-05-08_candidate-5_overlay.webp`
is absent from `do_spaces` and present on `public`; `transcripts/sermon_39.md` is absent from
`do_spaces` and present on `public` and `local`. The audit is reporting reality.

What the audit *could* do better is say **where the file actually is** instead of only that it is
missing — that is WP4, and it would have turned this investigation into a single command.

### 2.6 The local transcript corpus is not a clean restore source

The maintainer's laptop holds **235 files** under `transcripts/` on the `local` disk (plus 14 on
`public`). This is currently the only surviving copy of anything overlapping the 35 production losses.
It is *not* a straightforward restore source:

- **Two naming generations.** `transcripts/sermon-1.txt` (hyphen, `.txt`) and
  `transcripts/sermon_39.md` (underscore, `.md`). Production rows reference the underscore/`.md` form.
  Only **88** of the 235 match `sermon_{id}`.
- **The id space does not line up.** Those 88 span ids **1–999**, while the local database's highest
  sermon id is **870**. So the tree accumulated across multiple database states and includes files
  keyed to ids that no longer mean anything locally.
- **The databases have diverged.** Local holds 832 sermons (825 with audio); production references 700
  audio assets. Local is not a subset or a current mirror of production.

**Therefore filename-id matching is not sufficient evidence of identity.** Restoring
`transcripts/sermon_39.md` onto production's sermon 39 because the numbers agree risks attaching one
sermon's transcript to another — worse than leaving it missing, because it is wrong rather than absent,
and it would surface as body text on a public page. §4 WP3 gates on content verification.

---

## 3. Design

### 3.1 Two independent recoveries, sequenced by confidence

Thumbnails are a mechanical copy with the paths already correct and the bytes verifiably present, so
they carry near-zero risk. Transcripts require proving identity per file before anything is written.
They share no code and should not share a work package.

### 3.2 Prove-then-copy, never copy-then-check

Both recoveries copy rather than move, verify the target before considering a source disposable, and
never delete a source in the same run that writes a target. This mirrors the sequencing that made
`MoveSermonToPrivateStorage` safe, and is the reason rollback is trivial (§8).

### 3.3 The real fix is detection, not restoration

A disk repointing silently orphaning its existing objects is the underlying fault, and it went
unnoticed for months because the audit could only say "missing". WP4 makes the audit report
`stranded on <disk>` when a referenced path is absent from its expected disk but present on another
known one. That converts the next occurrence from an archaeology exercise into a line of output, and
it is cheap: the audit already loops every referenced asset and already knows every candidate disk.

---

## 4. Work packages

| WP | What | Kind | Blocked by |
|---|---|---|---|
| WP1 | Measure the real overlap for both families (read-only) | ops | — |
| WP2 | Restore the stranded thumbnails to Spaces | code/ops | WP1 |
| WP3 | Restore provably-identified transcripts | code/ops | WP1, content verification |
| WP4 | Teach the audit to report stranded assets | code | — (do first if convenient) |

### WP1 — Measure, before building anything

Read-only, on the server. Two questions, because §2.2 and §2.6 give counts rather than matches:

- **Which** assets are missing: `audit:sermon-assets --details` on the server (never through
  `production-audit.yml` — that log is public; `--details` prints sermon ids).
- **Which** stranded objects exist, per path: for each missing thumbnail path, does that exact key
  exist on the `public` disk? For each missing transcript path, is there a local file at that exact
  path on the laptop?

This yields four numbers that size WP2 and WP3: thumbnails recoverable / unrecoverable, transcripts
candidate-matched / unmatched.

- **Acceptance:** a per-path overlap figure for each family, not a count comparison.

### WP2 — Restore the stranded thumbnails

Expected to cover most or all of the 56. A command in the shape of the (now-deleted) private mover:
iterate sermons' thumbnail-family paths, and for each path absent from the configured disk but present
on `public`, stream it across and verify size before recording success. Dry-run by default; sources
left in place; no path column is ever written, because the paths are already right.

- Idempotent — a target already present and size-matched is a no-op.
- Per-asset failure collection, so one bad object does not abandon the rest.
- Tests: a stranded object is copied and verified; an object already on the target disk is untouched;
  an object missing from *both* disks is reported, not silently skipped; sources survive the run.
- **Acceptance:** `audit:sermon-assets` thumbnail-family `missing` drops to whatever WP1 found
  genuinely absent, and a sermon page renders its thumbnail.

### WP3 — Restore only provably-identified transcripts

**Gated on identity, not filenames (§2.6).** Do not build this until WP1 reports the candidate set and
a verification method has been agreed. Candidate methods, cheapest first:

- Compare the transcript's opening text against the sermon's title and scripture reference.
- Compare against the sermon's audio duration via word count, as a coarse plausibility check.
- Spot-check by ear against the audio for a sample, and treat a failure as disqualifying the whole
  batch rather than just that file.

If identity cannot be established for a file, **leave the transcript missing.** A missing transcript
degrades a page; a wrong one misrepresents a preacher.

- **Acceptance:** every restored transcript has a recorded reason for believing it belongs to that
  sermon, and the unmatched remainder is written down as accepted loss.

### WP4 — Teach the audit to report stranded assets

`AuditSermonAssetsCommand::auditAsset()` (`:196-236`) currently records `missing` when the path is
absent from `$expectedDisk`. Extend it: on a miss, probe the other known disks (`public`, `local`,
`do_spaces`, the transcript disk) and, if found, count it as `stranded` with the disk name, keeping it
distinct from `missing`. Counts only — a disk *name* is not a path and is safe for the public log.

- A test asserts `present + missing + stranded + check_errors == referenced`, so a future asset kind
  cannot fall outside the partition.
- Keep the failure condition red for stranded assets: stranded is still broken from a visitor's
  perspective.
- **Acceptance:** running it today reports the 56 thumbnails as stranded-on-`public` and the 35
  transcripts as genuinely missing — i.e. it reproduces this investigation's conclusion automatically.

---

## 5. Interactions

- **Children's-talk plan.** Independent, and now **complete and archived** (2026-07-25). Its WP3b
  deleted `MoveSermonToPrivateStorage`, whose copy-verify-commit structure is the model WP2 should
  follow: verify-before-delete, compare-and-set path commits under `lockForUpdate()`, per-asset
  failure collection so one failure cannot leave the rest half-moved. **Read it from git history**
  (`git show 238ad6f71^:app/Jobs/MoveSermonToPrivateStorage.php`) rather than looking for the file.
- **Historic archive import.** If WP3's overlap is poor, re-transcription or archive re-import becomes
  the only route for those 35, which is that plan's territory. Note that §2.1 of the children's-talk
  plan found source recordings for older runs were themselves destroyed, so re-transcription may not be
  available either.
- **`audit:sermon-assets` as a verification tool.** WP4 improves the instrument every other plan relies
  on to verify a migration, which is why it is listed as safe to do first.

---

## 6. Risks

| Risk | Mitigation |
|---|---|
| **A transcript is restored onto the wrong sermon** | WP3 gates on content verification, never filename ids (§2.6); unmatched files stay unrestored |
| The 61 stranded thumbnails do not correspond to the 56 missing paths | WP1 measures per-path overlap before WP2 is written; a count comparison is explicitly not sufficient |
| The only copy of the transcript corpus is deleted during routine tidying | Recorded in the plan header and in memory; do not clear `storage/app/transcripts` on the laptop |
| WP2 writes to production storage | Additive only, sources retained, dry-run default, and the run needs explicit maintainer sign-off |
| Someone "fixes" the audit instead of the data | §2.5 records why it is correct, with the disproof |
| A future disk repointing orphans assets again | WP4 makes it visible in one command; the `transcript_disk` fallback chain (§2.4) is the specific trap |
| Stranded assets are treated as acceptable because the bytes exist | WP4 keeps stranded in the failure condition — a visitor still gets a 404 |

## 7. What this plan does not do

- It does not add a volume mount. Nothing writes to `storage/app/transcripts` any more (§2.4).
- It does not change any disk configuration, or move audio or video, which are intact.
- It does not rewrite any asset path column. Both recoveries make existing paths correct by putting
  bytes where the paths already point.
- It does not re-transcribe or regenerate anything. Regeneration is a fallback to consider only if
  WP1 shows poor overlap, and it depends on source recordings that may not exist.

## 8. Rollback

- **WP1** is read-only.
- **WP2** is additive: it writes objects to Spaces under keys the database already references and
  deletes nothing. Rollback is deleting the copied objects, which returns the system to today's state.
- **WP3** is additive per file. A transcript later found to be misattributed is removed by deleting the
  object and nulling the column — but the safeguard is not restoring it in the first place.
- **WP4** is a reporting change with no data effect.
