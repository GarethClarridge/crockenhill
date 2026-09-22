# Song title cleanup and durable alternative titles

**Status: planned, 2026-09-22.** Code paths checked; production data has not been
remeasured or changed. This document owns catalogue title cleanup and enrichment.
D1 revised following maintainer direction: prepare one-off corrections here, export
them back to OpenLP manually, then retain OpenLP as the source of truth. No permanent
website override layer is planned. Live database replacement remains an operator step.

**Who benefits:** service planners, catalogue visitors and the operator.
**What observably improves:** songs can be found by their familiar names, titles do
not contain hymnbook numbers, and corrections survive the next OpenLP sync.

## Existing work and scope

The [historic video acceptance plan](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md)
records the September 18 investigation under BC-06: 686 of 799 populated
`alternate_title` values were number-position variants, and six genuine aliases
were identified from catalogue lyrics. Those are dated measurements, not a fresh
census. Its ruling was to correct OpenLP because all 1,160 live songs were managed
by sync. No local alias correction was applied in that work.

That is a bounded correction proposal, not a catalogue-wide cleanup plan. This
plan takes ownership of the broader work and retains the earlier OpenLP-first ruling,
with correction preparation and export performed here. Historic identity binding, acceptance,
publication and import retirement remain with their existing plans. Better aliases
do not authorise automatic relinking or removal of content holds.

## Verified constraints

- `app/Services/Song/SongCatalogSyncService.php` groups by a canonicalised OpenLP
  `search_title`, chooses the newest source row (highest ID breaks ties), and
  overwrites `title`, `alternate_title`, `praise_number` and `import_metadata`.
  Local edits to these values alone cannot survive sync.
- `praise_number` currently comes from a hash-number in the source title. Removing
  that number in OpenLP without changing sync would clear the separate field.
- `Sync/OpenLpSongSourceReader.php` already reads songbook links and their `entry`;
  `Sync/SongAuthorBookSyncer.php` replaces the corresponding local pivots at sync.
  Book identity matters: a bare number is not globally a Praise! number.
- `Song::canonicalizeKey()` preserves the title/number part before `@`. Renaming
  the source can therefore change identity lookup, not just presentation. Existing
  source IDs are retained in metadata but are not the primary upsert key.
- Public catalogue SQL searches `songs.title` and `songs.alternate_title` directly;
  `SongTitleResolver` loads those fields too. A display-only accessor would leave
  search and matching inconsistent. Existing slugs are retained on update.
- `alternate_title` is the live field. The legacy `alternative_title` is removed;
  do not recreate it.
- Read-only inspection of `storage/scratch/songs.sqlite` confirmed `songs.id`,
  `title`, `alternate_title`, `search_title`, `search_lyrics`, `last_modified`, plus
  `songs_songbooks(songbook_id, song_id, entry)`. Its first rows show the reported
  number-position aliases. `storage/scratch/songs (1).sqlite` also exists; neither
  copy is assumed to be the latest operational database without comparison.

## D1 — one-off correction here, manual export to OpenLP

**Chosen direction, 2026-09-22:** prepare and research corrections in this project,
verify them against a disposable website catalogue, and export a corrected copy of
the OpenLP SQLite database for manual installation. Once installed, that corrected
OpenLP database remains authoritative and ordinary imports preserve the improvements
because they now contain the same data. This replaces this plan's initial recommendation
for persistent website overrides; that extra state is unnecessary for a one-off cleanup.

Keep a reviewed correction artifact with website/source song IDs, original values,
replacement values (including intentional empty aliases), source fingerprint and
research provenance. This is an operation artifact, not a new runtime data-ownership
layer. Use the existing single `alternate_title` field; record additional candidates
separately if one alternative proves insufficient, rather than concatenating names.

Correct locally first, but do not leave production relying on local edits while it
still imports the old database. Rehearse in a disposable catalogue. At cutover, pause
song sync, install the corrected database in OpenLP, refresh the source copy used by
the website, apply the verified identity mapping and sync, then resume normal imports.
Any direct production correction belongs inside that same controlled interval.

## Delivery sequence

### ST1 — inventory and reviewable correction list

Produce a read-only inventory of current songs and the actual maintained OpenLP
source. Capture IDs, source IDs/group representative, keys/slugs, raw titles and
aliases, structured book entries, Praise! number, CCLI/authors and lyric fingerprint.
Classify aliases as empty, formatting/number-only duplicate, distinct candidate,
or ambiguous. The previously recorded 113 values with different words still need
review; different words alone do not establish a genuine alias.

Preview proposed before/after values, reasons, number conflicts and identity
collisions. Preserve numerals that belong to a real title, including psalm numbers;
remove only recognised book-number decorations with supporting metadata. Preserve
letter suffixes and meaningful book editions. Blank is preferable to invented data.

**Outcome:** the operator can see exactly what will change. No database writes,
bulk web lookup or new paid service is needed to establish the scope.

### ST2 — make sync safe before cleaning data

Deliver number and identity protections before editing source titles. Do not add
curation override columns, precedence rules or a permanent two-way synchroniser.

Use validated structured Praise! book entries as the preferred number source,
with an explicit mapping to the correct book/edition. Retain legacy title extraction
as a compatibility fallback while old sources exist. Report conflicts and preserve
the last verified number pending resolution; missing metadata must not silently
erase an established number. Define deliberate number removal separately.

Keep website song IDs, URLs and historical foreign keys stable. Do not recompute
canonical keys from cleaned display names. Handle source renames using verified
source-row correspondence scoped to the maintained source database; SQLite IDs
alone are not portable across replaced databases. Preview group splits, merges,
changed representatives, source-ID reuse and canonical-key collisions. Ambiguous
identity changes must stop the affected update for review rather than create or
merge songs automatically. Do not refactor the retiring `LegacySongReconciler`.

Retain raw source titles and compatibility lookup forms separately from genuine
aliases. Preserve number-based and old-source-title matching without showing those
forms as alternate titles. Ensure public number searches use structured number/book
fields when removal from `title` would otherwise break them.

**Outcome:** a reviewed clean title and alias survive repeated source imports;
number searches and all existing song references still work.

### ST3 — apply deterministic cleanup and the six known candidates

After ST2, apply the reviewed ST1 changes to the local rehearsal catalogue in a bounded,
idempotent operation with before-values and stale-input checks. Keep IDs, slugs, lyrics and historical source
assertions unchanged. Recheck these September 18 candidates against current rows:

| Recorded song ID | Clean title | Alternative candidate |
|---|---|---|
| 712 | O Lord My God | How Great Thou Art |
| 254 | From Heaven You Came | The Servant King |
| 644 | My Jesus My Saviour | Shout to the Lord |
| 606 | Lord You Were Rich | Thou Who Wast Rich Beyond All Splendour |
| 996 | We Trust In You | We Rest On Thee |
| 980 | We Are Here To Praise You | We Are Here To Praise Him |

The final three involve modernised/older text: retain the earlier evidence and
verify the church's version before treating them as interchangeable. “Jesus Is
Lord” remains ambiguous; do not select a song on title alone.

**Outcome:** reviewed titles contain no redundant book decorations and the known
familiar names work in public search and deterministic catalogue resolution.

### ST4 — source additional alternatives where useful

Prioritise unresolved real service titles and frequently used songs, then work
through the remaining catalogue. Start with local lyrics/refrains and existing
book metadata. Use internet references to corroborate identity, not generate names.

Sources checked on 2026-09-22:

- [OpenLP song editor manual](https://manual.openlp.org/songs.html) documents separate
  title, alternate title and songbook editing. It also permits alternate-title use
  as a grouping aid, so imported content cannot be assumed to be a true alias.
- [Hymnary's How Great Thou Art record](https://hymnary.org/text/o_lord_my_god_when_i_in_awesome_wonder)
  links that familiar title to “O Lord my God, when I in awesome wonder”.
- [Praise!'s corresponding hymn entry](https://www.praise.org.uk/hymns/o-lord-my-god-when-i-in-awesome-wonder)
  provides a publisher reference for checking the church's text.

Prefer hymnbook publishers and songwriters/publishers, with Hymnary as a supporting
index. Where available to the operator, CCLI SongSelect identifiers can help check
modern songs; no subscription or bulk access is assumed. Match using first line,
lyrics/refrain, author and book/CCLI evidence together. Do not confuse tune names,
translations, arrangements or distinct songs with aliases.

For each candidate retain the source URL, date checked, supporting identity facts,
local song ID and accepted/rejected/unresolved disposition. Use small reviewable
batches. No unsupported AI suggestions enter the live catalogue; no bulk lyrics
copying or dependency purchase is required. Check source access terms before any
automated harvesting. A song with no established alternate title stays empty.

**Outcome:** additional aliases have traceable evidence and remain useful after sync.

### ST5 — export a corrected SQLite copy and complete the round trip

Use a fresh, consistent copy of the maintained OpenLP database as the export base;
the repository copy establishes the schema but may be stale. Apply the reviewed
correction artifact to a new output file, never overwrite the input. If the base has
changed, compare before-values and lyric/identity evidence; rebase reviewed changes
or report conflicts instead of replacing recent operator edits.

Preserve source song IDs and all unrelated content, relationships and settings.
Map each website canonical song to its verified source rows: duplicate source rows
must not reintroduce the old alias when a different representative wins next sync.
Do not fan changes across a group whose members actually represent different songs.
Write verified book numbers to the appropriate songbook entries before removing
title decorations; retain other books and entries unchanged.

Update `title`, `alternate_title` and OpenLP's derived `search_title` together using
the installed OpenLP version's actual normalisation rules. Verify those rules from
its implementation or an editor-save fixture before building the exporter; the SQLite
schema alone does not specify them. Preserve `search_lyrics` if lyrics are unchanged,
and handle `last_modified` consistently without accidentally changing group winners.
Carry an explicit old/new canonical-key mapping into the website cutover so the
renamed source updates existing songs rather than creating new ones. Detect collisions;
do not keep stale OpenLP search fields merely to avoid changing website keys.

Check SQLite integrity, foreign keys, row/link counts and an allowlist of changed
fields. Open the output in the operator's OpenLP version and verify title, alternate
title and songbook searches, including after saving a corrected song. Reimport that
saved database into the rehearsal website twice and check stable IDs, links and values.

Deliver the corrected SQLite file, correction/provenance report, checksum and
original backup reference. With OpenLP closed, the operator backs up the current
database and manually installs the verified copy. Confirm the database actually used
by OpenLP and the website sync source both match the corrected version before resuming
sync. No ongoing manual patch application is required after this handoff.

**Outcome:** both applications use the same corrected catalogue, and an ordinary
OpenLP edit/save followed by website sync does not undo the cleanup.

## Verification, rollout and completion

1. Reproduce overwrite, number-loss and source-rename risks in focused tests before
   implementation. Extend `SongCatalogSyncServiceTest`, `SongTitleResolverTest` and
   public catalogue search tests and focused export tests. Cover empty aliases, repeated
   sync, source changes, duplicate representatives, collisions, real numeric titles,
   lettered hymn numbers, ambiguous aliases, stale export inputs, duplicate source rows,
   derived search fields and round-trip identity mapping. Use PHPUnit and project isolation.
2. On a disposable database copied from the current catalogue, apply the proposed
   corrections, export and import the corrected source twice, then test a newer source with
   changed names/representatives. Verify all curated values, numbers, IDs, slugs,
   usage/video/service links and resolution outcomes. Dry-run must predict effective
   field changes/conflicts, not merely the existing aggregate upsert counts.
3. For code, run focused tests, PHPStan, Pint and the parallel suite through Sail;
   any browser editing behaviour also requires Dusk. No code tests are required for
   this documentation-only planning change.
4. Before production application, bank source/database backups and a reviewed
   before/after artifact. Prevent sync from overlapping the bounded application;
   reject stale rows. Coordinate rollback across OpenLP and the website: stop sync,
   restore matching source and website naming/key state, then verify before resuming.
   Field rollback restores only touched fields whose after-values still
   match, preserving intervening legitimate edits. Any temporary command declares its
   deletion trigger: successful production round trip and expiry of the agreed rollback window.
5. Completion: all measured formatting-only aliases are removed or have an explicit
   reviewed exception; verified numbers are retained separately; approved familiar
   names resolve without conflating distinct songs; repeat imports preserve curation;
   the correction report accounts for all candidates. Remaining unknown aliases are
   reported, not filled speculatively. Historical relinking is a separate decision.

The next useful slice is **ST1**, followed by ST2, local cleanup/research and ST5's
manual handoff. This planning update does not itself execute production edits,
OpenLP replacement, paid research or historic publication.
