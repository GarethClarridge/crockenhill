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
| Historic email OoS | Original Markdown archive | Evaluate, then create-only import | Already imported; rerun only from the same/newer artifact |
| Legacy MP3 sermons | Local processed records plus their verified Spaces objects and source hashes | Promote selected local-only records through a purpose-built create-only bundle; do not upload or reprocess the audio again | Preserve until every local-only candidate is classified and promoted/rejected |
| Other sermons/video | Original media plus its metadata | Re-import selected material through the current pipeline when no safe portable record exists | Preserve until every local-only candidate is classified |
| Legacy song usage | Production `play_date` IDs | Dump and import in production | Do not import the production dump locally |
| Runtime/auth data | Production | Keep in production | Never copy raw users, tokens, sessions or real inbound email bodies |

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
- The `.osz` dry run reports unexpected updates, review items or any failures.
- A song-link dry run proposes unexpected clears.
- A fresh production DB backup cannot be located, decrypted and tied to a tested restore path.
- A media-processing run is active, or the operator cannot monitor Horizon after it resumes.
- The OoS extractor model/key, API quota or network access has not been confirmed for the
  101-entry evaluation.
- The sermon ledger still relies on counts rather than per-identity classification.
- Local and production do not produce the same non-secret Spaces location fingerprint, or a
  selected asset fails existence, size or hash verification.
- The temporary sermon promotion exporter/importer described in Phase 6 has not been implemented,
  reviewed, tested and deployed. The old SQL patch is not a fallback.

The default local `storage/mnt/services` directory contained zero `.osz` files on 2026-07-20, but
the original archive was subsequently located on the operator's external drive. Its 536 files
contain a byte-identical 105-file nested duplicate set, leaving 431 unique sources. Operator
curation retains 428 imports after 7 explicit date/service aliases and 3 explicit exclusions.
Use the private curated manifest from the 2026-07-20 evidence directory; the database rows are not
a substitute for those source files. The external source was verified through a read-only Sail
mount because copying the roughly 9 GiB recursive archive would exhaust the local system disk.

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

### 1.3 Capture local sermon identities and duplicates

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

From the Mac, transfer the current SQLite, Markdown archive and complete `.osz` source to a
private directory on the production host using the configured SSH-key login. Replace the host
placeholder; do not weaken SSH authentication for this transfer.

```bash
ssh <production-user>@<production-host> \
  'umask 077; mkdir -p /srv/crockenhill/storage/scratch/r8-input/openlp-osz'

scp "storage/scratch/songs (1).sqlite" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/openlp-songs.sqlite

scp storage/scratch/crockenhill_orders_of_service_archive.md \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/oos-archive.md

rsync -a "$OPENLP_OSZ_SOURCE/" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/openlp-osz/

scp "$R8_EVIDENCE/openlp-osz.sha256" \
  <production-user>@<production-host>:/srv/crockenhill/storage/scratch/r8-input/openlp-osz.sha256
```

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
```

`diff` must produce no output. The relative filenames and hashes must match exactly.

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

Copy the authoritative `.osz` set under `storage/scratch/r8-input/openlp-osz` so Sail can see it,
then run:

```bash
mkdir -p storage/scratch/r8-input/openlp-osz
rsync -a "$OPENLP_OSZ_SOURCE/" storage/scratch/r8-input/openlp-osz/

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
`--import`; the import is create-only.

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

The `.osz` import must precede the Markdown import. Production has only two OpenLP services while
local has 395; importing the create-only Markdown archive first would let the lower-fidelity email
source occupy slots that should be represented by OpenLP.

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

### 5.3 Import the complete historic `.osz` archive

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

### 5.4 Evaluate and create-only import the Markdown OoS archive

Evaluation is mutating: it writes/synchronises synthetic inbound emails and invokes the extractor.
It is run after the backup and before `--import` so the private report can be reviewed.
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
Privately inspect the full aggregate, blocked entries, failures and eligible plans. Then:

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
no `created`, `failed` or `import_failed`; eligible occupied slots should report
`skipped_existing`. The console summary alone is not sufficient evidence.

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

### 6.1 Engineering prerequisite: portable sermon promotion commands

There is no safe command for this operation in the repository yet. Implement, review and deploy a
temporary pair before any production write. The names below are the required interface, not
commands that can be run from the current release:

```text
sermons:export-promotion-bundle --ids=<comma-separated-local-ids> --output=<private-json-path>
sermons:import-promotion-bundle --path=<private-json-path> [--verify-hashes] [--apply]
```

Both command docblocks must state their deletion trigger: delete them with their tests after the
R8 ledger has no unresolved/promote entries and the production idempotency run passes.

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
- [ ] Complete `.osz` archive imported/reviewed in production and rehearsed locally.
- [ ] Markdown OoS import and idempotency rerun passed in production.
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
