# R8 data convergence and one-shot retirement runbook

Written 2026-07-20. This is the operator runbook for R8 items 2.4 and 2.6 in the
[July 2026 simplification remainder plan](../plans/JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md).
The command implementations and `docker-compose.prod.yml` win if this document drifts.

## Outcome and authority

Converge durable church data before deleting its one-shot importers. This is deliberately not a
bidirectional database sync:

1. Original source artifacts are imported through the current application paths.
2. Production becomes authoritative after the promotion is reviewed.
3. Local is a rehearsal/projection environment. Keep it close by importing the same sources and
   comparing stable domain identities, not by copying production primary keys or operational state.

Authorities by domain:

| Domain | Authority | Production action | Local action |
|---|---|---|---|
| Song catalogue | Current OpenLP songs SQLite | Sync from the checksummed SQLite | Sync from the identical SQLite |
| Historic service plans | Original OpenLP `.osz` archive | Import the complete checksummed directory | Import/rehearse the same directory |
| Historic email OoS | Original Markdown archive | Evaluate, then import in batches through the live pipeline | Already imported; rerun only from the same/newer artifact |
| Legacy MP3 sermons | Local processed records plus their verified Spaces objects and source hashes | Promote selected local-only records through a purpose-built create-only bundle; do not upload or reprocess the audio again | Preserve until every local-only candidate is classified and promoted/rejected |
| Other sermons/video | Original media plus its metadata | Re-import selected material through the current pipeline when no safe portable record exists | Preserve until every local-only candidate is classified |
| Legacy song usage | Production `play_date` IDs | Dump and import in production | Do not import the production dump locally |
| Runtime/auth data | Production | Keep in production | Never copy raw users, tokens, sessions or real inbound email bodies |

### What local rehearsal does and does not buy

Local runs first for two reasons: processing is free here, and a mistake here is not a mistake in
production. This runbook currently delivers the second in full and the first only in part, and the
difference is worth stating plainly before anyone reads a local result as a prediction.

| Work | Where it happens | Consequence |
|---|---|---|
| OpenLP `.osz` parsing | Both, deterministic | Local outcome predicts production exactly. |
| Legacy MP3 sermons | Locally only; promoted as a create-only bundle (Phase 6) | The intended shape: expensive work done once, its result shipped. |
| Markdown OoS extraction | Both; the extractor runs again in production | 101 further model calls, and production may parse an entry differently from the copy the operator reviewed locally. |
| Historic video | Both; re-processed through the production pipeline | Transcription and structure detection paid for twice, and the production run may not reproduce the local one. |

The parse cache that would avoid the second row lives per-row in
`inbound_emails.processing_metadata.parsing`, keyed by `input_hash` + `PARSER_VERSION`
(`ImportOosArchiveCommand`), so it is reused across re-runs in one environment but has no portable
export. There is no equivalent for a processed video.

Until a portable form exists, treat the local run as a **rehearsal, not a projection**: it proves
the commands, the counts and the operator's decisions, and it does not guarantee that production
will extract the same plan from the same markdown. §7.1 and §7.2 are the checks that catch a
divergence after the fact.

Closing the gap is planned separately, in
[LOCAL-PROCESSING-PORTABILITY-2026-07-28.md](../plans/LOCAL-PROCESSING-PORTABILITY-2026-07-28.md):
WP1 ships the reviewed OoS parses so production reuses them instead of re-extracting, and the video
half belongs to the [historic archive import and promotion plan](../plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)'s
Stage B. Neither has landed. Until WP1 does, run §5.4 expecting production to spend the model calls
again and to be capable of returning a different plan for an entry.

### Never run the old sermon patch

`storage/app/sermon-patch.sql` is abandoned. Do not apply, regenerate or modernise it. The
2026-05-12 artifact contains 99 updates and 711 inserts, matches only `(date, service)` even though
the application identity is `(date, service, content_type)`, contains 17 duplicate identity
groups, omits processing provenance and related records, and inserts no `preacher_id`. Its media
paths are now known to be real Spaces keys, but that does not make its row matching or foreign-key
handling safe. Applying it would immediately reopen the passed preacher gate.

Delete `GenerateProdSermonPatchCommand` only after the private sermon reconciliation ledger has
no unresolved local-only candidate.

## Evidence at the start

These are aggregate operator counts, not a migration manifest:

| Check | Production | Local |
|---|---:|---:|
| Sermons | 704 | 830 |
| Sermon date range | 2012-06-03–2026-01-25 | 2003-08-30–2026-06-28 |
| Duplicate `(date, service, content_type)` groups | 8 | 17 |
| Sermon source types | 666 manual, 23 livestream, 13 video, 2 audio | 808 audio, 15 livestream, 4 manual; plus 3 manual children's talks |
| OpenLP church services | 2 | 395 |
| Other church services | 1 livestream | 3 livestream, 2 email, 1 manual |
| Bad song canonical keys | 1,207 | 0 (1,151 songs total) |
| Unaccounted `play_date` rows | 6,203 | 0 rows in the source table; no imported legacy items |
| Song-link drift | 3 | 1 |

The local storage audit adds stronger evidence for the legacy MP3s:

- local is configured with both `sermon_disk` and `transcript_disk` set to `do_spaces`;
- all 808 `audio_upload` sermon rows reference a canonical `sermons/audio/...` key;
- all 808 keys are distinct, and the full audit found all 823 referenced sermon audio objects
  present on the configured disk;
- the 808 rows have 819 linked processing logs and 4,092 processing-step rows; 805 sermons have at
  least one completed log and three have only failed logs, so those three require explicit review;
- 669 of the 808 rows have 912 derived scripture-filter rows; these should be regenerated from
  `sermons.reference`, not copied by primary key;
- the rows use 111 canonical preacher records and have no linked speaker samples or published
  service sections;
- one transcript reference elsewhere in the 830-row local database is missing. That does not block
  the MP3 audio transfer, but the affected row must not be promoted with an unverified transcript
  path.

The net difference of 126 sermons is not the promotion count. Overlap, duplicates, children's
talks and production-only records must be resolved from private identity manifests.

## Hard stops

Do not start a real production mutation when any of these is true:

- The current OpenLP SQLite or original `.osz` archive cannot be identified.
- A staged artifact checksum differs from its source checksum.
- The curated `.osz` directory does not hold exactly 428 archives, in either environment (§1.3).
- The `.osz` dry run reports unexpected updates, review items or any failures.
- A song-link dry run proposes unexpected clears.
- A fresh production DB backup cannot be located, decrypted and tied to a tested restore path.
- A media-processing run is active, or the operator cannot monitor Horizon after it resumes.
- The OoS extractor model/key, API quota or network access has not been confirmed for the
  101-entry evaluation.
- The sermon ledger still relies on counts rather than per-identity classification.
- Local and production do not produce the same non-secret Spaces location fingerprint, or a
  selected asset fails existence, size or hash verification.
- The deployed production release does not carry the sermon promotion exporter/importer described in
  Phase 6, or its eligibility guard does not cover the rows being promoted. The commands exist in
  the repository; what has to be confirmed is that production is running a release containing them.
  The old SQL patch is not a fallback.

The default local `storage/mnt/services` directory contained zero `.osz` files on 2026-07-20, but
the original archive was subsequently located on the operator's external drive. Its 536 files
contain a byte-identical 105-file nested duplicate set, leaving 431 unique sources. Operator
curation retains 428 imports after 7 explicit date/service aliases and 3 explicit exclusions.
Use the private curated manifest from the 2026-07-20 evidence directory; the database rows are not
a substitute for those source files. The external source was verified through a read-only Sail
mount because copying the roughly 9 GiB recursive archive would exhaust the local system disk.

**Curation happens on the filesystem, not in the importer.**
`service-tracking:import-openlp-services` takes a directory and imports every `.osz` beneath it. It
has no manifest, deduplication, alias or exclusion option, and it derives each service's
`(date, service)` from the *upload* filename, falling back to the embedded `.osj` name. Pointing it
at the raw 536-file tree would import the duplicate set, the three excluded archives and the seven
misnamed ones under their wrong identities. §1.3 builds the 428-file curated directory that every
later step — local rehearsal, production transfer, production import — must use. Nothing in this
runbook may be run against `$OPENLP_OSZ_SOURCE` directly.

Keep SQL dumps, manifests, import reports and archive contents private. `oos:import-archive`
reports can contain subjects, message IDs and unmatched titles. Store local evidence under
`storage/scratch/`; never commit it.

## Command shells

### Local Mac/Sail shell

Run from the repository root:

```bash
cd /Users/garethclarridge/Projects/crockenhill
set -o pipefail
./vendor/bin/sail up -d
docker compose ps

local_art() {
  ./vendor/bin/sail artisan "$@"
}

local_dbq() {
  docker compose exec -T mysql sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "$1"' \
    sh "$1"
}

R8_RUN_ID="$(date +%Y%m%d-%H%M%S)"
R8_EVIDENCE="storage/scratch/r8/$R8_RUN_ID"
mkdir -p "$R8_EVIDENCE"
```

### Production server/Docker shell

SSH to the production server, change to `/srv/crockenhill`, and define these wrappers. Do not
paste `.env.production` contents into a terminal transcript or report.

```bash
cd /srv/crockenhill
set -o pipefail

dc() {
  docker compose -f docker-compose.prod.yml --env-file .env.production "$@"
}

art() {
  dc exec -T -u www app php artisan "$@"
}

dbq() {
  dc exec -T mysql sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "$1"' \
    sh "$1"
}

# Use the same literal run ID as the local evidence directory.
R8_RUN_ID="<YYYYMMDD-HHMMSS>"
R8_HOST_EVIDENCE="/srv/crockenhill/storage/scratch/r8/$R8_RUN_ID"
R8_STAGE="/var/www/html/storage/app/temp/r8/$R8_RUN_ID"
umask 077
mkdir -p "$R8_HOST_EVIDENCE"
dc exec -T -u www app mkdir -p "$R8_STAGE"
```

## Phase 1 — Preserve local and inventory the sources

### 1.1 Back up local before replacing anything

```bash
docker compose exec -T mysql sh -lc \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --no-tablespaces --single-transaction --quick -u"$MYSQL_USER" "$MYSQL_DATABASE"' \
  | gzip > "$R8_EVIDENCE/local-before-r8.sql.gz"

gzip -t "$R8_EVIDENCE/local-before-r8.sql.gz"
ls -lh "$R8_EVIDENCE/local-before-r8.sql.gz"
```

Preserve this dump until every local-only sermon and OoS source has been classified. It can
contain local credentials or email material; keep it private and on an encrypted disk.

### 1.2 Checksum the known artifacts

The hashes recorded on 2026-07-20 were:

- `crockenhill_orders_of_service_archive.md`:
  `e199ddf1af85d7e247b5b9b7c1456881f3008a66708750f16d65ae8bb7fb748b`
- `songs (1).sqlite`:
  `e8a926f4cd123d8514ec1f9366f40ea39a7c06fa3e4a7322f32789d069e2a9ca`

Treat those only as identities for the current local files. If OpenLP has changed, close OpenLP,
make a fresh consistent SQLite copy, and use its new checksum in both environments.

```bash
shasum -a 256 \
  storage/scratch/crockenhill_orders_of_service_archive.md \
  "storage/scratch/songs (1).sqlite" \
  | tee "$R8_EVIDENCE/source-files.sha256"
```

Locate the original `.osz` directory and make a deterministic private manifest:

```bash
OPENLP_OSZ_SOURCE="<absolute-path-to-original-osz-directory>"

find "$OPENLP_OSZ_SOURCE" -type f -iname '*.osz' | wc -l

(
  cd "$OPENLP_OSZ_SOURCE"
  find . -type f -iname '*.osz' -print | LC_ALL=C sort | while IFS= read -r archive
  do
    shasum -a 256 "$archive"
  done
) > "$R8_EVIDENCE/openlp-osz.sha256"

shasum -a 256 "$R8_EVIDENCE/openlp-osz.sha256"
```

Stop if this source set cannot account for the historic local import. Do not reverse-engineer
`.osz` files from the local database.

### 1.3 Build the curated `.osz` directory

Everything downstream imports this directory. Build it once, gate it on the expected count, and
treat its manifest — not the raw archive's — as the artifact to transfer and re-verify.

```bash
OPENLP_OSZ_CURATED="storage/scratch/r8-input/openlp-osz"
rm -rf "$OPENLP_OSZ_CURATED"
mkdir -p "$OPENLP_OSZ_CURATED"

# One file per distinct SHA-256, first path in sorted order. This collapses the
# byte-identical nested duplicate set to the 431 unique sources.
awk '{ hash = $1; sub(/^[^ ]+  /, ""); if (! seen[hash]++) { print } }' \
  "$R8_EVIDENCE/openlp-osz.sha256" \
  > "$R8_EVIDENCE/openlp-osz-unique.txt"

wc -l < "$R8_EVIDENCE/openlp-osz-unique.txt"   # expect 431

while IFS= read -r archive
do
  install -D -m 600 "$OPENLP_OSZ_SOURCE/$archive" "$OPENLP_OSZ_CURATED/$archive"
done < "$R8_EVIDENCE/openlp-osz-unique.txt"
```

Now apply the curation decisions from the private 2026-07-20 manifest, which is the only record of
which archive each one refers to:

- delete the 3 excluded archives from `$OPENLP_OSZ_CURATED`;
- rename the 7 aliased archives to their corrected `YYYY-MM-DD-<morning|evening>.osz` identity.

Renaming is how an alias is applied: the parser reads the upload filename first. It also compares
that against the embedded `.osj` name, so each of the 7 will import with a
`filename_mismatch` warning and arrive flagged for review. That is the intended outcome — the
operator asserted the identity, and the flag is the record of it. Then gate the result:

```bash
find "$OPENLP_OSZ_CURATED" -type f -iname '*.osz' | wc -l   # must be 428

(
  cd "$OPENLP_OSZ_CURATED"
  find . -type f -iname '*.osz' -print | LC_ALL=C sort | while IFS= read -r archive
  do
    shasum -a 256 "$archive"
  done
) > "$R8_EVIDENCE/openlp-osz-curated.sha256"

shasum -a 256 "$R8_EVIDENCE/openlp-osz-curated.sha256"
```

Stop if the count is not 428. A different number means the source archive, the duplicate set or the
curation decisions have changed since 2026-07-20, and the manifest has to be redone before any
import — local or production — is meaningful.

### 1.4 Capture local sermon identities and duplicates

```bash
local_dbq "
SELECT CONCAT_WS('|', DATE_FORMAT(date, '%Y-%m-%d'), service, content_type)
FROM sermons
GROUP BY date, service, content_type
ORDER BY date, service, content_type;
" | LC_ALL=C sort -u > "$R8_EVIDENCE/local-sermon-keys.txt"

local_dbq "
SELECT
    DATE_FORMAT(date, '%Y-%m-%d'), service, content_type, COUNT(*),
    GROUP_CONCAT(CONCAT(id, ':', COALESCE(title, ''), ':', COALESCE(source_type, 'NULL'))
                 ORDER BY id SEPARATOR ' || ')
FROM sermons
GROUP BY date, service, content_type
HAVING COUNT(*) > 1
ORDER BY date, service, content_type;
" > "$R8_EVIDENCE/local-sermon-duplicates.tsv"

local_dbq "
SELECT
    id,
    CONCAT_WS('|', DATE_FORMAT(date, '%Y-%m-%d'), service, content_type),
    REPLACE(REPLACE(COALESCE(title, ''), CHAR(9), ' '), CHAR(10), ' '),
    COALESCE(source_type, 'NULL'),
    COALESCE(audio_file_path, ''),
    COALESCE(video_file_path, ''),
    COALESCE(livestream_processing_id, '')
FROM sermons
ORDER BY date, service, content_type, id;
" > "$R8_EVIDENCE/local-sermon-rows.tsv"

local_dbq "
SELECT
    s.id,
    s.audio_file_path,
    mpl.processing_id,
    mpl.status,
    COALESCE(mpl.file_hash, ''),
    COALESCE(mpl.file_size, ''),
    REPLACE(REPLACE(COALESCE(mpl.original_filename, ''), CHAR(9), ' '), CHAR(10), ' '),
    COALESCE(mpl.source_file_path, '')
FROM sermons s
INNER JOIN media_processing_logs mpl ON mpl.sermon_id = s.id
WHERE s.source_type = 'audio_upload'
ORDER BY s.id, mpl.completed_at DESC, mpl.id DESC;
" > "$R8_EVIDENCE/local-legacy-sermon-provenance.tsv"
```

Do not include titles, paths or detailed manifests in a public issue or PR.

## Phase 2 — Stage and verify production inputs

From the Mac, transfer the current SQLite, Markdown archive and the **curated** `.osz` directory
built in §1.3 to a private directory on the production host using the configured SSH-key login.
Replace the host placeholder; do not weaken SSH authentication for this transfer. Production must
receive the curated 428, never the raw archive: the importer would take everything it is given.

```bash
ssh <production-user>@<production-host> \
  'umask 077; mkdir -p /srv/crockenhill/storage/scratch/r8-input/openlp-osz'

scp "storage/scratch/songs (1).sqlite" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/openlp-songs.sqlite

scp storage/scratch/crockenhill_orders_of_service_archive.md \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/oos-archive.md

rsync -a --delete "$OPENLP_OSZ_CURATED/" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/openlp-osz/

scp "$R8_EVIDENCE/openlp-osz-curated.sha256" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/openlp-osz.sha256
```

`--delete` matters: a previous attempt's uncurated copy left in that directory would be imported
alongside the curated set.

On production, copy the artifacts into the persisted app temp volume immediately before the run:

```bash
dc cp /srv/crockenhill/storage/scratch/r8-input/openlp-songs.sqlite \
  "app:$R8_STAGE/openlp-songs.sqlite"
dc cp /srv/crockenhill/storage/scratch/r8-input/oos-archive.md \
  "app:$R8_STAGE/oos-archive.md"
dc exec -T -u www app mkdir -p "$R8_STAGE/openlp-osz"
dc cp /srv/crockenhill/storage/scratch/r8-input/openlp-osz/. \
  "app:$R8_STAGE/openlp-osz/"
dc exec -T app chown -R www:www "$R8_STAGE"

dc exec -T -u www app sha256sum \
  "$R8_STAGE/openlp-songs.sqlite" \
  "$R8_STAGE/oos-archive.md" \
  | tee "$R8_HOST_EVIDENCE/staged-source-files.sha256"
```

Regenerate the `.osz` manifest inside the app container:

```bash
dc exec -T -u www app sh -lc '
  cd "$1"
  find . -type f -iname "*.osz" -print | LC_ALL=C sort | while IFS= read -r archive
  do
    sha256sum "$archive"
  done
' sh "$R8_STAGE/openlp-osz" > "$R8_HOST_EVIDENCE/openlp-osz.sha256"

diff -u \
  /srv/crockenhill/storage/scratch/r8-input/openlp-osz.sha256 \
  "$R8_HOST_EVIDENCE/openlp-osz.sha256"

dc exec -T -u www app sh -lc 'find "$1" -type f -iname "*.osz" | wc -l' sh "$R8_STAGE/openlp-osz"
```

`diff` must produce no output. The relative filenames and hashes must match exactly, and the count
must be 428 — the same gate as §1.3, re-asserted on the copy that production will actually import.

### 2.1 Capture production sermon identities

```bash
dbq "
SELECT CONCAT_WS('|', DATE_FORMAT(date, '%Y-%m-%d'), service, content_type)
FROM sermons
GROUP BY date, service, content_type
ORDER BY date, service, content_type;
" | LC_ALL=C sort -u > "$R8_HOST_EVIDENCE/prod-sermon-keys.txt"

dbq "
SELECT
    DATE_FORMAT(date, '%Y-%m-%d'), service, content_type, COUNT(*),
    GROUP_CONCAT(CONCAT(id, ':', COALESCE(title, ''), ':', COALESCE(source_type, 'NULL'))
                 ORDER BY id SEPARATOR ' || ')
FROM sermons
GROUP BY date, service, content_type
HAVING COUNT(*) > 1
ORDER BY date, service, content_type;
" > "$R8_HOST_EVIDENCE/prod-sermon-duplicates.tsv"

dbq "
SELECT
    id,
    CONCAT_WS('|', DATE_FORMAT(date, '%Y-%m-%d'), service, content_type),
    REPLACE(REPLACE(COALESCE(title, ''), CHAR(9), ' '), CHAR(10), ' '),
    COALESCE(source_type, 'NULL'),
    COALESCE(audio_file_path, ''),
    COALESCE(video_file_path, ''),
    COALESCE(livestream_processing_id, '')
FROM sermons
ORDER BY date, service, content_type, id;
" > "$R8_HOST_EVIDENCE/prod-sermon-rows.tsv"

dbq "
SELECT
    s.id,
    s.audio_file_path,
    mpl.processing_id,
    mpl.status,
    COALESCE(mpl.file_hash, ''),
    COALESCE(mpl.file_size, ''),
    REPLACE(REPLACE(COALESCE(mpl.original_filename, ''), CHAR(9), ' '), CHAR(10), ' '),
    COALESCE(mpl.source_file_path, '')
FROM sermons s
INNER JOIN media_processing_logs mpl ON mpl.sermon_id = s.id
WHERE s.source_type = 'audio_upload'
ORDER BY s.id, mpl.completed_at DESC, mpl.id DESC;
" > "$R8_HOST_EVIDENCE/prod-legacy-sermon-provenance.tsv"
```

Copy only the key and duplicate manifests back to the private local evidence directory:

```bash
scp <production-user>@<production-host>:\
/srv/crockenhill/storage/scratch/r8/<run-id>/prod-sermon-keys.txt \
  "$R8_EVIDENCE/prod-sermon-keys.txt"

scp <production-user>@<production-host>:\
/srv/crockenhill/storage/scratch/r8/<run-id>/prod-sermon-duplicates.tsv \
  "$R8_EVIDENCE/prod-sermon-duplicates.tsv"

scp <production-user>@<production-host>:\
/srv/crockenhill/storage/scratch/r8/<run-id>/prod-sermon-rows.tsv \
  "$R8_EVIDENCE/prod-sermon-rows.tsv"

scp <production-user>@<production-host>:\
/srv/crockenhill/storage/scratch/r8/<run-id>/prod-legacy-sermon-provenance.tsv \
  "$R8_EVIDENCE/prod-legacy-sermon-provenance.tsv"
```

Then compare the sorted natural-key sets:

```bash
comm -23 \
  "$R8_EVIDENCE/local-sermon-keys.txt" \
  "$R8_EVIDENCE/prod-sermon-keys.txt" \
  > "$R8_EVIDENCE/local-only-sermon-keys.txt"

comm -13 \
  "$R8_EVIDENCE/local-sermon-keys.txt" \
  "$R8_EVIDENCE/prod-sermon-keys.txt" \
  > "$R8_EVIDENCE/prod-only-sermon-keys.txt"
```

The three-column files are candidate-key comparisons only. They deliberately do not prove that
two rows sharing a key are the same sermon. Compare the row-level manifests and create a private
ledger for every one of the 830 local sermon rows with one of these decisions:

- already represented in production under a corrected identity;
- already represented in production by the same Spaces key or source SHA-256;
- promote the verified Spaces-backed local MP3 record through the portable bundle;
- promote from original video;
- re-import original media only when no verified portable record/object exists;
- manually recreate metadata-only record;
- discard duplicate/test record;
- unresolved.

Add the matching production ID (when any), local sermon ID, Spaces key, processing UUID, source
filename, source-media SHA-256, recorded size, content type and review notes. An exact file hash or
exact canonical Spaces key is stronger evidence than `(date, service, content_type)`; a date key
alone never authorises an update. Different singleton rows sharing a candidate key and unequal
duplicate counts must be resolved explicitly. Do not proceed to sermon promotion or delete either
sermon importer while any entry remains unresolved.

## Phase 3 — Local rehearsal from the authoritative sources

Run this before production mutation. A dry run is not permission to ignore unexpected metrics.

### 3.1 Songs

```bash
local_art service-tracking:sync-songs \
  --path="/var/www/html/storage/scratch/songs (1).sqlite" \
  --dry-run | tee "$R8_EVIDENCE/local-sync-songs-dry-run.txt"

local_art service-tracking:sync-songs \
  --path="/var/www/html/storage/scratch/songs (1).sqlite" \
  | tee "$R8_EVIDENCE/local-sync-songs.txt"

local_dbq "
SELECT COUNT(*)
FROM songs
WHERE canonical_key IS NULL
   OR canonical_key = ''
   OR canonical_key LIKE 'legacy-song-%';
"
```

The final count must remain zero.

### 3.2 Historic OpenLP services

§1.3 already built the curated directory at `storage/scratch/r8-input/openlp-osz`, where Sail can
see it. Confirm it is still the curated 428 rather than a re-copied raw tree, then run:

```bash
find "$OPENLP_OSZ_CURATED" -type f -iname '*.osz' | wc -l   # must be 428

local_art service-tracking:import-openlp-services \
  --path=/var/www/html/storage/scratch/r8-input/openlp-osz \
  --dry-run | tee "$R8_EVIDENCE/local-openlp-services-dry-run.txt"
```

The command can update existing services. Review created/updated/review/failure counts. The
2026-07-20 curated rehearsal processed 428 archives with 29 creates, 399 updates, 21 review
outcomes and zero failures. Eighteen reviews are pre-existing local service flags, one additional
livestream/OpenLP structure merge would be staged, and two email/OpenLP conflicts would
auto-merge. The operator explicitly accepted those two email auto-merges because OpenLP is the
authority over email-derived plans. Inspect or explicitly accept the remaining 19 cases before
continuing. Run the real command locally only when this is the complete intended archive and the
local DB backup has been verified:

```bash
local_art service-tracking:import-openlp-services \
  --path=/var/www/html/storage/scratch/r8-input/openlp-osz \
  | tee "$R8_EVIDENCE/local-openlp-services.txt"
```

### 3.3 Markdown OoS archive

The current artifact has already produced 101 synthetic emails and two local email-sourced
services. Structural validation is safe to repeat:

```bash
local_art oos:import-archive \
  /var/www/html/storage/scratch/crockenhill_orders_of_service_archive.md \
  --dry-run \
  --report="$R8_EVIDENCE/oos-structural.json"
```

`--dry-run` only splits and validates; it does not call the extractor and is not an import
preview. A run without `--dry-run` or `--import` invokes the extractor and writes/synchronises
synthetic `InboundEmail` rows. Do not rerun evaluation/import locally unless the source hash or
parser has changed. If it has, back up first, evaluate, privately review the JSON report, then run
`--import`; the import merges into whatever service already occupies each date+service slot (see
§5.4).

### 3.4 Local linking and other local drift

```bash
local_art service-tracking:link-songs --dry-run \
  | tee "$R8_EVIDENCE/local-link-songs-dry-run.txt"
```

The known local result was one update and no clears. Stop for an unexpected clear. Otherwise:

```bash
local_art service-tracking:link-songs \
  | tee "$R8_EVIDENCE/local-link-songs.txt"
local_art service-tracking:link-songs --dry-run
local_art backfill:audit --json | tee "$R8_EVIDENCE/local-backfill-audit.json"
```

The starting local audit also reported 5 missing scripture-filter rows, missing speaker profiles,
and 693 sermons with a reference but no cached passage. Only the filter rows are a failing derived
data gate:

```bash
local_art sermons:sync-scripture-filters --only-missing --dry-run
local_art sermons:sync-scripture-filters --only-missing
local_art backfill:audit --json
```

Speaker profiles are environment-specific model artifacts; they do not need to match production
unless local speaker-identification testing is required. In that case, first require a configured
provider and accessible source audio, then inspect `speaker-profiles:bootstrap --dry-run` before a
real run. Cached scripture passages are advisory and API-backed; inspect
`sermons:enrich-scripture --dry-run --limit=100` and only run it locally when api.bible is enabled
and its quota/cost is accepted.

Run the local media-identity backfill only if its own dry run finds work. Equality of processing
logs is not a convergence target:

```bash
local_art media-processing:backfill-extracted-identity --dry-run
# If Would update is greater than zero:
local_art media-processing:backfill-extracted-identity
local_art media-processing:backfill-extracted-identity --dry-run
```

Do not run the production `play_date` dump locally. Its song IDs belong to production; existence
of the same numeric ID locally would not prove it represents the same song.

## Phase 4 — Production dry runs

Confirm production is on the intended application revision and healthy:

```bash
dc ps
art horizon:status
art about --only=environment
art config:show service-tracking \
  | tee "$R8_HOST_EVIDENCE/prod-service-tracking-config.txt"
dc exec -T app df -h /var/www/html/storage/app/temp
dc exec -T app sh -lc 'test -n "$OPENAI_API_KEY"'

dbq "
SELECT status, COUNT(*)
FROM media_processing_logs
WHERE status IN ('pending', 'started', 'processing')
GROUP BY status;
"
```

Wait for every active media run to become terminal.

Confirm that `service-tracking.email_parsing.model` is the intended production model. The key check
must exit zero without printing the key. Confirm API quota/network availability and budget enough
time for 101 extractor calls; `--dry-run` does not exercise this dependency.

Capture the failed-job baseline for the post-resume check:

```bash
R8_FAILED_JOB_BASELINE="$(dbq "SELECT COALESCE(MAX(id), 0) FROM failed_jobs;")"
printf '%s\n' "$R8_FAILED_JOB_BASELINE" \
  > "$R8_HOST_EVIDENCE/failed-job-baseline.txt"
```

Run and save the dry runs in dependency order:

```bash
art service-tracking:sync-songs \
  --path="$R8_STAGE/openlp-songs.sqlite" \
  --dry-run | tee "$R8_HOST_EVIDENCE/prod-sync-songs-dry-run.txt"

art service-tracking:import-openlp-services \
  --path="$R8_STAGE/openlp-osz" \
  --dry-run | tee "$R8_HOST_EVIDENCE/prod-openlp-services-dry-run.txt"

art oos:import-archive "$R8_STAGE/oos-archive.md" \
  --dry-run \
  --report="$R8_STAGE/oos-structural.json" \
  | tee "$R8_HOST_EVIDENCE/prod-oos-structural.txt"

art service-tracking:link-songs --dry-run \
  | tee "$R8_HOST_EVIDENCE/prod-link-songs-before.txt"

art media-processing:backfill-extracted-identity --dry-run \
  | tee "$R8_HOST_EVIDENCE/prod-media-identity-dry-run.txt"
```

### 4.1 Why this order, and what it does and does not decide

The aim is the state week-by-week operation would have reached, where the email plan arrives first,
the OpenLP export is made for the service, and the recording is processed afterwards. This runbook
imports OpenLP before the Markdown archive and the historic video last, which is not that
chronology, so the difference has to be accounted for rather than assumed away.

**What the arrival order no longer decides.** `ChurchServiceItemSyncService` resolves item identity
from the accumulated `metadata.source_evidence` rather than from the row's `source` column, so:

- a song or reading that OpenLP has identified keeps that identification against any email merge,
  whichever import ran first, and against a re-import of the same email afterwards;
- no machine source deletes another's items — only a manual save, a source restating its own
  earlier import, or an explicit replace states a complete list;
- a row a person has reviewed is not rewritten or removed by any later machine import.

`ChurchServiceItemSyncServiceTest::test_the_reading_is_the_same_whichever_of_the_two_plans_imported_first`
is the executable form of that claim. If it is ever deleted or weakened, this ordering argument
lapses with it.

**What the arrival order still decides.** Three things, all of them visible rather than silent:

1. `church_services.source` records the last source to apply items, so the same service ends up
   labelled `openlp` in one order and `email` in the other. It is provenance for a human reader; no
   merge rule consults it.
2. Which source *creates* a service row. Production has 2 OpenLP services against 428 incoming, so
   importing `.osz` first means almost every slot is created from a filename-derived
   `(date, service)` rather than from an AI-parsed date.
3. Which conflicts get recorded. An email merging into an OpenLP service flags every unmatched
   OpenLP non-song item as `preserved_existing_item`; an OpenLP merge into an email service raises
   no equivalent flag. So this order produces more review signal, not less — count it after the
   import (§5.4) rather than being surprised by it.

None of those changes what survives in the item list, which is why the order below is kept.

### 4.1 Make the production `play_date` source dump

Use ordinary `mysqldump` output. Do not use `--complete-insert`; the importer expects
`INSERT INTO \`play_date\` VALUES ...`.

```bash
set -a
. ./.env.production
set +a

dc exec -T -e MYSQL_PWD="$DB_PASSWORD" mysql \
  mysqldump --no-tablespaces --single-transaction --quick --no-create-info --skip-triggers \
  -u"$DB_USERNAME" "$DB_DATABASE" play_date \
  > "$R8_HOST_EVIDENCE/play_date.sql"

chmod 600 "$R8_HOST_EVIDENCE/play_date.sql"
grep -m 1 '^INSERT INTO `play_date` VALUES ' "$R8_HOST_EVIDENCE/play_date.sql"

dc cp "$R8_HOST_EVIDENCE/play_date.sql" "app:$R8_STAGE/play_date.sql"
dc exec -T app chown www:www "$R8_STAGE/play_date.sql"

art service-tracking:import-legacy-song-usage \
  --path="$R8_STAGE/play_date.sql" \
  --dry-run | tee "$R8_HOST_EVIDENCE/prod-play-date-dry-run.txt"
```

Stop if referenced song IDs are missing. Catalogue reconciliation must run before the real usage
import so the reconciler can preserve the production legacy song IDs.

## Phase 5 — Production maintenance window and mutation

Song sync and `play_date` import are single transactions. The `.osz` directory import is
transactional per archive, the Markdown import per entry, and song linking/media identity are
chunked. A later failure can therefore leave completed earlier units. Save every output and rely
on idempotent reruns/audits; do not issue compensating deletes.

Plan enough time for the `.osz` directory and 101-entry archive evaluation. If the maintenance
window would be unacceptably long, split this into separately backed-up windows at the phase
boundaries; never let the desire for a shorter outage remove the backup/Horizon gates.

### 5.1 Pause work and take two verifiable backups

Enter maintenance mode first so no new HTTP upload can be accepted. Leave Horizon running until
every already-accepted media run is terminal; otherwise pausing it can strand a `pending` or
`started` run in the backup.

```bash
art down --retry=60

dbq "
SELECT status, COUNT(*)
FROM media_processing_logs
WHERE status IN ('pending', 'started', 'processing')
GROUP BY status;
"
```

Repeat that query until it returns no rows, while monitoring Horizon. Account separately for any
scheduled or non-HTTP ingress that can create a run. Then pause Horizon and prove no run appeared
in the hand-off window:

```bash
art horizon:pause
art horizon:status
art horizon:supervisors

dbq "
SELECT status, COUNT(*)
FROM media_processing_logs
WHERE status IN ('pending', 'started', 'processing')
GROUP BY status;
"
```

The final query must return no rows and the Horizon dashboard must show the supervisor paused.
Now take the backups:

```bash
art backup:run --only-db
art backup:list | tee "$R8_HOST_EVIDENCE/backup-list.txt"

dc exec -T -e MYSQL_PWD="$DB_PASSWORD" mysql \
  mysqldump --no-tablespaces --single-transaction --quick -u"$DB_USERNAME" "$DB_DATABASE" \
  | gzip > "$R8_HOST_EVIDENCE/prod-before-r8.sql.gz"

chmod 600 "$R8_HOST_EVIDENCE/prod-before-r8.sql.gz"
gzip -t "$R8_HOST_EVIDENCE/prod-before-r8.sql.gz"
ls -lh "$R8_HOST_EVIDENCE/prod-before-r8.sql.gz"
```

Do not continue until the encrypted application backup is visible and the private host dump
passes `gzip -t`. The host dump must remain on the protected production host; do not copy it to a
laptop or public file service.

### 5.2 Sync songs

```bash
art service-tracking:sync-songs \
  --path="$R8_STAGE/openlp-songs.sqlite" \
  | tee "$R8_HOST_EVIDENCE/prod-sync-songs.txt"

dbq "
SELECT COUNT(*)
FROM songs
WHERE canonical_key IS NULL
   OR canonical_key = ''
   OR canonical_key LIKE 'legacy-song-%';
" | tee "$R8_HOST_EVIDENCE/prod-song-canonical-gate.txt"
```

The count must be zero. Stop with the site in maintenance and Horizon paused if it is not.

### 5.3 Import the curated historic `.osz` archive

This imports the 428-file curated directory verified in Phase 2, not the raw archive.

```bash
art service-tracking:import-openlp-services \
  --path="$R8_STAGE/openlp-osz" \
  | tee "$R8_HOST_EVIDENCE/prod-openlp-services.txt"

dbq "
SELECT source, COUNT(*)
FROM church_services
GROUP BY source
ORDER BY source;
" | tee "$R8_HOST_EVIDENCE/prod-service-sources-after-openlp.txt"
```

Any failure makes the command fail but earlier archives may have committed. Retain the output,
fix the cause, and rerun; do not delete the successful services. Review every service flagged for
manual review before declaring the archive final. Compare the actual created/updated/review/failure
metrics with the dry run and explain any difference caused by the now-reconciled song catalogue;
the earlier dry run against the legacy catalogue is not by itself acceptance evidence.

The import count is its own gate. 428 archives must be accounted for as created plus updated plus
failed; a total of 431 or 536 means an uncurated directory was staged and the run has to be
assessed against the pre-import backup rather than continued.

#### 5.3.1 Clear staged structure merges before the next source

Run this after §5.3, after §5.4 and after §6.5. A service can hold only one pending proposal, so a
second source staging against the same recording pushes the first into
`superseded_proposals` — kept for audit, but no longer what the review screen offers:

```bash
dbq "
SELECT id, date, service, pending_structure_merge_source,
       JSON_LENGTH(JSON_EXTRACT(import_metadata, '\$.pending_structure_merge.superseded_proposals'))
FROM church_services
WHERE pending_structure_merge_source IS NOT NULL
ORDER BY date;
" | tee "$R8_HOST_EVIDENCE/prod-pending-structure-merges-after-<stage>.txt"
```

Resolve each row from the service review screen before starting the next import. A non-zero
superseded count means an earlier proposal was already displaced and the reviewer has to read it
out of `import_metadata` before accepting or rejecting the current one. Zero rows is the intended
state at every stage boundary; carrying rows forward is a deliberate, recorded decision.

### 5.4 Evaluate and import the Markdown OoS archive

The archive import processes historic entries as if the current email pipeline had handled them at
the time. Each entry becomes a synthetic inbound email and takes one of three routes:

| Route | Report disposition | Email status | Meaning |
| --- | --- | --- | --- |
| Blocked | `blocked` | `archive_eval` | The markdown contradicts itself about the date (`weekday_mismatch`, `date_discrepancy`, `source_date_discrepancy`, `multi_date`). Correct the archive text before anything else can be done — deliberately kept out of the inbox. |
| Held for review | `held_for_review` | `pending` | The ground truth does not corroborate the parse, or the parse did not clear the live auto-import bar. The entry sits in the review inbox and is finished in the normal edit-and-approve workbench. |
| Imported | `created` / `merged` | `processed` | The plan cleared the auto-import bar and was merged into the existing service for its date+service slot, or created one. |

`unresolved` (no date at all) and `import_failed` are the two remaining dispositions; an evaluation
run reports `eligible` where an import run would have attempted the import.

Three consequences of the merge model to hold onto:

- An archive email **merges into an existing OpenLP or livestream service** rather than skipping
  it, adding the prayers, notices and sermon an OpenLP export structurally cannot carry. Existing
  song identity is preserved by the merge policy; a conflict is staged, not applied.
- A re-run **repairs automatically**. There is no `--repair-existing` flag: re-running the same
  entry re-merges, and a re-parse that no longer clears the bar returns a previously processed
  email to the inbox instead of leaving it silently `processed`.
- **A livestream import arriving last applies rather than stages.**
  `StructureMergePolicy::requiresMergePlanning()` only stages a merge when the *existing* items are
  high-confidence livestream detections and the incoming source is something else. A livestream
  import arriving last therefore merges directly: it still cannot delete email or OpenLP items and
  cannot rewrite their identification, but nothing pauses for human adjudication of a disagreement.
  §6.5 imports the historic video after both plans, so those services reach the reviewer as a
  merged list plus whatever conflicts the sync recorded — not as a staged proposal. Import a video
  batch before the email pass for any date where you want the disagreement staged instead.
- **A second staged proposal no longer discards the first.** There is one
  `pending_structure_merge` slot per service, so where a recording already exists and both plans
  conflict with it, the email proposal supersedes the OpenLP one. The superseded proposal is kept
  in `pending_structure_merge.superseded_proposals` with its own source and timestamp, but only the
  newest is what the review screen offers to accept. Resolve staged merges between import stages
  (§5.3.1) rather than letting them queue.

Evaluation is mutating: it writes/synchronises synthetic inbound emails and invokes the extractor.
It does *not* change email status — only an `--import` run releases entries into the operator's
inbox — so it is run after the backup and before `--import` and its private report reviewed first.
For the one-entry API preflight, replace `YYYY-MM-DD` with a resolved date selected from the
structural report.

```bash
art oos:import-archive "$R8_STAGE/oos-archive.md" \
  --date=YYYY-MM-DD \
  --report="$R8_STAGE/oos-extractor-preflight.json" \
  | tee "$R8_HOST_EVIDENCE/prod-oos-extractor-preflight.txt"

dc cp "app:$R8_STAGE/oos-extractor-preflight.json" \
  "$R8_HOST_EVIDENCE/oos-extractor-preflight.json"

dc exec -T -u www app php -r '
  $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  echo json_encode($report["aggregate"]["dispositions"], JSON_PRETTY_PRINT), PHP_EOL;
' "$R8_STAGE/oos-extractor-preflight.json"

art oos:import-archive "$R8_STAGE/oos-archive.md" \
  --report="$R8_STAGE/oos-evaluate.json" \
  | tee "$R8_HOST_EVIDENCE/prod-oos-evaluate.txt"

dc cp "app:$R8_STAGE/oos-evaluate.json" \
  "$R8_HOST_EVIDENCE/oos-evaluate.json"
```

The preflight dispositions must not contain `failed`; otherwise stop before the full evaluation.
Privately inspect the full aggregate, blocked entries, failures and eligible plans. Then import.

**Do not review the inbox yet.** Import every source first — OpenLP (§5.3), this archive, song
usage (§5.5), links (§5.6) and the historic video batch (§6.5) — and review once, at the end.

The reason is no longer that an early review would be *destroyed*: a reviewed row is now protected.
A save from the workbench carries manual authority even when it is finishing an inbound email
(`SaveChurchServiceFromAdmin`), so it states the whole list — it deletes and reorders — and
`ChurchServiceItemSyncService::shouldPreserveExistingIdentity()` then stops every later machine
import rewriting or removing what the reviewer settled.

That protection is exactly why the review still goes last. A row reviewed before the OpenLP export
lands is frozen against OpenLP's identification of it: the export can still add items the reviewer
never saw, but it will not correct the song or reading on a row a person has already signed off. So
an early review does not lose work — it loses the corroboration the later source was going to
supply, and the reviewer adjudicates two sources where they could have adjudicated three. A pending
email has created nothing and costs a table row; a service reviewed twice costs a person an
evening.

Batching the *import* is still useful — a per-batch report is easier to inspect and a failure is
easier to attribute — and `--date`, `--from`, `--to` and `--limit` all select entries, so no extra
flag is needed:

```bash
art oos:import-archive "$R8_STAGE/oos-archive.md" \
  --import \
  --from=2022-01-01 --to=2022-12-31 \
  --report="$R8_STAGE/oos-import-2022.json" \
  | tee "$R8_HOST_EVIDENCE/prod-oos-import-2022.txt"
```

The commands below take the whole corpus in one pass and are the shape of each batch; substitute
the date window when running for real.

```bash
art oos:import-archive "$R8_STAGE/oos-archive.md" \
  --import \
  --report="$R8_STAGE/oos-import.json" \
  | tee "$R8_HOST_EVIDENCE/prod-oos-import.txt"

art oos:import-archive "$R8_STAGE/oos-archive.md" \
  --import \
  --report="$R8_STAGE/oos-idempotency.json" \
  | tee "$R8_HOST_EVIDENCE/prod-oos-idempotency.txt"

dc cp "app:$R8_STAGE/oos-import.json" \
  "$R8_HOST_EVIDENCE/oos-import.json"
dc cp "app:$R8_STAGE/oos-idempotency.json" \
  "$R8_HOST_EVIDENCE/oos-idempotency.json"

dc exec -T -u www app php -r '
  $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  echo json_encode($report["aggregate"]["dispositions"], JSON_PRETTY_PRINT), PHP_EOL;
' "$R8_STAGE/oos-import.json"

dc exec -T -u www app php -r '
  $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  echo json_encode($report["aggregate"]["dispositions"], JSON_PRETTY_PRINT), PHP_EOL;
' "$R8_STAGE/oos-idempotency.json"

dbq "
SELECT status, COUNT(*)
FROM inbound_emails
WHERE message_id LIKE '%oos-archive-%'
GROUP BY status
ORDER BY status;
"

dbq "
SELECT source, COUNT(*)
FROM church_services
GROUP BY source
ORDER BY source;
"
```

The first report must contain no `failed` or `import_failed` disposition. The second must contain
no `created`, `failed` or `import_failed`: a re-run re-merges, so every entry that imported the
first time must report `merged` the second. The console summary alone is not sufficient evidence.

The email status counts are the check that the three-way split behaved. `archive_eval` must equal
the number of `blocked` entries and no more — anything else means an entry that a human could act
on is being withheld from the inbox. Then confirm the merges were safe:

```bash
dbq "
SELECT cs.id, cs.date, cs.service, cs.source, cs.needs_review, COUNT(csi.id) AS items,
       SUM(csi.song_id IS NOT NULL) AS linked_songs
FROM church_services cs
LEFT JOIN church_service_items csi
    ON csi.church_service_id = cs.id AND csi.deleted_at IS NULL
GROUP BY cs.id
ORDER BY cs.date;
"
```

Run it before and after each batch. Item counts must not grow on the second (idempotency) pass and
no service may lose a `song_id` — the email is the completeness authority, OpenLP the identification
authority, so a merge adds items and never drops song identity. A service left with
`needs_review = 1` and a `pending_structure_merge` key in `import_metadata` has had its merge
*staged* rather than applied: that is the correct outcome for a conflict against high-confidence
livestream items, not a failure, and it is resolved from the service review screen.

Also count the review signal this pass produced, because importing OpenLP before email is what
generates it (§4.1) and a batch that flags nearly everything is worth understanding before the next
one runs:

```bash
dbq "
SELECT COUNT(*)
FROM church_services
WHERE JSON_SEARCH(import_metadata, 'one', 'preserved_existing_item') IS NOT NULL;
" | tee "$R8_HOST_EVIDENCE/prod-preserved-existing-item-count.txt"
```

Each of those is an OpenLP item the email plan did not mention. A handful is the expected shape —
the two lists genuinely differ. A count approaching the number of imported services means the two
sides are failing to match rather than genuinely disagreeing, and the reading and song resolvers
should be checked against a sample before the operator spends an evening confirming false
disagreements.

If a later parser change invalidates cached parses (`PARSER_VERSION` in `ImportOosArchiveCommand`),
re-running the import re-parses and re-merges: no separate repair mode exists. An entry whose new
parse the structural validator rejects is held for review instead, and its email returns to the
inbox — the service built from the old parse is left exactly as it is, pending a manual comparison
against the archive.

### 5.5 Import production legacy song usage

Repeat the dry run now that catalogue/service reconciliation has committed. This is the dry run
that authorises the real usage import:

```bash
art service-tracking:import-legacy-song-usage \
  --path="$R8_STAGE/play_date.sql" \
  --dry-run | tee "$R8_HOST_EVIDENCE/prod-play-date-after-songs-dry-run.txt"

art service-tracking:import-legacy-song-usage \
  --path="$R8_STAGE/play_date.sql" \
  | tee "$R8_HOST_EVIDENCE/prod-play-date.txt"

dbq "
SELECT COUNT(*)
FROM play_date pd
WHERE NOT EXISTS (
    SELECT 1
    FROM church_service_items csi
    WHERE csi.deleted_at IS NULL
      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(csi.metadata, '$.legacy_play_date_id')) AS UNSIGNED) = pd.id
)
AND NOT EXISTS (
    SELECT 1
    FROM church_services cs
    INNER JOIN church_service_items csi
        ON csi.church_service_id = cs.id
       AND csi.deleted_at IS NULL
    WHERE cs.date = pd.date
      AND cs.service = CASE pd.time
          WHEN 'a' THEN 'morning'
          WHEN 'p' THEN 'evening'
      END
      AND csi.song_id = CAST(pd.song_id AS UNSIGNED)
);
" | tee "$R8_HOST_EVIDENCE/prod-play-date-gate.txt"
```

The count must be zero. Rerunning the importer should report only existing-row/existing-song skips.

### 5.6 Converge song links and praise numbers

```bash
art songs:backfill-praise-numbers --dry-run \
  | tee "$R8_HOST_EVIDENCE/prod-praise-dry-run.txt"

art service-tracking:link-songs --dry-run \
  | tee "$R8_HOST_EVIDENCE/prod-link-songs-after-imports-dry-run.txt"
```

Praise-number updates must remain zero. Stop for unexpected link clears; otherwise run and prove
the linker is then a no-op:

```bash
art service-tracking:link-songs \
  | tee "$R8_HOST_EVIDENCE/prod-link-songs.txt"
art service-tracking:link-songs --dry-run \
  | tee "$R8_HOST_EVIDENCE/prod-link-songs-idempotency.txt"
```

The final dry run must report `Links updated = 0` and `Links cleared = 0`.

### 5.7 Backfill media-processing identity

```bash
art media-processing:backfill-extracted-identity \
  | tee "$R8_HOST_EVIDENCE/prod-media-identity.txt"
art media-processing:backfill-extracted-identity --dry-run \
  | tee "$R8_HOST_EVIDENCE/prod-media-identity-idempotency.txt"
```

`Would update` must be zero. The five rows with no usable metadata are accepted residue.

### 5.8 Resume and verify

On a successful run, leave maintenance mode and resume Horizon:

```bash
art up
art horizon:continue
art horizon:status
art horizon:supervisors
dc ps

R8_FAILED_JOB_BASELINE="$(cat "$R8_HOST_EVIDENCE/failed-job-baseline.txt")"
dbq "
SELECT COUNT(*)
FROM failed_jobs
WHERE id > $R8_FAILED_JOB_BASELINE;
" | tee "$R8_HOST_EVIDENCE/new-failed-job-count.txt"

dbq "
SELECT status, COUNT(*)
FROM media_processing_logs
WHERE status IN ('pending', 'started', 'processing')
GROUP BY status;
"

art backfill:audit --json \
  | tee "$R8_HOST_EVIDENCE/prod-backfill-audit.json"

curl --fail --silent --show-error https://crockenhill.org/ > /dev/null
```

Watch the Horizon dashboard and repeat the non-terminal media query until the reconciliation and
processing queues have drained. Stop and investigate if the failed-job count is non-zero.

Require:

- `songs_missing_praise_numbers = 0`;
- `song_link_drift = 0`;
- `songs_catalogue_missing = false`;
- the canonical-key and `play_date` gates above both equal zero;
- no failed import or unexpected review outcome;
- the Horizon dashboard queues have drained, the new failed-job count is zero, and any queued
  `.osz` reconciliation work completed successfully;
- Horizon and the Compose services are healthy.

## Phase 6 — Promote local-only sermons separately

Do this after the synchronous data maintenance window. Never infer a promotion set from the net
count difference. Promote only rows whose private ledger decision is `promote`.

The 808 local legacy MP3 rows already point at verified objects on `do_spaces`. The preferred path
is therefore a create-only database promotion with asset verification. It must not upload the MP3,
transcribe it, run analysis again or overwrite a production sermon. Re-ingestion is the fallback
only for a row whose object/provenance cannot be verified.

### 6.0 Prove that local and production address the same Spaces location

Create a non-secret location fingerprint locally. This hashes the bucket, endpoint and effective
region; it deliberately excludes access keys and secrets:

```bash
docker compose exec -T laravel.test sh -lc '
  printf "%s\n%s\n%s\n" \
    "$DO_SPACES_BUCKET" \
    "$DO_SPACES_ENDPOINT" \
    "${DO_SPACES_REGION:-$DO_SPACES_DEFAULT_REGION}" \
    | sha256sum
' | awk '{print $1}' > "$R8_EVIDENCE/local-spaces-location.sha256"

local_art config:show media-processing.storage \
  | tee "$R8_EVIDENCE/local-sermon-storage-config.txt"
local_art audit:sermon-assets --json \
  | tee "$R8_EVIDENCE/local-sermon-assets.json"
```

The current local whole-database audit exits non-zero because one transcript is missing, but its
audio section must still say `823 referenced`, `823 present`, `0 missing`, `0 check_errors`.

Create the corresponding production evidence:

```bash
dc exec -T app sh -lc '
  printf "%s\n%s\n%s\n" \
    "$DO_SPACES_BUCKET" \
    "$DO_SPACES_ENDPOINT" \
    "${DO_SPACES_REGION:-$DO_SPACES_DEFAULT_REGION}" \
    | sha256sum
' | awk '{print $1}' > "$R8_HOST_EVIDENCE/prod-spaces-location.sha256"

art config:show media-processing.storage \
  | tee "$R8_HOST_EVIDENCE/prod-sermon-storage-config.txt"
art audit:sermon-assets --json \
  | tee "$R8_HOST_EVIDENCE/prod-sermon-assets.json"
```

Copy the production fingerprint back and compare it:

```bash
scp <production-user>@<production-host>:\
/srv/crockenhill/storage/scratch/r8/<run-id>/prod-spaces-location.sha256 \
  "$R8_EVIDENCE/prod-spaces-location.sha256"

diff -u \
  "$R8_EVIDENCE/local-spaces-location.sha256" \
  "$R8_EVIDENCE/prod-spaces-location.sha256"
```

`diff` must produce no output. If it differs, stop: the objects need an explicit server-side
bucket copy plus verification before their rows can be promoted.

### 6.1 The portable sermon promotion commands

**These now exist** — this section was a build specification when the runbook was written and is
now an acceptance checklist. `sermons:export-promotion-bundle` and
`sermons:import-promotion-bundle` are in the repository, backed by
`app/Services/Sermon/SermonPromotionBundle{Exporter,Importer,Validator,Files}.php` and
`SermonPromotionAssets.php`, with `tests/Feature/Console/SermonPromotionBundleCommandTest.php`
covering create-only import, preacher alias resolution, slug/asset-hash/processing-UUID collisions,
missing assets, streamed hash mismatch, transaction rollback and the second-run idempotency result.

```text
sermons:export-promotion-bundle --ids=<comma-separated-local-ids> --output=<private-json-path>
sermons:import-promotion-bundle --path=<private-json-path> [--verify-hashes] [--apply]
```

Confirm before use, rather than assuming, that the deployed release is the one carrying them
(`art list sermons` on the production host) and that the exporter's eligibility guard still matches
the rows being promoted — it accepts `audio_upload` sermons with an audio path only, and rejects
livestream-sourced rows by design. Historic *video* promotion is a different bundle and belongs to
the [historic archive import and promotion plan](../plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md),
whose Stage B copies this pair as a template rather than widening it in place.

Both command docblocks state their deletion trigger: delete them with their tests after the
R8 ledger has no unresolved/promote entries and the production idempotency run passes.

The requirements below are what those classes were built to, and remain the acceptance criteria for
any change to them.

The versioned JSON bundle must be portable domain data, not SQL. For each selected sermon it must
contain:

- the local ID only as audit evidence, never as the production primary key;
- every portable sermon field, including canonical Spaces asset paths and original timestamps,
  while omitting the local download counter and environment-specific relationships;
- preacher name/slug and aliases needed to resolve or create the canonical production preacher;
- the source filename, SHA-256, recorded size and globally unique processing UUID;
- the selected processing provenance and its step history, while omitting local auto-increment IDs,
  `owner_user_id`, `church_service_id`, `job_id`, queue state and other machine-local correlation;
- scripture-filter book/chapter entries as derived natural values, never their IDs;
- all thumbnail paths embedded in `thumbnail_metadata`, so they are verified as assets too.

Do not copy `scripture_passage_id`, scripture-passage API payloads, scripture-filter primary keys,
speaker profiles/samples or published service-section links. The importer must resolve an existing
production scripture passage by the reference where possible, verify the bundled book/chapter
entries against the current parser, and rebuild those derived filters against the new sermon ID.

The importer must be dry-run by default. Its preflight must classify every bundle entry as exactly
one of `already_present`, `create` or `conflict`, using exact source SHA-256 and canonical asset key
before considering the non-unique date/service/content-type candidate identity. It must:

- reject a slug, processing UUID, asset-key or hash collision that points at a different sermon;
- resolve preacher IDs by stable slug/name/alias, creating a minimal canonical preacher only when
  neither slug nor name conflicts;
- verify every referenced Spaces object before writing; `--verify-hashes` must stream and compare
  SHA-256 rather than merely accepting an object-exists response;
- treat local sermons 35, 36 and 37 as manual-review conflicts because they only have failed
  provenance logs despite valid audio, and treat sermon 39's missing transcript as a conflict until
  the object is restored or the stale local reference is deliberately cleared before export;
- insert only `create` entries in one database transaction, preserve provenance with remapped
  sermon IDs, and leave every existing production row untouched;
- fail the whole batch before writes on any conflict or failed asset/FK check;
- be idempotent: a second dry run after apply must report only `already_present` and zero changes.

Write focused tests for create-only import, preacher remapping/creation, duplicate date identities,
slug/path/hash/processing-UUID conflicts, missing assets, hash mismatch, transaction rollback,
provenance remapping and the second-run idempotency result. Do not build this by repairing the SQL
dump parser.

### 6.2 Export, transfer and dry-run a small approved MP3 bundle

After that release is deployed, start with a small set of approved local IDs:

```bash
PROMOTE_SERMON_IDS="<id,id,id>"
LOCAL_SERMON_BUNDLE="$R8_EVIDENCE/legacy-sermons-batch-01.json"

local_art sermons:export-promotion-bundle \
  --ids="$PROMOTE_SERMON_IDS" \
  --output="/var/www/html/$LOCAL_SERMON_BUNDLE"

shasum -a 256 "$LOCAL_SERMON_BUNDLE" \
  | awk '{print $1}' > "$R8_EVIDENCE/legacy-sermons-batch-01.sha256"

scp "$LOCAL_SERMON_BUNDLE" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/
scp "$R8_EVIDENCE/legacy-sermons-batch-01.sha256" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/
```

On production, stage the private bundle in the persisted app temp volume and verify its checksum:

```bash
dc cp /srv/crockenhill/storage/scratch/r8-input/legacy-sermons-batch-01.json \
  "app:$R8_STAGE/legacy-sermons-batch-01.json"
dc exec -T app chown www:www "$R8_STAGE/legacy-sermons-batch-01.json"

dc exec -T -u www app sha256sum "$R8_STAGE/legacy-sermons-batch-01.json" \
  | awk '{print $1}' > "$R8_HOST_EVIDENCE/legacy-sermons-batch-01.sha256"

diff -u \
  /srv/crockenhill/storage/scratch/r8-input/legacy-sermons-batch-01.sha256 \
  "$R8_HOST_EVIDENCE/legacy-sermons-batch-01.sha256"

art sermons:import-promotion-bundle \
  --path="$R8_STAGE/legacy-sermons-batch-01.json" \
  --verify-hashes \
  | tee "$R8_HOST_EVIDENCE/legacy-sermons-batch-01-dry-run.txt"
```

`diff` must be empty. The dry run must contain only the ledger-approved `create` entries (or an
explained `already_present`) and zero conflicts.

### 6.3 Apply the MP3 bundle and prove idempotency

Take/record a fresh production backup, enter maintenance mode and pause Horizon using the Phase 4
procedure. Re-run the dry run immediately before apply, then:

```bash
art sermons:import-promotion-bundle \
  --path="$R8_STAGE/legacy-sermons-batch-01.json" \
  --verify-hashes \
  --apply \
  | tee "$R8_HOST_EVIDENCE/legacy-sermons-batch-01-apply.txt"

art sermons:import-promotion-bundle \
  --path="$R8_STAGE/legacy-sermons-batch-01.json" \
  --verify-hashes \
  | tee "$R8_HOST_EVIDENCE/legacy-sermons-batch-01-idempotency.txt"

art audit:sermon-assets --json \
  | tee "$R8_HOST_EVIDENCE/prod-sermon-assets-after-batch.json"

dbq "SELECT COUNT(*) FROM sermons WHERE preacher_id IS NULL;"
```

The second importer run must report zero creates/updates/conflicts, and the importer must report
that every expected scripture-filter entry is present. Review every new public page, audio URL,
preacher and scripture reference, then resume the site/Horizon and verify health as in Phase 5.
Promote further small batches only after this one passes. The preacher query must remain zero; the
audio audit must have zero missing/check errors.

### 6.4 Stage an approved historic-video batch

Video records that do not have a verified portable Spaces-backed record still use their original
media and the current pipeline. Stage only selected source files:

```bash
LOCAL_VIDEO_BATCH="storage/scratch/r8-input/historic-videos/batch-01"
mkdir -p "$LOCAL_VIDEO_BATCH"

# Place only the selected video source files in this directory.
(
  cd "$LOCAL_VIDEO_BATCH"
  find . -type f -print | LC_ALL=C sort | while IFS= read -r source_file
  do
    shasum -a 256 "$source_file"
  done
) > "$R8_EVIDENCE/historic-videos-batch-01.sha256"

ssh <production-user>@<production-host> \
  'umask 077; mkdir -p /srv/crockenhill/storage/scratch/r8-input/historic-videos/batch-01'
rsync -a "$LOCAL_VIDEO_BATCH/" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/historic-videos/batch-01/
scp "$R8_EVIDENCE/historic-videos-batch-01.sha256" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/historic-videos-batch-01.sha256
```

On production:

```bash
dc exec -T -u www app mkdir -p "$R8_STAGE/historic-videos/batch-01"
dc cp /srv/crockenhill/storage/scratch/r8-input/historic-videos/batch-01/. \
  "app:$R8_STAGE/historic-videos/batch-01/"
dc exec -T app chown -R www:www "$R8_STAGE/historic-videos"

dc exec -T -u www app sh -lc '
  cd "$1"
  find . -type f -print | LC_ALL=C sort | while IFS= read -r source_file
  do
    sha256sum "$source_file"
  done
' sh "$R8_STAGE/historic-videos/batch-01" \
  > "$R8_HOST_EVIDENCE/historic-videos-batch-01.sha256"

diff -u \
  /srv/crockenhill/storage/scratch/r8-input/historic-videos-batch-01.sha256 \
  "$R8_HOST_EVIDENCE/historic-videos-batch-01.sha256"
```

`diff` must produce no output. Do not stage the whole historic archive.

### 6.5 Historic video batch

Use the existing historic-video runbook pattern: dry-run first, then one serial production item
before any larger batch.

```bash
art sermons:import-historic-videos \
  --dir="$R8_STAGE/historic-videos/batch-01" \
  --dry-run

art sermons:import-historic-videos \
  --dir="$R8_STAGE/historic-videos/batch-01" \
  --parallel=1 \
  --limit=1
```

See [LLM structure promotion soak](llm-structure-promotion-soak.md) for staging, disk-space and
end-to-end review details. Keep this importer through R12's bulk backfill.

**This is the fallback path, not the intended one.** Re-processing in production pays for
transcription and structure detection a second time and can produce a different result from the
local run the operator reviewed. The
[historic archive import and promotion plan](../plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)'s
Stage B replaces it with a create-only promotion bundle — `project()` needs only the processing log,
its `service_sections` and `processing_metadata`, so the service graph is rebuildable in production
from portable data with no media. Delete §6.4 and §6.5 in favour of it once Stage B lands; until
then this section stands, and §6.0's Spaces fingerprint check is what keeps it honest.

### 6.6 Manual and children's-talk records

Use the supported admin/upload flow for genuinely metadata-only records and children's talks.
The legacy MP3 batch importer is a sermon importer; do not silently coerce a children's talk into
the sermon content type. Preserve manual title/preacher/scripture decisions explicitly.

### 6.7 Sermon completion gate

Regenerate both environment row/key/duplicate manifests after each completed batch. For every
local row, record the production sermon ID and source checksum or the explicit discard decision.
The gate is not equal total counts; it is:

- no unresolved local-only candidate;
- every promoted asset exists on its configured production disk;
- every promoted record has a non-null `preacher_id`;
- duplicates in both environments have been deliberately resolved or accepted;
- tape digitisation is declared final before deleting `LegacySermonImporter`.

## Phase 7 — Local end state

Exact numeric IDs, complete processing history, queue history and synthetic email cache rows do
not need to match. Each promoted legacy MP3 does need one portable provenance log so its source
hash and automated origin survive. The intended local convergence is:

- identical source hashes for songs and historic OoS;
- equivalent song `canonical_key` values;
- equivalent service `(date, service)` coverage from the source archives;
- every valuable local-only sermon promoted or deliberately retained/discarded;
- zero local song-link drift after its own source imports.

Do not restore an unsanitized production backup locally. It contains authentication data, real
email bodies/headers, webhook data, operational paths and error context. There is currently no
purpose-built sanitized snapshot tool. Until one is designed, prefer the same source imports plus
private natural-key manifests. Keep the pre-convergence local backup until R8 closes.

The production `play_date` usage may remain an intentional local difference. A future local refresh
must use a purpose-built, sanitized domain export with explicit ID remapping; that is not part of
this one-shot run.

### 7.1 Compare the resulting domain keys

Capture the local keys after all local source imports:

```bash
local_dbq "
SELECT canonical_key
FROM songs
WHERE deleted_at IS NULL
ORDER BY canonical_key;
" | LC_ALL=C sort -u > "$R8_EVIDENCE/local-song-keys.txt"

local_dbq "
SELECT CONCAT_WS('|', DATE_FORMAT(date, '%Y-%m-%d'), service)
FROM church_services
GROUP BY date, service
ORDER BY date, service;
" | LC_ALL=C sort -u > "$R8_EVIDENCE/local-service-keys.txt"
```

Capture the equivalent production files in the production shell:

```bash
dbq "
SELECT canonical_key
FROM songs
WHERE deleted_at IS NULL
ORDER BY canonical_key;
" | LC_ALL=C sort -u > "$R8_HOST_EVIDENCE/prod-song-keys.txt"

dbq "
SELECT CONCAT_WS('|', DATE_FORMAT(date, '%Y-%m-%d'), service)
FROM church_services
GROUP BY date, service
ORDER BY date, service;
" | LC_ALL=C sort -u > "$R8_HOST_EVIDENCE/prod-service-keys.txt"
```

Copy the production files into `$R8_EVIDENCE`:

```bash
scp <production-user>@<production-host>:\
/srv/crockenhill/storage/scratch/r8/<run-id>/prod-song-keys.txt \
  "$R8_EVIDENCE/prod-song-keys.txt"
scp <production-user>@<production-host>:\
/srv/crockenhill/storage/scratch/r8/<run-id>/prod-service-keys.txt \
  "$R8_EVIDENCE/prod-service-keys.txt"
```

Then record both sides of each comparison:

```bash
comm -23 "$R8_EVIDENCE/local-song-keys.txt" "$R8_EVIDENCE/prod-song-keys.txt" \
  > "$R8_EVIDENCE/song-keys-local-only.txt"
comm -13 "$R8_EVIDENCE/local-song-keys.txt" "$R8_EVIDENCE/prod-song-keys.txt" \
  > "$R8_EVIDENCE/song-keys-prod-only.txt"

comm -23 "$R8_EVIDENCE/local-service-keys.txt" "$R8_EVIDENCE/prod-service-keys.txt" \
  > "$R8_EVIDENCE/service-keys-local-only.txt"
comm -13 "$R8_EVIDENCE/local-service-keys.txt" "$R8_EVIDENCE/prod-service-keys.txt" \
  > "$R8_EVIDENCE/service-keys-prod-only.txt"
```

Song differences after the identical catalogue sync and service differences inside the imported
historic source range require an explicit explanation. Record intentional local fixtures,
production-only live services and the production-only `play_date` usage as allowed differences.
Source hashes alone are not a parity result.

### 7.2 Compare what the services actually contain

`(date, service)` coverage says a slot was filled, not that it was filled with the right thing. A
service can hold the correct key and the wrong items, the wrong reading, or a plan that no source
ever corroborated. Run this in both environments and compare the two files:

```bash
# In each environment, substituting local_dbq / dbq and the evidence directory.
dbq "
SELECT
    CONCAT_WS('|', DATE_FORMAT(cs.date, '%Y-%m-%d'), cs.service),
    COUNT(csi.id),
    SUM(csi.type = 'songs'),
    SUM(csi.type = 'bibles'),
    SUM(csi.song_id IS NOT NULL),
    SUM(JSON_CONTAINS_PATH(csi.metadata, 'one', '\$.source_evidence.livestream')),
    SUM(JSON_CONTAINS_PATH(csi.metadata, 'one', '\$.source_evidence.openlp')),
    SUM(JSON_CONTAINS_PATH(csi.metadata, 'one', '\$.source_evidence.email'))
FROM church_services cs
LEFT JOIN church_service_items csi
    ON csi.church_service_id = cs.id AND csi.deleted_at IS NULL
GROUP BY cs.date, cs.service
ORDER BY cs.date, cs.service;
" > "$R8_HOST_EVIDENCE/prod-service-composition.tsv"
```

`metadata.source_evidence` accumulates every source that has ever asserted an item, so those last
three columns are the corroboration record: an item with `livestream` evidence was observed
happening, one without it was only ever planned, and one with two or three is agreed by that many
independent sources. A service whose items carry no livestream evidence at all is a plan, not a
record of what happened — correct for a date with no recording, and worth a second look on a date
that has one.

Differences between the two environments in item counts, song-link counts or evidence coverage need
the same explicit explanation as a missing key. Record them beside the key comparison.

### 7.3 Closeout gate

Do not declare the convergence finished, and do not delete an importer, while any of these is
outstanding. Each is a query, not a judgement:

```bash
# Archive entries a person could still act on.
dbq "SELECT status, COUNT(*) FROM inbound_emails
     WHERE message_id LIKE '%oos-archive-%' GROUP BY status;"

# Staged proposals nobody adjudicated.
dbq "SELECT COUNT(*) FROM church_services WHERE pending_structure_merge_source IS NOT NULL;"

# Services still asking for attention, and why.
dbq "SELECT review_reason, COUNT(*) FROM church_services
     WHERE needs_review = 1 GROUP BY review_reason ORDER BY COUNT(*) DESC;"
```

Require:

- `pending` archive emails are zero, and every `archive_eval` entry is a `blocked` one whose
  markdown genuinely contradicts itself;
- zero pending structure merges, with every `superseded_proposals` entry read before its parent was
  resolved;
- every remaining `needs_review` service has a recorded reason the operator accepted — including the
  seven aliased `.osz` imports, which arrive flagged by design (§1.3);
- §7.2's composition comparison has an explanation for every difference.

## Remaining non-data R8 checks

- `PreacherCutoverCommand`: passed; production null `preacher_id` count is zero. Recheck after
  sermon promotion.
- `ConvertJpgToWebp`: delete the command, but do not run it for real. The remaining JPGs are
  classified live assets/counterparts, not a conversion backlog.
- `MeetingMigratePhotosCommand`: mapped folders produced zero migrations and zero errors. Resolve
  the `sunday-services` folder-to-current-meeting mapping and visually verify the relevant public
  pages before deleting its legacy folders/service.
- `FixUploadDirectories`: do not run it as a test. Mounted runtime roots are writable; confirm no
  cron, systemd or provisioning process invokes `upload:fix-directories`.
- `ImportOpenLpDirectoryCommand`: keep until the complete `.osz` source set has been imported and
  reviewed in production and declared final.
- `ImportOosArchiveCommand`: keep until the Markdown import/idempotency run passes in production and
  the archive is declared final.
- `HistoricVideoImporter`: keep until R12 completes.

## Failure and rollback

For an interrupted chunked/per-archive run, retain the output and rerun only after understanding
the failure. Song linking, media identity and the import commands are designed for repeatable
postcondition checks. Do not manually delete rows to make counts look right.

At a failed production gate, stop further mutations and keep Horizon paused while deciding whether
to retry or restore. Do not leave the public site in maintenance mode unattended, but do not run
`art up` merely to shorten the outage: the partial state must first be explicitly judged safe for
public reads. If it is safe and the decision is deferred, bring the site up; resume Horizon only
after confirming that queued reconciliation or processing jobs are also safe against that state.

For a semantically incorrect successful production mutation:

1. Pause Horizon and enter maintenance mode.
2. Stop all further imports and preserve command output/reports.
3. Identify every legitimate write made after the pre-run backup. A whole-DB restore will remove
   those writes too.
4. Use the already-tested restore procedure and the exact private backup recorded for this run.
5. Re-run migrations/health checks, leave maintenance mode, resume Horizon and re-verify the site.

Do not improvise the restore command during the incident. A code/image rollback does not undo
committed data.

Pipeline-based sermon imports also create queue work and assets in Spaces. A database restore alone
is not a complete rollback for those imports: cancel/drain the processing runs first, then
reconcile storage artifacts against the restored database with the existing asset-audit tooling.
The portable MP3 bundle is different: it must create no object and queue no work, so roll it back
through the database only and never delete its shared pre-existing Spaces objects.

## R8 closeout checklist

- [ ] Source SQLite, `.osz`, Markdown, Tape Index, portable sermon bundles and any fallback-media
      checksums recorded privately.
- [ ] Curated 428-file `.osz` directory built (§1.3), count-gated in both environments, imported and
      reviewed in production and rehearsed locally.
- [ ] Markdown OoS import and idempotency rerun passed in production.
- [ ] §7.3 closeout gate passed: zero actionable archive emails, zero pending structure merges,
      every remaining `needs_review` service explained.
- [ ] §7.2 service-composition comparison run in both environments and every difference explained.
- [ ] Song canonical-key count is zero in production and local.
- [ ] Production `play_date` accounting count is zero.
- [ ] Production and local song-link drift are zero.
- [ ] Production media-identity dry run reports `Would update = 0`.
- [ ] Local-only sermon ledger has no unresolved entries.
- [ ] Local and production Spaces location fingerprints match; promoted MP3 hashes/sizes pass.
- [ ] Portable promotion bundle second dry run reports only already-present/no-op entries.
- [ ] Every promoted sermon asset and relationship has been reviewed.
- [ ] Preacher null count remains zero after sermon promotion.
- [ ] Meeting `sunday-services` photo mapping and external upload-directory automation checks closed.
- [ ] Horizon, health checks and public smoke check pass.
- [ ] Only staged temp copies are removed; original source archives and private backups are retained.
- [ ] Aggregate counts, artifact date/label and completion dates recorded in the R8 plan; source
      hashes and raw evidence remain private.
