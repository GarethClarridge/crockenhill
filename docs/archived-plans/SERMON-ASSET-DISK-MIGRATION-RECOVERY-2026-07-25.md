# Recover Sermon Assets Orphaned by the Spaces Disk Migration

> **ARCHIVED 2026-07-25 — complete.** All four work packages are resolved: WP4 (stranded reporting)
> and WP2 (`media:restore-stranded-thumbnails`) shipped and ran against production, WP1's measurement
> was discharged by running them, and WP3 is closed as accepted loss. Production went from
> **748/839 assets present to 804/839**, with **0 stranded and 0 check errors**. Written, shipped and
> run inside a single day.
>
> **Read this header, not the status header below it**, which is a snapshot from just before the
> production runs.
>
> **The headline finding, because it disposes of the plan's hardest problem without answering it.**
> §2.6 built a careful case that transcripts could only be restored after per-file identity
> verification — two naming generations, ids spanning 1–999 against a diverged database, and the risk
> of attributing one preacher's words to another. That argument is sound and still worth reading. It
> was also never reachable: production's missing transcripts are sermons **718–757**, and the entire
> 235-file laptop corpus covers ids **1–261** plus a stray 999. **The intersection is empty in all
> four naming shapes**, so there was no candidate set to verify. A single `ls | grep -oE '[0-9]+' |
> sort -n` would have closed WP3 on day one — **check the candidate set's range before designing the
> method that validates its members.** See §9.5.
>
> **What production looks like now.** Every thumbnail kind reads `Present = Referenced`; the whole
> family (`thumbnail`, `plain`/`card`/`overlay`, and the three candidate kinds) resolves on
> `do_spaces` under the keys the database already held — no path column was ever rewritten. The 35
> missing transcripts are permanent and are recorded as accepted loss, with the reason in §9.5. The
> audit therefore still exits non-zero; **that is correct, not a regression.** Verified end to end:
> five thumbnail URLs spanning the affected range (733, 750, 758, 763, 764) return 200 through
> `SermonAssetController::serveThumbnail`, so the recovery is proven at the read path and not merely
> at the bucket.
>
> **What WP4 left behind, and why it matters more than the restore.** `audit:sermon-assets` now
> reports `stranded on <disk>` distinct from `missing`, probing `public` + `local` + whichever disks
> are *configured* as kind disks. The next disk repointing that orphans its objects shows up as a
> line of output instead of an archaeology exercise — §3.3's claim, now demonstrated: WP4 reproduced
> this entire investigation's conclusion in one command.
>
> **Residue carried forward — one operator decision, no code:**
>
> 1. **The stranded sources are still on production's `public` disk** (~61 files under
>    `storage/app/public/sermons/thumbnails`). WP2 copied rather than moved, so these are its
>    rollback (§8) and nothing schedules their removal. Deleting them is a deliberate decision to
>    give up that rollback, not tidying. No deadline — they sit on a mounted volume and cost only
>    disk. See §9.6.
>
> **Do not**, without maintainer input: re-run `media:restore-stranded-thumbnails --apply`
> speculatively; delete `storage/app/public/sermons/thumbnails` on production; "fix"
> `audit:sermon-assets` (§2.5 records the disproof); or attempt to fill *any* sermon's empty
> transcript column from the laptop corpus by filename id — §2.6 explains why that is dangerous
> rather than merely unreliable, and §9.5 confirms it buys nothing here. The laptop's
> `storage/app/transcripts` is no longer load-bearing for this plan but remains the only copy of
> those 235 files, so keep it.

> **Status (2026-07-25): COMPLETE. WP1, WP2 and WP4 done in production; WP3 closed as accepted loss.**
> Production now reports **804/839 assets present, 0 stranded, 0 check errors** — the entire thumbnail
> family recovered. The 35 missing transcripts are permanent: §9.5 measured the laptop corpus against
> the missing sermon ids and found **zero overlap**, so there was never a candidate set to verify.
> See §9.4 for the production run and §9.5 for the WP3 closure.
>
> **One deliberate residue:** the stranded sources are still on the `public` disk (~61 files under
> `storage/app/public/sermons/thumbnails`). They are the rollback for WP2 and nothing schedules their
> removal — see §9.6.
>
> **Originally: evidence gathered, nothing started.** Discovered while running WP1 of
> [CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md](CHILDRENS-TALK-STORAGE-TO-SPACES-2026-07-24.md)
> against production. Nothing here is urgent: the loss stopped when the disk config changed, and no
> further assets are being orphaned.
>
> **Agents must not, without maintainer input:** (a) write to production storage — WP2 is the only
> work package that does, and its run is now **done**; do not re-run `--apply` speculatively;
> (b) delete `storage/app/public/sermons/thumbnails` on production, which is WP2's rollback (§9.6);
> (c) "fix" `audit:sermon-assets` — it is correct, see §2.5; (d) attempt to match the laptop transcript
> corpus to production sermons by filename id — §9.5 closed that off, and §2.6 explains why it is
> actively dangerous rather than merely unreliable.
>
> The laptop's `storage/app/transcripts` is **no longer** load-bearing for this plan (§9.5), but it is
> still the only copy of 235 transcripts, so keep it.

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

| WP | What | Kind | Blocked by | Status |
|---|---|---|---|---|
| WP1 | Measure the real overlap for both families (read-only) | ops | — | **done in production** (§9.4) — 56/56 per-path match |
| WP2 | Restore the stranded thumbnails to Spaces | code/ops | WP1 | **done in production** (§9.4) — 50 objects, 0 failures |
| WP3 | Restore provably-identified transcripts | code/ops | WP1, content verification | **closed — accepted loss** (§9.5); no candidate set exists |
| WP4 | Teach the audit to report stranded assets | code | — (do first if convenient) | **done** |

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

---

## 9. Implementation record (2026-07-25)

### 9.1 What landed

**A shared asset enumeration** (`app/Support/SermonAssetReferences.php`). Both commands now read one
list of `[kind, disk, path]` tuples. This is not tidying: WP2's acceptance criterion is stated in
terms of the audit's numbers, so if the two enumerated the thumbnail family differently there would be
no way to verify a restore against an audit. `AuditSermonAssetsCommand::referencedAssets()` moved here
unchanged.

**WP4 — stranded reporting.** `auditAsset()` now probes the other known disks on a miss and records
`stranded` with the disk name, distinct from `missing`. Three details worth keeping:

- The probe list is `public` + `local` + whichever disks are *configured* as kind disks — not a
  hardcoded `do_spaces`. A hardcoded remote disk would have made the audit build an S3 client for
  every installation that does not use one, including the test suite.
- A probe that throws is treated as "not here", not as a `check_error`. The expected disk already
  answered, so the asset's status is known; an unreachable secondary disk must not downgrade a
  definite `missing`.
- Probes only run for assets already known to be absent, so a clean audit costs nothing extra.

`stranded` stays inside the failure condition, per §6 — every reader resolves the configured disk, so
a visitor still gets a 404. A test asserts the partition
`present + missing + stranded + check_errors == referenced`.

**WP2 — `media:restore-stranded-thumbnails`.** Dry-run by default; `--apply` copies. Streams each
object across, verifies size, never deletes a source, never writes a path column. Beyond the plan's
requirements:

- **It never overwrites a live key.** An object present on the target at a *different* size from its
  source is reported as `size_mismatch` and left alone. Silently calling that "already present" would
  hide a truncated upload; overwriting it would guess which copy is right.
- **Legacy `private/` rows are skipped, not guessed at.** They resolve to the local disk, so the key
  this command would write is not the key the row references.
- **One object referenced twice is copied once.** The report therefore carries two units: `objects`
  (distinct storage keys — what happened to the disks) and `references` (per kind, the audit's unit).
  The per-kind table lines up with the audit's row for row; the summary line reports distinct objects.
- `--sermon=` limits the run, which is how the plan's "a sermon page renders its thumbnail" acceptance
  check is done on one sermon before committing to the archive.

### 9.2 WP1 is now a command, not an investigation

§3.3 predicted this and it held. WP4 answers WP1's first question (*which* assets, and whether the
bytes survive elsewhere) and WP2's dry run answers the second (*per-path* overlap, not a count
comparison). WP1 no longer needs bespoke work — it is two read-only commands.

**Measured locally on 2026-07-25** (local shares the production Spaces bucket, so the disk resolution
is identical, but the database is a different population — 832 sermons vs production's):

```
audit:sermon-assets            1002 referenced, 858 present, 0 missing, 144 stranded, 0 check errors
                               all 144 stranded on `public`
media:restore-stranded-…       143 thumbnail-family references restorable (91 distinct objects)
                               0 unrecoverable, 0 size mismatches
```

Every asset the audit previously called `missing` is in fact `stranded on public`. That is the
investigation's conclusion, reproduced by one command, which was WP4's acceptance criterion.

**One transcript is stranded on `public` too.** This sharpens WP3 in a way §2.6 could not: a transcript
found on another disk *under the exact key its own row records* needs no identity verification at all
— it is production's own file at production's own path, not a filename-id guess across diverged
databases. §2.3 measured 0 files under production's `storage/app/public/transcripts`, so the
expectation is that production's 35 are all genuinely `missing` and none get this easy route. **The
production WP4 run settles it**, and the answer changes how much of WP3 remains.

### 9.3 What the maintainer had to do — all discharged

The three outstanding items (production WP1, WP2 sign-off, WP3 decision) were completed on
2026-07-25. §9.4 records the runs, §9.5 the WP3 closure, §9.6 the one thing left open.

### 9.4 The production runs (2026-07-25)

**WP1 — measurement.** `audit:sermon-assets --details` and `media:restore-stranded-thumbnails`
(dry run), both on the server. The two reports matched **row for row**:

| Asset kind | Audit `stranded` | Restore `restorable` |
|---|---|---|
| thumbnail | 28 | 28 |
| plain_thumbnail | 6 | 6 |
| card_thumbnail | 2 | 2 |
| overlay_thumbnail | 6 | 6 |
| candidate_plain | 10 | 10 |
| candidate_card | 2 | 2 |
| candidate_overlay | 2 | 2 |
| **total** | **56** | **56** |

0 unrecoverable, 0 size mismatches, 0 failures, 0 skipped-private. **This is WP1's acceptance
criterion met exactly** — a per-path overlap, not the count comparison §2.2 refused. The 56 references
resolve to 44 distinct objects, plus 6 already present.

The stranded sermons are **733–764, contiguous** — the fingerprint of a single config change, not
gradual decay, which independently corroborates §2.2's account.

**WP2 — restore.** Smoke test on sermon 763 first (the richest case: all seven kinds plus the dedup
path), then the full `--apply`. Final state:

```
audit:sermon-assets   839 referenced, 804 present, 35 missing, 0 stranded, 0 check errors
                      every thumbnail kind at Present = Referenced
```

**The two-unit reporting earned its keep here, and it is worth recording why.** Between the dry run
and the apply run, `already_present` jumped 6 → 17 and `restored` came in at 45 rather than 56 — which
in isolation is indistinguishable from a double-copy bug or a miscount. It reconciles precisely once
the smoke test is accounted for: sermon 763 is 11 references / 7 distinct objects, so `6+11=17`,
`56−11=45`, `6+7=13`, `44−7=37`. Both runs total **62 references / 50 objects**. With a single tally
there would have been no way to distinguish a correct run from a broken one short of listing the
bucket by hand.

The audit still exits non-zero, because of the 35 transcripts. That is correct behaviour, not a
regression — see §9.5.

### 9.5 WP3 — closed as accepted loss

**The gate §4 described was never reachable, because there is no candidate set.**

Production's missing transcripts are sermons **718–757** (from `--details`). The laptop corpus at
`storage/app/transcripts` holds 235 files in four naming shapes:

| Shape | Count |
|---|---|
| `sermon-{n}.txt` | 146 |
| `sermon_{n}_transcript.txt` | 55 |
| `sermon_{n}.md` | 33 |
| `transcript_{n}.md` | 1 |

Every id appearing in any of those shapes falls in **1–261**, plus a single stray `999`. **The
intersection with 718–757 is empty in all four shapes.** Nothing in the corpus could be restored onto
a sermon that is missing a transcript, whatever verification method were agreed.

This also disposes of the hopeful case §9.2 raised. Production reported **0 stranded transcripts**,
unlike local's 1, confirming §2.3's measurement that `storage/app/public/transcripts` is empty in
production. There was no easy route and there was no hard route either.

**Decision: the 35 transcripts are permanently lost and are recorded as accepted loss.** Per §4's
acceptance criterion, the reason is written down here rather than left implicit: they were destroyed
by deploys before the disk was repointed (§2.3), production holds no copy on any disk, and the only
surviving corpus does not reach their id range. §4's instruction stands — *a missing transcript
degrades a page; a wrong one misrepresents a preacher* — and with zero overlap there is not even a
tempting wrong answer available.

**Do not revisit this by matching filenames to ids.** §2.6's argument (two naming generations, ids
spanning 1–999 against a database whose highest local id is 870, two diverged populations) is not
weakened by the corpus being useless here — it is the reason the corpus cannot be repurposed to fill
*other* sermons' empty transcript columns either. That would be new scope, and it belongs to the
historic archive import plan under its own evidence rules.

Re-transcription remains theoretically open but is out of scope: §2.1 of the children's-talk plan found
source recordings for older runs were themselves destroyed, so it is not known to be available.

### 9.6 The one open item: the stranded sources

WP2 copied rather than moved, so roughly **61 files remain under
`storage/app/public/sermons/thumbnails` on production**. They are now redundant — every referenced key
exists on `do_spaces` — but they are also WP2's rollback (§8), and nothing schedules their removal.

Leave them until the thumbnails have been observed rendering across the archive rather than on the one
smoke-tested sermon. Deleting them is a deliberate decision to give up the rollback, not tidying, and
it should be taken as such. There is no deadline: they sit on a mounted volume and cost only disk.
