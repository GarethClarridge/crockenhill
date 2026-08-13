# Historic Import Safety Remediation Plan

> **Status (2026-08-13): HIR0–HIR4, HIR6 and HIR7 complete; HIR5 and HIR8 remain; production remains
> NO-GO.** Verified
> against `ac1468b47` and the findings in
> [the 10–12 August commit review](../reviews/historic-import-commit-review-2026-08-12.md).
> The eight red tests and the change-control baseline are recorded in
> [the HIR0 baseline](../reports/historic-import-hir0-baseline-2026-08-13.md); one red test remains,
> `VerifyHistoricImportRecoveryCommandTest`, which is HIR5's to close.
> Do not run any production historic import, release, source-acquisition acceptance, recovery
> acceptance or exact closeout command until this plan's applicable packages have landed and the two
> governing historic plans have been updated with new rehearsal evidence.
>
> **Authority:** this is a temporary implementation addendum, not a third historic-import authority.
> [Historic Archive Final Import Readiness](HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md)
> remains the sole go/no-go and phase-order owner. [Historic Archive Import Readiness
> Remediation](HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md) remains the owner of G0–G9,
> Bundle A/B and the technical import contract. Package completion evidence from this document must
> be folded into those owners; this file is archived when all packages are incorporated and no
> separate executable sequence remains.
>
> **Human authority required:** the maintainer/operator must supply the production resource anchors,
> approve independent evidence-key custody, confirm the source-filesystem support envelope, and
> explicitly permit the narrow safety work in deletion-scheduled one-shots. Production mount,
> restore, rollback, object-store and closeout acceptance remains operator-run. This plan authorises
> no dependency change and no production command.

## 1. Outcome

Close the eight defects found in the 10–12 August review without weakening the existing exactness,
quarantine or one-shot-retirement programme:

1. a release attempt cannot overwrite or delete bytes owned by another attempt or writer;
2. source custody is proved from observed storage facts rather than signed claims alone;
3. recovery acceptance authenticates the verifier and checks the artifacts it names;
4. a mislabelled process cannot escape the production guard because release/config/schema drifted;
5. an OoS parse is always resolved under the exact current curation authority;
6. every absent Scripture Passage has an explicit approved terminal outcome;
7. exact closeout waits until deferred inbound email has actually finished; and
8. the Scripture enrichment command respects its API delay on unsuccessful calls.

**Who benefits:** the operator, the independent verifier, church members whose orders of service
arrive during the window, and visitors consuming released historic material.

**What observably improves:** the release candidate passes adversarial concurrency, forged-evidence,
same-disk custody, curation-change and queued-but-unprocessed-email tests; a production-shaped
rehearsal then completes restore, rollback, exact no-op and closeout without relying on a claim the
application did not observe.

## 2. Relationship and boundaries

### 2.1 Existing owners

| Concern | Executable owner | This plan's boundary |
|---|---|---|
| Final verdict, source-drive connection, phase order and business acceptance | Historic final-readiness plan | This plan adds no waiver and cannot change NO-GO to GO |
| G0–G9, source manifests, Bundle A/B, rehearsal and exact import closeout | Historic readiness-remediation plan | Packages below repair implementation contracts and report their effect on the owning gate |
| Generic storage durability, queue timing, logging and long-lived processing architecture | [Architectural Maintainability Delivery](ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md) | Reuse generic seams where available; do not pull AM work into the one-time import |
| Historic one-shot retirement | Historic G9/WP10 | Safety changes remain removable and name their deletion trigger |
| General test-notice/PHPStan cleanup | Code-quality plan | Existing notices are recorded, not repaired here |

### 2.2 In scope

- The eight findings in the linked review and only the adjacent schema/config/test changes required
  to make their contracts executable.
- Additive migrations for release ownership and deferred-email leases.
- Versioned source-custody, recovery and operational-closeout evidence.
- Invalidation and regeneration of affected parse caches, Bundle A artifacts, operation bindings and
  approvals.
- Production-shaped rehearsal and the evidence needed to update G1–G8; release acceptance remains
  after the operation's exact closeout in the governing sequence.

### 2.3 Out of scope

- Running the production import, changing archive curation decisions, processing the source drive or
  performing the public release.
- General refactoring or polishing of `ImportOosArchiveCommand`, `HistoricVideoImporter` or other
  deletion-scheduled tooling.
- New packages, a new queue backend, a new document-signing service, or generic backup architecture.
- Resolving F60–F62 hymn-workbook work except where shared gates must continue to refuse it.
- Rewriting the two governing historic plans or the production runbook before implementation facts
  and rehearsal output exist.

## 3. Safety invariants

Every implementation PR must name the invariant it makes executable.

1. **Stable target identity:** stable production database/storage anchors decide whether a target is
   production; release, schema and feature configuration decide whether an operation is still the
   approved operation. Drift in the latter never proves the former is non-production.
2. **Authority before cache:** model extraction may be cached; manifest-owned date, service, scope
   and supersession are applied from the current approved entry on every run.
3. **Settled Scripture:** exactly one of a linked portable Scripture identity or an approved terminal
   absence is present. Missing evidence is never interpreted as absence approval.
4. **Observed custody:** signatures authenticate a custody statement but cannot substitute for
   observed device, mount, write-protection, link, xattr and inventory facts.
5. **Verified recovery:** an accepted recovery artifact authenticates its verifier and is backed by
   artifacts whose bytes, sizes, storage identities and disposable restores were checked.
6. **Processed means processed:** queue dispatch is an intermediate state. Closeout admits only an
   operation-scoped deferred row with `state=processed` and `processed_at` set after successful
   parse/import disposition.
7. **One release owner:** a durable globally unique destination claim exists before object I/O. No
   database transaction remains open across object storage.
8. **Exact compensation:** cleanup either deletes the exact object version/receipt created by the
   failed attempt or does not delete. A path/hash match alone is not ownership.
9. **Versioned evidence:** version 1 source/recovery/operational evidence remains retained for audit
   but cannot satisfy the repaired gate.
10. **Temporary stays removable:** every new historic-only command or field names the G9/WP10
    deletion trigger; no package expands the permanent product surface.

## 4. Decisions and discovery gates

Each decision blocks only the packages listed. Record answers here and in the governing plan before
implementation uses them.

| ID | Decision or discovery | Recommendation | Blocks | Status |
|---|---|---|---|---|
| HIR-D1 | Which atomic create/object-receipt primitives do the local filesystem and production DigitalOcean Spaces adapter actually support? | Require create-if-absent. Prefer an immutable version ID for cleanup; if exact-version deletion is unavailable, failed final objects are retained as explicit orphans and never auto-deleted | HIR7 implementation and HIR8 object exercise | **Decided 2026-08-12 — measured, see §4.1** |
| HIR-D2 | What stable database and storage identities are observable in every environment allowed to mutate historic state? | Database server UUID + schema identity; storage endpoint/account + bucket/prefix (or local filesystem identity), all canonical-hashed without credentials | HIR1 activation | **Decided 2026-08-12 — database anchor only, see §4.2** |
| HIR-D3 | Who holds the recovery-evidence signing key and what key ID/rotation rule applies? | A recovery-only HMAC key held by the independent-verifier workflow, distinct from application approval/source evidence keys; deploy verification material only for the accepted window | HIR5 | **Decided 2026-08-12 against recommendation — see §4.3** |
| HIR-D4 | Which acquisition hosts/filesystems are supported? | Explicit Darwin/APFS and production Linux filesystem adapters only; unknown platforms or unobservable mount facts fail closed | HIR4 | **Decided 2026-08-12 — recommendation adopted as written** |
| HIR-D5 | May safety defects be fixed inside deletion-scheduled `ImportOosArchiveCommand` and companion tests? | Approve a bounded exception for HIR2/HIR8 only; put cache-binding logic in a small service and make no unrelated command/test investment | HIR2 and affected rehearsal coverage | **Decided 2026-08-12 — bounded exception approved as recommended** |

No decision may be inferred from a test fixture. In particular, a successful local adapter test is
not evidence that Spaces supports the same conditional write/delete semantics.

### 4.1 HIR-D1 outcome — measured against production Spaces, 2026-08-12

A read-only capability probe plus a scratch-key write probe were run against the production
`crockenhill` bucket (`lon1`). No real sermon key was touched, no pre-existing object was deleted,
and a follow-up `ListObjectsV2` confirmed zero residue under the scratch prefix.

| Capability | Result |
|---|---|
| `PutObject` `IfNoneMatch: *` on an absent key | **Accepted** — create-if-absent is available |
| `PutObject` `IfNoneMatch: *` on a present key | **Refused, 412 `PreconditionFailed`** — genuinely enforced |
| `PutObject` `IfMatch` with a wrong ETag | **Refused, 412 `PreconditionFailed`** |
| `PutObject` response `VersionId` | `null` — bucket versioning is disabled and stays disabled |
| **`DeleteObject` `IfMatch` with a stale ETag** | **DELETED — the header is silently ignored** |
| Local filesystem create-if-absent | Unavailable via Flysystem: `LocalFilesystemAdapter::writeToFile()` is `file_put_contents`, which truncates. Use `fopen($path, 'x')` |
| Flysystem passthrough | `AwsS3V3Adapter::AVAILABLE_OPTIONS` omits both conditional headers, so `Storage::put(..., ['IfNoneMatch' => '*'])` **silently drops them**. The raw `Aws\S3\S3Client` (signature `s3v4`) is reachable via `Storage::disk('do_spaces')->getClient()` |

**Consequences binding on HIR7.**

1. Conditional create **is** the required primitive and it works. `HistoricReleaseObjectStore` must
   create final objects through the raw client with `IfNoneMatch: '*'`, never through the Storage
   facade, and never as `exists()` + `writeStream()`.
2. Exact-ownership deletion is **unavailable**. Versioning is off, and conditional delete is
   silently ignored — so a compensation path built on `DeleteObject` `IfMatch` would pass review,
   pass a local fake, and still destroy the winner's bytes in production. This is the precise trap
   §4's "no decision may be inferred from a test fixture" warns about.
3. Therefore the plan's recorded fallback applies as written: **failed final objects become retained
   `orphaned` ledger rows for operator reconciliation, and automatic path or conditional deletion is
   prohibited.** Record this against FR-D6 in the final-readiness plan.
4. Any local implementation of the boundary must refuse conditional delete rather than emulate it,
   so the local fake cannot certify a capability production lacks.

### 4.2 HIR-D2 outcome — database anchor only

Observable identities, both canonical-hashed without credentials:

- **Database:** `@@server_uuid` (`4e4d873e-fbd1-11f0-92b9-f209517d1fcb` locally, MySQL 8.0.45) plus
  schema-name hash. Caveat: `server_uuid` is regenerated if the data directory is reinitialised, so
  a managed-database rebuild or node replacement changes it. That fails closed, which is safe, but
  the anchor must be re-recorded before a window rather than discovered during one.
- **Storage:** endpoint `https://crockenhill.lon1.digitaloceanspaces.com` plus bucket `crockenhill`.
  Note `bucket_endpoint => true` means the endpoint already embeds the bucket, and the live region is
  `lon1` while the config default is `nyc3`.

**Decision: only the database anchor triggers production controls. The storage anchor is computed
and recorded in the target fingerprint but is not an OR-ed production trigger.**

This was taken against the recommendation, and the reason it was on the table is load-bearing:

```
.env: SERMON_STORAGE_DISK=do_spaces, DO_SPACES_BUCKET=crockenhill
      => media-processing.storage.sermon_disk resolves to the PRODUCTION bucket in local dev
```

`HistoricSermonPublicationService` writes releases to `sermon_disk`
(`HistoricSermonPublicationService.php:44`), so an OR-ed storage anchor would have classified the
local environment as production and refused the rehearsal that §13.5 requires.

**Accepted residual risk:** with the storage anchor demoted, a local historic release run still
writes to the production public bucket, and the production guard will not stop it. HIR1 therefore
closes the *volatile-drift* half of review finding #4 but leaves the storage half open by
configuration. HIR7 must carry a compensating refusal — see §4.2.1.

#### 4.2.1 Required compensating control

Because the storage anchor no longer guards, HIR7's object-store boundary must refuse, outside
production, to write to a destination whose resolved bucket/endpoint matches the recorded production
storage anchor — unless an explicit, separately named local override is set. This keeps the
rehearsal usable while making "local run publishes to the production bucket" an error rather than a
silent success. Add it to HIR7's red tests, not as a runtime flag on the guard.

### 4.3 HIR-D3 outcome — approval key reused, against recommendation

**Decision: recovery evidence is signed with the existing approval signing key rather than a
separate recovery-only key.**

Recorded plainly because it narrows what HIR5 can claim. `HistoricImportApprovalManifest::verify()`
authenticates approvals with `hash_hmac('sha256', …)` (`HistoricImportApprovalManifest.php:48`) — a
**symmetric** secret the application must already hold in order to verify. Reusing it for recovery
evidence means the application holds everything required to *generate* a valid recovery artifact.

Review finding #3 was that recovery evidence is accepted without authenticating its verifier. Key
reuse closes the "unsigned" half — a random party still cannot forge an artifact — but it does not
establish verifier *independence*, because the signature no longer distinguishes the independent
verifier from the application itself.

**This is a formally accepted limitation, not an oversight**, following the same clause used for the
F46 logging decision. It constrains HIR5 as follows:

- HIR5 must still implement byte-level artifact verification (recomputed digests, sizes, storage
  identities, disposable restores). That half of the finding is unaffected and is where the real
  assurance now comes from.
- HIR5's acceptance text and the recovery artifact schema **must not claim verifier independence**.
  The signature attests integrity and approval-key custody only.
- The `signature.key_id` field already exists in the approval format, so a distinct key ID should
  still be issued for recovery artifacts. This gives forward compatibility if the decision is
  revisited, but on its own it grants no independence while the underlying secret is shared.
- Revisit before the *public release* step if an external auditor is ever required to attest
  recovery independently.

## 5. Delivery map and sequencing

Review-surface size describes blast radius, not elapsed time.

| Review finding | Owning package | Required loop-back |
|---|---|---|
| Release loser can delete the winner's object | HIR7 | Release authority, object-recovery exercise and post-closeout release rehearsal |
| Custody accepts claimed independence/protection | HIR4 | Source acquisition, custody and inventory hashes |
| Recovery accepts unsigned, unverified claims | HIR5 | Recovery artifact and G7/G8 acceptance |
| Volatile drift can bypass the production guard | HIR1 | Target/runtime fingerprint, operation and approval |
| OoS cache omits current curation authority | HIR2 | Clean staging, Bundle B/source hashes and G2 evidence |
| Missing Scripture outcome is treated as approved absence | HIR3 | Bundle A, normal-output contract and preflight |
| Deferred email dispatch is treated as completion | HIR6 | Operational evidence and exact closeout |
| Scripture API delay skips failed attempts | HIR3 | Enrichment command rehearsal only; Bundle A changes only if outcomes change |

| Package | Outcome | Size | Gate impact | Depends on |
|---|---|---:|---|---|
| HIR0 | Baseline, red tests, governance exception and production NO-GO remain explicit — **done 2026-08-13** | S | All | HIR-D5 for OoS work |
| HIR1 | Stable production resource anchors fail closed under volatile drift — **done 2026-08-13** | M | F46 / G8 | HIR-D2 |
| HIR2 | OoS cache is bound to raw input and current entry authority | M | G1/G2/G5; F49/F53/F63 | HIR0/HIR-D5 |
| HIR3 | Scripture absence and API pacing contracts are exact | S | F59 / G1/G4/G5 | HIR0 |
| HIR4 | Source custody version 2 proves independent protected copies | L | F36 / G5/G7 | HIR-D4 |
| HIR5 | Recovery evidence version 2 is authenticated and byte-verified | L | F45 / G7/G8 | HIR-D3; HIR7 before final exercise |
| HIR6 | Deferred inbound state machine blocks closeout until success | M | F56/F57 / G9 | HIR0 |
| HIR7 | Release ledger and object-store boundary eliminate ownership races | L | F29/F45; post-closeout release | HIR-D1, HIR1 |
| HIR8 | All invalidated artifacts are regenerated and the full rehearsal is repeated | L/operator | G1–G8 readiness; release exercise after exact closeout | HIR1–HIR7 |

```text
HIR0 baseline / NO-GO / red-test homes
 ├─ HIR-D2 ─> HIR1 stable production anchors ──────────────────────────┐
 ├─ HIR-D5 ─> HIR2 raw parse + current curation binding ──┐            │
 ├─────────> HIR3 Scripture settlement + pacing ──────────┴─> re-export┤
 ├─ HIR-D4 ─> HIR4 observed source custody v2 ─────────────────────────┤
 ├─────────> HIR6 deferred-email completion state ─────────────────────┤
 └─ HIR-D1 ─> HIR7 durable release ownership ─> HIR-D3 ─> HIR5 v2 ─────┤
                                                                      v
                       HIR8 clean full rehearsal / restore / no-op / closeout
                                    └─> governing plans recertify gates
```

HIR2 and HIR3 change authoritative derived output. Land them before any new manifest approval,
Bundle A export or operation preparation. HIR7 must land before the release/object-race recovery
exercise; an evidence schema claiming safe object recovery against the old release code proves
nothing.

## 6. HIR0 — Baseline, red tests and change control

> **Complete 2026-08-13.** Evidence:
> [HIR0 baseline](../reports/historic-import-hir0-baseline-2026-08-13.md), recorded against
> `7b1fd7ff66aefa31304e56d0cece760df32a306c`. Eight red tests exist and fail for their findings'
> reasons (`--group=hir-red`); the release-candidate checks pass; the one-shot deletion-trigger
> structural test is red for `EnrichHistoricScripturePassagesCommand`, which HIR3 owns. Production
> remains NO-GO.

**Purpose:** prevent fixes from silently inheriting stale hashes, evidence or production authority.

**Who benefits:** maintainers reviewing a large safety change. **Observable improvement:** every
package starts with a reproducing failure and names the artifacts/gates it invalidates.

### Steps

1. Reconfirm the review's file/line inventory on the implementation branch. Record moved code, not
   stale line numbers, in each PR description.
2. Keep `HISTORIC_IMPORT_PRODUCTION_APPROVAL` unset everywhere except a separately approved window.
   Add a release-candidate check that lists no usable production import/release authorisation.
3. Record HIR-D1–HIR-D5. HIR-D1 is a read-only capability spike against scratch keys only; it must never use a
   real sermon key or delete a pre-existing object.
4. Write the first failing regression test for each package before changing production code and
   prove it fails for the finding's reason. Retain every test after green.
5. Capture the current affected artifacts as superseded evidence: parser/cache version, OoS plan
   hash, Bundle A hashes, operation/approval IDs if any, custody report version, recovery report
   version and operational-closeout report version. Do not delete them.
6. Add a structural test for one-shot deletion triggers if an existing repository test does not
   already enforce the `AGENTS.md` rule.
7. Resolve the do-not-invest contradiction through HIR-D5. The exception expires when HIR8 evidence is
   incorporated; it permits safety/correctness only, not refactoring or new features.

### Acceptance

- Eight non-vacuous red tests (or deterministic test groups) exist and are linked to their package.
- No production approval is usable, no source/recovery v1 artifact is promoted, and no existing
  manifest/cache is silently rewritten.
- Each later package can be reviewed without reconstructing its authority or invalidation boundary.

## 7. HIR1 — Stable production resource identity

> **Complete 2026-08-13.** `HistoricImportResourceIdentity` splits the stable anchors out of
> `HistoricImportTargetFingerprint`; the guard compares the database anchor alone and fails closed on
> a malformed, duplicated, superseded-key or unobservable configuration. Red test 4 is green and the
> suite's expected-failure count drops from nine to eight. Anchors are placeholder-configured in
> `.env.example`, `phpunit.xml` and `.env.dusk.ci`; recording the **real production database anchor**
> is an outstanding operator item, and it must be re-recorded before a window rather than discovered
> during one.
>
> **§7 item 4 below was written before HIR-D2 and contradicted it; it has been corrected in place.**

**Review finding:** `HistoricImportProductionGuard::guardsCurrentEnvironment()` compares the entire
volatile operation target fingerprint. A changed release, migration count or configuration makes a
process pointing at production look non-production.

**Who benefits:** the operator and production data owners. **Observable improvement:** a local-env
process aimed at either the production DB or production storage is refused even when every volatile
fingerprint field differs.

### Red tests

Extend `HistoricImportProductionGuardTest` first:

- production database anchor matches while release identifier differs;
- production database anchor matches after migration batch/count changes;
- production **storage** anchor matches while the database anchor does not — the guard stays silent
  and reports the match, because HIR-D2 demoted the storage anchor. This is the accepted residual
  risk and is pinned as a test so it cannot be "fixed" by accident;
- database anchor matches while the storage anchor does not — guarded;
- required anchor is malformed, duplicated across both variables, superseded
  (`production_target_fingerprint` still set) or configured over an unobservable identity;
- an absent anchor stays silent, because refusing would gate the §13.5 rehearsal on configuration
  only a production deploy supplies. Presence is asserted instead by the release-candidate baseline
  test;
- a fully configured rehearsal target matches neither anchor and remains usable;
- read-only/preflight commands retain their documented scope while every mutating call site refuses.

Each matching/unknown case must assert zero database and storage writes, not only an error string.

### Implementation

1. Introduce a single injected `HistoricImportResourceIdentity` service. It returns separately
   canonicalised database and storage identities:
   - database driver, observed stable server identity and schema-name hash;
   - storage driver, endpoint/account identity, bucket and prefix, or resolved local root/filesystem
     identity;
   - never credentials, connection URLs with secrets or raw production paths in logs/artifacts.
2. Keep `HistoricImportTargetFingerprint` as the immutable full operation binding. Refactor it to
   consume the new resource identity plus release/schema/config fields; do not duplicate identity
   implementations.
3. Replace `production_target_fingerprint` with separately configured production database and
   storage anchor hashes. During one compatibility release, read the old key only to emit a
   fail-closed configuration error; never use it to declare a target safe.
4. **Corrected by HIR-D2 (§4.2), which was decided after this section was written.** Guard logic is
   *not* OR-based: matching the production **database** anchor means production controls apply, and
   the storage anchor is recorded but never arms the guard. An OR-ed storage anchor would classify
   every developer machine as production, because `.env` resolves the public sermon disk to the
   production bucket, and would refuse the §13.5 rehearsal. The guard exposes
   `matchesProductionStorageAnchor()` so the diagnostic and HIR7's §4.2.1 refusal can see the match
   without it becoming a trigger. A full target mismatch is a refusal requiring a newly signed
   operation, never a fall-through.
5. Any environment capable of running historic mutation must have valid production anchors,
   including local rehearsal and CI. Add non-secret placeholders to `.env.example`, test config and
   Dusk config; deploy actual hashes through environment secrets/config.
6. Add a read-only diagnostic to the existing operation-prepare/status surface that prints only
   hashes and `production/rehearsal/unknown`, never raw identity material. Do not create a new
   throwaway command.
7. Ensure `RehearsalDatabaseProvisioner` uses the same identity service and still refuses to destroy
   a database matching a production anchor.

### Rollout and rollback

Deploy anchor config before activating code that requires it. The deploy smoke check verifies both
anchors are syntactically valid and the production process observes both as production. Rehearsal
must observe neither. If rollout fails, leave every mutation disabled and fix config; do not restore
the fail-open comparison. A code rollback invalidates the release-bound approval, so the runbook
continues to prohibit mutation on the old release.

### Acceptance

- Stable resource matches guard every volatile-drift test.
- The full target fingerprint still changes when release/schema/config changes and remains bound to
  operation preparation, approval, closeout and release authority.
- F46/G8 may cite the new anchor proof; no production approval is issued yet.

## 8. HIR2 — Raw parse cache with current curation binding

> **Complete 2026-08-13.** `OosArchiveParseCacheBinding` (cache contract version 1) keys the **raw
> extractor result** on input hash, parser version and received date, and `OosArchiveIdentityResolver`
> now runs on every pass — including the ones that make no model call. Pre-HIR2 resolved-only caches
> are version 0: retained, never reusable, reparsed once. `--fresh-parse` buys another model call and
> nothing else. The binding records the raw cache key, raw-result hash, per-entry authority hash,
> current plan hash and resolved-result hash, and it reaches the report as each entry's `parse_cache`.
> `OosArchiveAssertionBundle::export()` now refuses an entry last resolved under a different source
> **or curation** authority, where it previously compared the input hash alone.
>
> **Two things the red-test matrix found that the plan did not predict.**
> `InboundEmailImportService::storedItems()` dropped `section_type`, which
> `ChurchServiceAssertionNormalizer` reads when a parse becomes canonical assertions — so a parse
> restored from the inbox had always produced items without one, and the raw cache surfaced it as a
> decoded parse resolving differently from the one it was encoded from. And MySQL's JSON type
> normalises object keys by length, so a stored cache key compared with `===` against a freshly built
> one is never equal; the reuse decision compares canonical hashes instead.
>
> **§8's "added supersession" red case is not reachable and was replaced.** A same-identity pair with
> no declared lineage is refused when the plan is built, so the archive cannot be walked from "no
> supersession" to "supersession". The equivalent real re-curation — re-keying the predecessor an
> existing correction names — is covered instead.

**Review finding:** the archive cache key omits manifest-owned content scope and identity decisions,
so unchanged source bytes can reuse a parse resolved under an older curation plan.

**Who benefits:** editorial reviewers and visitors receiving the final service history. **Observable
improvement:** changing an entry from full to partial immediately changes the staged outcome without
`--fresh-parse`, while unchanged raw extraction is reused without another model call.

### Red tests

In `ImportOosArchiveCommandTest`, exercise unchanged source bytes across:

- `full` to `partial` (no canonical items may be projected);
- changed resolved service/date;
- added/changed/removed supersession;
- a different approved plan whose entry is semantically unchanged;
- old metadata with no raw-cache binding;
- `--fresh-parse` bypass;
- an extra uncorroborated plan, which must retain its own unknown scope.

Assert extractor call counts, the exact cache-binding metadata, report disposition, source evidence,
canonical item count and inbox state. The full-to-partial test must fail against `ac1468b47` by
showing the stale full scope is consumed.

### Implementation

1. Add a small `OosArchiveParseCacheBinding`/cache service outside the command. Its exact entry
   authority hash covers item key, ground-truth date, services present, content scope and reason,
   parse decision, source/synthetic identity and supersession fields using `CanonicalJson`.
2. Cache the **raw extractor result** by input hash, parser version and received date. Never cache a
   manifest-resolved result as reusable raw model output.
3. On every archive run, apply `OosArchiveIdentityResolver` to the raw result and current
   `OosArchiveEntry`, even when no model call occurs. Store/report:
   - raw cache key and raw-result hash;
   - current per-entry authority hash;
   - current whole-plan hash for operation/audit binding; and
   - resolved-result hash.
4. Treat existing resolved-only cache metadata as version 0: it is retained but ineligible. The
   first run reparses once, writes the new raw cache and never guesses how to reverse a resolved
   result back into model output.
5. `--fresh-parse` invalidates only model extraction reuse; it still applies and records the same
   current curation binding.
6. Keep `ImportOosArchiveCommand` as orchestration. Do not add unrelated helper methods or expand
   `OosArchiveEvaluator`; both remain deletion-scheduled.
7. Bump the archive cache contract/version. Do not rely on a parser-version bump alone: future
   curation changes must invalidate or re-resolve mechanically.

### Invalidation, rollout and rollback

This changes source normalisation/authority and invokes the governing plan's §13.6 loop-back to G2.
Discard no old rows, but mark old resolved caches ineligible. Re-run calibration only if raw parser
behaviour changes; always reset/re-run clean full staging and regenerate affected reports. Recreate
Bundle B/source hashes and any operation plan that consumed old resolved output.

Rollback means returning to NO-GO and restoring code only. Do not reuse a resolved cache or approval
created under the new contract with old code.

### Acceptance

- Every red case passes without unnecessary extractor calls.
- The approved partial cohort remains evidence-only and exact source/plan hashes appear in reports.
- The current OoS plan may be re-approved only after the fresh authoritative staging run in HIR8.

## 9. HIR3 — Settled Scripture outcomes and reliable API pacing

> **Complete 2026-08-13.** `HistoricScripturePassageRequirements::keyFor()` is now an exact exclusive
> union, `scripture_passage_outcome` is a **required** normal-output field (contract version 5), and
> the enrichment delay is validated in `0..60000` and applied through a fakeable `Sleep` in a
> `finally`. Red tests 6 and 8 are green, and
> `HistoricImportReleaseCandidateBaselineTest::every_historic_import_one_shot_declares_its_deletion_trigger`
> closes with the trigger added to `EnrichHistoricScripturePassagesCommand`.
>
> **Two adjacent defects surfaced while making the contract executable and were fixed with it.**
> `HistoricProcessingMetadataSerializer` allow-lists the portable `historic_import` block and did not
> carry `scripture_passage_outcomes`, so an export destroyed the curator's settlement outright —
> invisible before HIR3 because the destination read the omission as approval. And
> `HistoricProcessingResultInventory` read the outcomes from the raw model metadata while the
> destination stores the serialized view, so the two could disagree; it now reads the serialized
> block, which is what the importing side will hold.

**Review findings:** a null/missing passage with a null/missing outcome passes as approved absence,
and the enrichment delay runs only after successful lookups.

**Who benefits:** visitors relying on Scripture links and the operator running the pre-window API
pass. **Observable improvement:** malformed Bundle A fails before writes, and every attempted API
request is separated by the configured delay regardless of success/not-found/exception.

### Red tests

1. Extend `HistoricScripturePassageRequirementsTest` with missing outcome key, explicit null,
   non-array outcome, unknown status and unapproved reason. Keep linked and each accepted absence
   reason as positive cases.
2. Extend Bundle A contract/preflight and graph-persister tests so the malformed publication refuses
   with zero rows/assets written before a service transaction begins.
3. In `EnrichHistoricScripturePassagesCommandTest`, use Laravel's fakeable `Sleep` boundary to cover
   success, not-found, exception, budget-exhausted/no-call, zero delay, negative delay and an
   excessive delay.

### Implementation

1. Make `HistoricScripturePassageRequirements::keyFor()` an exact exclusive union:
   - linked passage: nonblank `bible_id` + `normalized_reference`, outcome status `linked`, no
     absence reason; or
   - absent passage: outcome status `approved_absent` + one accepted reason.
2. Mark `scripture_passage_outcome` required in `HistoricNormalOutputContract`; the field value is
   never nullable even though `scripture_passage` is.
3. Use this one canonical read in bundle validation, preflight and persister. Do not add a permissive
   compatibility path.
4. Validate `--delay` as an integer in an explicitly bounded range (recommended `0..60_000` ms).
   After every actual `ensurePassage()` attempt, call `Sleep::for($delayMs)->milliseconds()` in a
   `finally` path. Do not sleep when the budget check prevented the call or after the final item if
   the implementation can determine it without complicating error paths.
5. Add the required deletion trigger to `EnrichHistoricScripturePassagesCommand`: delete after the
   exact production import and accepted rollback/retention window prove no further Bundle A
   enrichment is required.

### Invalidation, rollout and acceptance

The normal output contract changed, so §13.6 returns affected media output to G1. Regenerate Bundle A
and every bundle/plan/approval hash derived from it. Do not patch missing outcomes at apply time;
curate or re-export them at the source.

Acceptance requires all exact-union tests, zero-write preflight proof, fake Sleep assertions and a
read-only run against the final Bundle A reporting zero missing/unsettled identities after the
operator completes enrichment.

## 10. HIR4 — Observed source custody version 2

> **Complete 2026-08-13.** `HistoricSourceFilesystemInspector` with Darwin and Linux implementations
> (HIR-D4) is the only place custody facts are observed; an unknown platform fails closed at the
> container binding. Custody and acquisition artifacts are **version 2**; version 1 stays readable and
> cannot satisfy the repaired gate.
>
> **Failure domain, not path.** `HistoricSourceRootObservation::failureDomain()` is
> `sha256(mount source | device)`, so two roots with different canonical paths and different declared
> `storage_identity` strings are one copy if they sit on one mount. Linux reads
> `/proc/self/mountinfo` rather than `mount` output, because it is the only view that distinguishes a
> bind mount of an already-mounted filesystem from a genuinely separate store — which is the
> distinction "two independent copies" turns on.
>
> **Protection is decided by the write probe, not the mount option.** A read-only mount is the
> strongest form but not the only valid one, and an options string can say `ro` over a filesystem
> exported writable underneath. The mount option is recorded as corroborating context.
>
> **Two hashes, as §10 item 6 asks.** The physical inventory hash preserves actual types, links,
> xattrs and modes; the logical byte-set hash resolves an approved symlink through to its bytes. That
> is what lets an evidence link and its materialised working file prove equal content without
> pretending the two objects are equal — and the acceptance case now asserts their physical
> inventories differ.
>
> **One fact this host cannot expose, handled explicitly rather than assumed.** Neither `getfattr` nor
> the PHP xattr extension is present, so extended attributes are unreadable here. The inspector says
> so through `supportsExtendedAttributes()`: a custody artifact claiming **no** attributes verifies
> normally, and one claiming an attribute is **refused**, because the claim would otherwise reach the
> report unexamined. That is the substitution HIR4 exists to stop, and it is not an "assume
> protected" flag.
>
> **The superseded acceptance fixture is rebuilt, not deleted.** It now materialises the working
> copy's link for real and injects the two facts this container genuinely cannot produce — a second
> failure domain, and a directory unwritable to root. The red test keeps the real inspector, because
> its whole point is that the actual disk refuses.
>
> **Operator work remaining (HIR8's):** the real read-only physical-source observation on the
> acquisition host, and two genuinely independent protected copies verified there rather than through
> an injected observation.

**Review finding:** two writable sibling folders on one disk, with unchanged symlinks and claimed
xattrs, pass as independent protected copies.

**Who benefits:** the archive custodian and anyone recovering the corpus after media failure.
**Observable improvement:** same-device/writable/unmaterialised copies are rejected and the real
accepted source/evidence/working mounts produce a signed report of observed facts.

### Red tests

- evidence and working roots are different paths on the same device/failure domain;
- evidence root is writable or the read-only/write-probe facts are unobservable;
- a claimed xattr differs from the observed xattr or cannot be read;
- `materialize_in_working_copy` remains a symlink;
- absolute, escaping, cyclic and externally targeted links;
- different case/Unicode paths and hard-link aliases;
- observed inventory changes after custody signing;
- unsupported OS/filesystem/mount output;
- valid evidence symlink plus byte-identical materialised working file.

Unit tests use an injected observation fake; integration tests use real temporary filesystem
objects. The final support proof is operator-run on the actual acquisition host and stores.

### Implementation

1. Introduce a `HistoricSourceFilesystemInspector` contract and immutable observation DTO. Provide
   only the HIR-D4-approved Darwin and Linux implementations using existing PHP/Laravel process APIs—no
   package change.
2. Observe for each root: canonical path, filesystem device, mount point/source identity, filesystem
   type/options, read-only status, safe write-probe result, modes, inode/link counts, actual xattrs,
   symlink target and containment, size and streamed SHA-256.
3. Define failure-domain identity separately from path identity. Evidence and working copies must
   differ in failure domain, not merely root path or caller-supplied `storage_identity`.
4. Treat signed custody fields as expected authority and compare every field with observation. An
   expected claim that cannot be observed fails; it is never copied into the report as if observed.
5. Enforce dispositions per role:
   - evidence may preserve a documented link with its exact target record;
   - working `materialize_in_working_copy` must be a regular file inside the working copy with the
     target bytes;
   - excluded/unsupported/sidecar/traverse dispositions must match the allowed observed type.
6. Produce two hashes:
   - physical inventory hash, preserving actual types/links/xattrs/modes for each copy; and
   - logical byte-set hash, allowing an approved evidence symlink and its working materialisation to
     prove equivalent content without pretending their physical inventories are equal.
7. Emit custody/acquisition version 2 with inspector/platform version and observed identities. Keep
   v1 artifacts immutable but make G5/G7 acceptance require v2.
8. Re-observe the copy immediately before any later manifest/inventory consumer opens it; bind that
   observation hash into the historic operation.

### Rollout, rollback and acceptance

No schema migration is expected. Deploy v2 verification before connecting the drive for acceptance.
If the host cannot expose a required fact, stop and revise HIR-D4; do not add an “assume protected” flag.
Rollback preserves v2 artifacts but production remains NO-GO because old code cannot consume them.

Acceptance requires the full red matrix, a real read-only physical-source observation, two genuinely
independent protected copies, complete path accounting and a repeated observation with identical
hashes.

## 11. HIR5 — Authenticated, artifact-backed recovery evidence version 2

**Review finding:** placeholder digests and booleans in an unsigned JSON document satisfy the
mandatory recovery gate; the named backups and exercises are never opened.

**Who benefits:** the incident commander, verifier and congregation during recovery. **Observable
improvement:** forged/tampered/missing/same-copy evidence is rejected, while every accepted digest
can be reproduced from a retained artifact and disposable restore.

### Red tests

- no signature, unknown key ID, wrong key, tampered body and replay for another operation/target;
- missing artifact mapping, size/hash mismatch and changed bytes after initial read;
- on-host/off-host entries resolving to the same artifact or failure domain;
- invalid/same-as-production disposable restore target;
- reported row-manifest mismatch, RPO/RTO overrun and failed exercise;
- release/object-race evidence produced against a release implementation other than HIR7;
- v1 artifact presented to the repaired closeout gate.

### Implementation

1. Add recovery evidence schema version 2 with exact operation ID, target fingerprint, stable
   resource identities, release identifier, accepted RPO/RTO, verifier/key ID and signature.
2. Each backup, row manifest, object exercise, preserved artifact and rollback exercise carries a
   logical artifact ID, storage/failure-domain identity, byte size and SHA-256. Booleans describe
   results but never replace the artifact.
3. Extend `historic-import:verify-recovery` with repeatable exact `artifact-id=verification-path`
   mappings. A resolver streams each supplied artifact, verifies size/hash, rejects symlinks/unsafe
   paths and ensures every declared artifact is supplied exactly once. Paths are observation inputs,
   not persisted portable authority.
4. Authenticate canonical JSON with the HIR-D3 recovery-only key and required key ID. The command
   verifies before reading evidence paths or writing a retained artifact.
5. Reuse the observed storage-identity boundary from HIR4 where applicable. Prove on/off-host backup
   failure domains are distinct and each disposable restore identity is neither production nor the
   other restore.
6. Recompute table/row manifests from both restored databases through one read-only implementation;
   compare exact membership, not caller-supplied equal strings.
7. The object-recovery exercise must run the HIR7 implementation and retain its release-attempt,
   object-receipt and foreign-writer artifacts. Self-attested
   `foreign_before_cleanup_preserved=true` is removed.
8. Store the accepted result under a new immutable artifact key such as `recovery-rehearsal-v2`.
   Update exact closeout to require v2; never overwrite the v1 artifact.

### Rollout, rollback and acceptance

Provision verification config before enabling the command. Keep the signer outside the application
workflow and do not log key material. If config/signature/artifact resolution fails, write nothing.
A rollback leaves v2 retained but ineligible and production remains NO-GO.

Acceptance requires all adversarial tests plus operator-run on-host and off-host restores,
recomputed equal row manifests, the HIR7 concurrency/object exercise and measured RPO/RTO within FR-D6
of the final-readiness plan.

## 12. HIR6 — Deferred inbound completion state machine

> **Complete 2026-08-13.** The state contract below is implemented as written: one additive migration
> adds `dispatch_token`, `dispatch_claimed_at`, `lease_expires_at`, `last_failed_at`, bounded
> `last_error` and `failure_count` plus the lease index; the drain claims one row per short
> transaction and dispatches outside it; every state move after the claim is conditional on still
> owning the token, so a synchronous worker that finishes first is not regressed to `dispatched`.
> `assertDeferredInboundEmailReconciled()` now admits only `processed` with `processed_at`, and names
> the outstanding states in its refusal. `import:ingress drain` is the operator's idempotent retry,
> and `status`/`drain` both report exact per-state counts and the oldest outstanding lease.
>
> Operational closeout evidence is **version 2** under a new artifact key
> `operational-closeout-readiness-v2`: the deferred-inbound block carries exact per-state counts and
> a digest over the processed rows, both compared against the outbox rather than taken on the
> verifier's word, and the gate itself owns the completion rule so the closeout cannot drift into a
> second definition of "finished". Version 1 documents are retained and cannot satisfy it.
>
> **The claim lease is derived from `ProcessInboundOosEmail::UniqueForSeconds`, not chosen
> separately** (plan item 5), and a test pins that it is strictly longer. A lease that expired first
> would let a drain reclaim a row whose job is still queued, dispatch a second one, and have
> `ShouldBeUnique` drop it — leaving a claim with no job behind it.
>
> **The three superseded cases were rebuilt, not deleted.** They now run the queued job the way a
> worker would; a new `runQueuedInboundJobs()` helper does it, and the class binds a local extractor
> so the job's parse never reaches the network.

**Review finding:** `dispatched` is accepted as reconciled even though the job may still be queued,
running or later fail permanently.

**Who benefits:** church administrators sending orders of service during the window. **Observable
improvement:** exact closeout remains blocked until each deferred email has a successful durable
outcome, and a crash between claim/dispatch/processing is recoverable without duplicate import.

### State contract

```text
pending ──claim──> dispatching ──dispatch accepted──> dispatched ──job success──> processed
   ^                    │                    │                │
   └── dispatch fail ───┘                    └── job fail ───┘
   └──────────── expired claim/confirmed lost job reconciliation ───────────────┘
```

Only `processed` with non-null `processed_at` is terminal. `dispatched` means queue handoff only.

### Red tests

- queued under `Queue::fake()` but not executed blocks operational evidence and exact closeout;
- successful execution admits closeout;
- retryable and exhausted job failure block closeout and retain failure context;
- synchronous/fast dispatcher processes before the caller's post-dispatch update;
- two drainers claim the same row concurrently;
- crash after claim, crash after dispatch and stale lease recovery;
- duplicate webhook delivery, repeated drain and sequential import windows;
- an ordinary pending/review email remains untouched.

### Additive schema

Create a new migration adding nullable `dispatch_token`, `dispatch_claimed_at`,
`lease_expires_at`, `last_failed_at` and bounded `last_error`/failure metadata to
`import_deferred_inbound_emails`, plus the index required for
`operation_id + state + lease_expires_at + id`. Do not edit the deployed create-table migration and
do not perform data repair in the schema migration.

### Implementation

1. In a short transaction, lock one pending or explicitly stale row, assign a UUID token/lease,
   increment attempts and move it to `dispatching`. Commit before queue dispatch.
2. Dispatch the job outside the transaction. On success, conditionally move
   `dispatching -> dispatched` **only where the token still matches**. If a sync worker already wrote
   `processed`, the conditional update affects zero rows and must not regress state.
3. On dispatch exception, conditionally return the owned claim to `pending`, record bounded failure
   context and rethrow so the operator sees a failed drain.
4. The job locks/reloads the outbox row, exits only when already processed, and marks `processed`
   after parse/import reaches a successful or deliberately held normal disposition. Its `failed()`
   path returns the owned non-terminal row to `pending` with durable error context.
5. Reconcile `ShouldBeUnique` with the database lease. Preserve normal inbound-email idempotency;
   choose a deferred-job lock/lease duration that cannot expire before its queue/job timeout. Prove
   the chosen interface in tests rather than relying on queue-fake behaviour.
6. Add an idempotent `drain` action to `import:ingress` so a released window can retry its own pending
   rows. `status` reports exact per-state counts and oldest lease; it never sweeps ordinary email.
7. Make `assertDeferredInboundEmailReconciled()` and operational closeout require every row
   `processed` with `processed_at`. Operational evidence version 2 carries exact state counts and a
   digest/membership of the processed rows, not caller-asserted pending/failed zeros.
8. Store operational closeout under a new immutable artifact key/version. V1 is retained but cannot
   satisfy the repaired exact closeout.

### Rollout and rollback

Deploy the nullable schema first, then compatible writers/readers, then activate strict claims and
closeout. Before activation, run a read-only audit of existing `dispatched` rows. The operator may
requeue only after confirming the original job is absent; no DML migration guesses their state.

Rollback disables ingress release/drain and leaves rows/artifacts intact for forward repair. It must
not mark queued work processed or reopen exact closeout.

### Acceptance

- The full state/concurrency matrix passes under database transactions and both fake and synchronous
  dispatch boundaries.
- Closeout is impossible while any row is pending, dispatching or merely dispatched.
- A real rehearsal email received during the freeze is parsed/imported or deliberately held, marked
  processed and included in exact operational evidence before closeout.

## 13. HIR7 — Durable release ownership and concurrency-safe object I/O

> **Complete 2026-08-13.** `historic_import_release_attempts` and
> `historic_import_release_assets` carry the ledger; the uniqueness that matters is on
> `destination_identity` (`sha256(disk|path)`) and is **global**, so one public destination has one
> owner whichever operation claims it. A destination path is longer than an InnoDB key allows, and a
> truncated prefix index would let two different long paths collide into one claim — hence the hash.
>
> `HistoricReleaseObjectStore` is the only way a release touches a destination.
> `FilesystemHistoricReleaseObjectStore` creates through `fopen($path, 'x')` locally and through the
> **raw** `S3Client` with `IfNoneMatch: '*'` on Spaces, never the Storage facade, because Flysystem
> drops both conditional headers. **Both implementations return false from
> `supportsExactVersionDelete()` and throw from `deleteExactVersion()`** — the local one refuses for
> the same answer rather than a different one, so a fake cannot certify a capability production
> lacks.
>
> Compensation therefore never deletes. Objects a failed attempt created are retained, recorded
> `orphaned`, and the attempt is left `orphaned` so the batch cannot be retried over its own
> leftovers until a human reconciles it. A pre-existing identical object is `preexisting_verified`
> and never cleanup-owned; different bytes fail without overwriting.
>
> **Plan §4.2.1 is carried by `HistoricReleaseDestinationGuard`**, asked once before any claim and
> again inside the object store. Outside production it refuses to write to a destination matching the
> recorded production storage anchor unless `HISTORIC_IMPORT_ALLOW_NON_PRODUCTION_RELEASE_DESTINATION`
> is set — a separately named override, so nothing that authorises the rest of the operation can
> switch it off as a side effect. An absent or malformed anchor refuses nothing, for the same reason
> HIR1 stays silent on an absent database anchor.
>
> **Membership resolution had to split in two.** "Retry after a completed release is an exact no-op"
> and "every named record must still be quarantined" cannot both be checked before the attempt is
> resolved, because a completed batch names published records by then. Membership now resolves against
> the operation, the attempt decides which situation this is, and the quarantine check runs after.
>
> The concurrency matrix uses `PausingHistoricReleaseObjectStore`: the real store with a competing
> writer run inside one of its windows, so a case still exercises the genuine conditional create.
>
> **Not yet done, and HIR8's:** the operator-run Spaces scratch capability check, and HIR5's real
> object-recovery exercise against this implementation.

**Review finding:** two release processes can both write a final path; the loser can then delete the
winner's successfully published asset during compensation.

**Who benefits:** visitors playing released sermons and the rollback owner. **Observable improvement:**
two deliberately interleaved releasers leave one completed release and every advertised asset
present; no failure path deletes a foreign or winner-owned object.

### FR-D6 is reopened in part by this package

Final-readiness **FR-D6** (decided 2026-08-11) records that F45's object-rollback half is "already
satisfied", because `HistoricProcessingResultAssetTransfer::copyToDestinations()` confines every
write to `historic-import/{operation_id}/` and compensates only paths it created — and concludes
that "no bucket versioning is required".

**That conclusion is sound for the apply-step writer and does not reach the release writer.**
`HistoricSermonPublicationService` is a second writer: it copies out of quarantine to the **final
public path**, which is by definition not an operation-owned key, and its compensation deletes by
path. FR-D6's reasoning was never applied to it.

Consequences to carry, without reopening anything FR-D6 actually settled:

- FR-D6's RPO/RTO, backup and restore decisions stand. Do not reopen them.
- FR-D6's **"no bucket versioning is required"** clause is now scoped to the apply step only. HIR-D1
  may conclude that the release step needs object versioning or an equivalent receipt, which would
  extend — not contradict — the recorded decision.
- If HIR-D1 finds Spaces cannot return an ownership receipt usable for exact delete, the fallback is
  retained `orphaned` ledger rows and operator reconciliation (below), **not** a revived path
  delete and not a silent re-decision of FR-D6.
- Record the outcome in the final-readiness plan's FR-D6 entry when HIR-D1 is answered, so the two
  writers are visibly accounted for.

### Additive release ledger

Add new tables rather than overloading the import-transfer ledger:

1. `historic_import_release_attempts`: immutable attempt UUID, operation FK, signed-authorisation
   hash, exact batch/membership hash, state, lease token/expiry, failure summary and timestamps.
2. `historic_import_release_assets`: attempt FK, record type/id, source disk/path, destination
   disk/path, size/hash, state, create result (`preexisting` or `created`), provider receipt/version/
   ETag where authoritative, and verified/published/compensated timestamps.
3. A **global** unique index on destination disk/path, not operation + path, and exact uniqueness for
   the signed batch/attempt identity. Add query indexes for attempt state/lease and record membership.

Use one focused additive migration (or two ordered migrations if index limits demand it); do not
modify deployed migrations. Model defaults/casts and schema defaults must agree.

### Object-store boundary

Introduce an injected `HistoricReleaseObjectStore` with explicit operations:

- inspect final object and return size/hash plus provider receipt when available;
- create final object only if absent;
- verify the exact receipt/bytes created or observed; and
- delete the exact created version **only where HIR-D1 proves that operation exists**.

Provide local and Spaces implementations using already installed filesystem/provider capabilities.
Do not emulate create-if-absent with `exists()` followed by `writeStream()`. If Spaces cannot return
an ownership receipt usable for exact delete, failed final objects become retained `orphaned` ledger
rows for operator reconciliation; automatic path deletion is prohibited.

### Red concurrency/fault tests

Use a deterministic fake object-store boundary that can pause at claim, create, verify and DB commit:

- two releasers for the same exact batch;
- loser resumes after winner commits;
- foreign create between inspection and conditional create (identical and different bytes);
- crash after claim, after create, after verification and before DB commit;
- expired lease claim/reconciliation and live lease refusal;
- cleanup failure and unsupported exact-delete capability;
- existing identical/different final object;
- retry after completed release is an exact no-op;
- global destination uniqueness across two operations;
- stable lock order across sermon/song-video membership to avoid deadlock.

Every case asserts database publication state, disk selection, ledger ownership, journal sequence and
the continued existence/hash of all advertised assets.

### Implementation

1. Verify the signed release membership, then in one short transaction lock operation/records in a
   stable order and create or resolve the exact release attempt plus all destination claims. Commit
   before opening a source stream.
2. A second process with the same batch either observes completed exact no-op, refuses a live lease,
   or takes an expired lease through an explicit reconciliation path. It never creates a second
   owner.
3. Stream each source through conditional create, retain the provider receipt and verify size/hash.
   A pre-existing identical object is recorded `preexisting_verified` and is never cleanup-owned.
   Different bytes fail without overwrite.
4. After all assets verify, enter a short transaction, re-lock exact membership/attempt, recheck
   quarantine and disk bindings, update record paths/disk/publication state, mark assets published,
   append journal events and complete the attempt.
5. On failure, mark the attempt/asset state durably before any compensation. Compensation is
   receipt/version-based and conditional on the attempt still owning an unpublished created object.
   A completed/published/preexisting/foreign object is never deleted.
6. If exact delete is unavailable or ownership changed, retain the object, record `orphaned`, block
   release completion/acceptance and require the read-only reconciliation procedure. Never trade a
   possible orphan for deletion of a winner.
7. Update recovery/operational artifacts to carry release-attempt and asset-ledger digests. The
   signed release authority remains bound to exact operation, target, release and membership.
8. Add a G9/WP10 deletion trigger to new release-only services/schema: remove after the accepted
   public release/rollback observation window and artifact-retention decision, using a later
   expand/contract migration.

### Rollout and rollback

Deploy schema and code with release disabled. Run local/Spaces scratch capability checks and the
full concurrency suite. Enable only for the production-shaped rehearsal release exercise.

Rollback means disable release, invalidate the release-identifier-bound authority and preserve the
ledger/objects. Never roll back to the old release command while an authorisation is usable. Resume
or reconcile forward from durable state; do not bulk-delete destinations.

### Acceptance

- All deterministic races/faults pass and a second exact release is a no-op.
- The production object-store capability proof selects exact-version cleanup or explicit no-delete
  orphan handling; there is no unchecked path delete.
- HIR5's real object-recovery exercise proves foreign writes survive and every released record still
  resolves to verified bytes.

## 14. HIR8 — Rebuild authority and repeat the production-shaped rehearsal

**Purpose:** prove the fixes together on the exact release candidate and return evidence to the
existing gate owners. Green unit tests alone do not change NO-GO.

**Who benefits:** the final approver and operator. **Observable improvement:** one command-exact
rehearsal produces a complete, independently checkable evidence ledger with no stale cache, v1
custody/recovery claim, unfinished email or unowned object.

### Invalidation ledger

Before rerunning, record each invalidation explicitly:

| Change | Minimum loop-back |
|---|---|
| HIR1 resource identity/full target composition | Prepare a new operation and approval on the exact release; G8 config proof |
| HIR2 raw/resolved OoS cache contract | Clean full archive staging; G2 source/projection evidence and affected Bundle B |
| HIR3 normal output/Scripture outcome | G1 output contract; affected media re-export and Bundle A; G4 preflight |
| HIR4 source observation/custody v2 | Source acquisition acceptance and all source/inventory hashes |
| HIR5 recovery v2 | G7/G8 recovery acceptance; old v1 ineligible |
| HIR6 operational closeout v2 | Ingress/deferred exercise and exact closeout evidence |
| HIR7 release object ownership | Release dry run/concurrency/rollback exercise; signed release authority regenerated |

### Rehearsal order

1. Deploy the exact release candidate with production anchors configured and all production approval
   variables unset. Prove production observes its anchors and rehearsal observes neither.
2. On the approved acquisition host, create source custody v2 from the read-only physical source and
   two observed independent protected copies. Repeat observation before consumption.
3. Re-run archive parsing/staging from a clean database under HIR2. Reconcile full/partial/extra/
   supersession outcomes and produce new exact reports; do not reuse archive-v11 result hashes.
4. Re-export affected historic media under the HIR3 output contract. Run Scripture enrichment before
   the window, settle every absence explicitly and prove destination preflight has zero missing keys.
5. Regenerate Bundle A/B, source/result manifests, target/runtime fingerprints, plan hashes and the
   immutable operation. Independently inspect every new hash before approval.
6. Run the full different-database/different-PK apply, exact audit and complete second no-op with no
   ad-hoc subset. Exercise interruption/resume at each changed HIR6/HIR7 boundary.
7. During the ingress freeze, submit a real signed test OoS webhook. Reopen/drain and wait until its
   operation-scoped row is actually processed; prove closeout fails while it is only dispatched.
8. Restore both database backups onto distinct disposable targets, recompute row manifests and run
   every recovery exercise. Verify recovery v2 only from mapped retained artifacts.
9. Run two interleaved release attempts against scratch/rehearsal object keys, then the exact signed
   rehearsal release and rollback/no-op exercise. Retain release ledger and object receipts.
10. Run public/admin/private/quarantine asset smoke, exact closeout, rotating-log review, queue/job
    failure review and zero-notification proof required by the governing plans.
11. Repeat full rollback/re-apply within accepted RPO/RTO and production-window reserves. Any new
    durable-output defect loops back according to readiness-remediation §13.6.

### Evidence and plan reconciliation

For each completed package, update the relevant finding/gate in the two governing historic plans
with commit, test and rehearsal evidence. Do not copy this whole sequence into them. Update the sole
production runbook only after the exact rehearsal sequence is stable, then execute it verbatim in a
second rehearsal as final-readiness already requires.

### Acceptance

- HIR1–HIR7 acceptance is green on one exact release candidate.
- Fresh source, cache, Bundle A/B, operation, approval, recovery and closeout hashes form one linked
  ledger; no superseded hash is presented as current.
- G1–G8 are re-evaluated by their existing owners. A gate remains open unless all of its other
  pre-existing requirements are also met.
- Production remains NO-GO until the final-readiness checklist—not this addendum—changes verdict.

## 15. Per-PR quality gates

Follow `AGENTS.md` and prove bug fixes red-to-green. Run through Sail:

1. focused PHPUnit tests for the package, including failure, edge and concurrency cases;
2. migration tests against both a fresh schema and an upgraded pre-package schema for HIR6/HIR7;
3. `vendor/bin/sail composer phpstan` — zero errors;
4. `vendor/bin/sail bin pint --dirty`;
5. `vendor/bin/sail artisan test --parallel --compact 2>&1 | tee /tmp/test-output.txt` — inspect
   failures from the captured output rather than rerunning to discover names;
6. no Dusk requirement unless implementation unexpectedly changes browser behaviour; if it does,
   add Dusk behaviour coverage and return to this plan before broadening scope.

For storage, mount, restore and object-store behaviour that PHPUnit cannot prove, retain an
operator-run artifact with command, release/config identity, start/end time, exit status and digest.
Never replace a programmatic test with a one-off verification script.

## 16. Definition of done

- [x] HIR-D1–HIR-D5 are recorded; production mutation/release remains disabled until final approval.
      *(HIR0, 2026-08-13 — §4.1–4.3 and the release-candidate checks in
      `HistoricImportReleaseCandidateBaselineTest`.)*
- [x] HIR1 stable database/storage anchors guard a mislabelled process under release/schema/config
      drift and bind the full target fingerprint separately. *(2026-08-13. Per HIR-D2 only the
      database anchor arms the guard; the storage anchor is recorded and reported. Outstanding
      operator item: record the real production database anchor.)*
- [x] HIR2 always reapplies current curation to raw cached extraction; full-to-partial never projects
      canonical items and supersession/identity changes cannot reuse stale resolution.
      *(2026-08-13. Cache contract version 1; the assertion-bundle export now refuses a parse resolved
      under a superseded authority. Clean full staging and Bundle B regeneration remain HIR8's.)*
- [x] HIR3 rejects missing/null Scripture outcomes before writes and delays every actual API attempt.
      *(2026-08-13. Normal output contract version 5; the export now carries the settlement it used
      to drop.)*
- [x] HIR4 v2 observes two independent protected copies, real xattrs/links/mount facts and exact
      disposition materialisation. *(2026-08-13, in code and under test. The proof **on the approved
      acquisition host** — real read-only physical source, two real protected copies, real xattrs —
      is operator work and remains HIR8's.)*
- [ ] HIR5 v2 authenticates the independent verifier and recomputes every required artifact/restore
      digest; v1 cannot satisfy closeout.
- [x] HIR6 closeout requires all operation-scoped deferred email to be processed, with crash-safe
      claim/retry and no ordinary inbox sweep. *(2026-08-13. Operational closeout evidence version 2
      under artifact key `operational-closeout-readiness-v2`. The real rehearsal email received
      during a freeze remains HIR8's.)*
- [x] HIR7 has one durable owner per public destination, deterministic concurrency/fault coverage and
      no path-only cleanup. *(2026-08-13. Compensation is retain-and-record only, because neither
      store can delete an exact version. The production capability proof and the release exercise
      remain HIR8's.)*
- [ ] Fresh full staging, different-PK apply, exact audit, complete no-op, restore, rollback, deferred
      webhook and release-race exercises pass on the same release candidate.
- [ ] Every invalidated manifest, bundle, report, fingerprint, plan and approval is regenerated and
      linked; superseded artifacts remain labelled and ineligible.
- [ ] Governing historic plans carry the closure/rehearsal evidence and retain sole gate/go-no-go
      ownership; this addendum is moved to `docs/archived-plans/` when no executable work remains.
- [ ] G9/WP10 deletes the repaired one-shots, temporary services, compatibility readers and additive
      historic-only schema only after their production/retention gates permit a later contract
      release.
