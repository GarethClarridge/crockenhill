# Historic import commit review — 10–12 August 2026

## Scope

This review covers the 24 commits from `ada4b0483e` through `ac1468b472`, compared with base
`ceaadb103a6dd9dca70a0e835d1b155c50beb873`. The range contains 12,710 additions and 536
deletions across 174 files. The primary focus is historic source custody, rehearsal and production
guards, durable import state, quarantined publication, exact closeout, Scripture Passage handling,
and the final OoS staging fixes.

Pre-existing uncommitted documentation changes in the worktree were excluded. This document is the
only file created by the review.

## Review method

- Read every commit subject and the combined range diff, then followed production-critical paths
  into their consumers and tests.
- Prioritised corruption/loss, concurrency, target identification, evidence authenticity,
  idempotency, closeout semantics and curation authority over formatting concerns.
- Checked the implementation against `AGENTS.md`, the current historic-import plans and the
  project Laravel/PHP conventions.
- Ran focused historic-import safety tests, PHPStan and the full parallel test suite (results below).

## Findings

### 1. High — a failed concurrent release can delete assets belonging to the successful release

**Affected code:** `app/Services/Import/HistoricSermonPublicationService.php:50-88` and
`:259-297` (`866fc85e98`, extended by `7492ed8762`).

`copyVerified()` performs a check-then-write against the final public path. When the path was absent,
the path is appended to the local `$created` list after writing. On any later exception, the outer
catch unconditionally deletes every path in that list.

Two release processes can both observe the same path as absent and both write it. The first process
can then commit the rows as published. The second loses the row-state race in `commit()` and its
catch deletes the shared final path, leaving the successfully published sermon with a missing asset.
The same race can occur when a foreign writer creates identical bytes between `exists()` and
`writeStream()`; the release then claims ownership of a path it did not exclusively create.

This directly conflicts with the recovery contract's claims that foreign writes are preserved and
ownership is reverified before deletion. Existing release tests cover rollback and byte conflicts,
but not two release attempts interleaving at the final key.

**Recommendation:** claim the release attempt durably before I/O, write to operation-owned/versioned
keys, and use an atomic conditional create or object-version identity. Compensation must delete only
the exact object version created by that attempt after rechecking ownership. Add a deterministic
interleaving test in which one release commits and the other fails without removing the winner's
asset.

### 2. High — source acquisition accepts two writable folders on the same disk as independent protected copies

**Affected code:** `app/Services/Import/HistoricSourceAcquisitionVerifier.php:22-76`, `:83-155`
and `:212-287` (`39728e7545`). The current success fixture is especially revealing:
`tests/Feature/Console/VerifyHistoricSourceAcquisitionCommandTest.php:88-181`.

The only observed independence check is that the two roots have different `realpath()` values.
`storage_identity` and `protected_read_only` are signed strings/booleans supplied by the custody
JSON; they are not compared with the roots' device/mount identity or actual writeability. Likewise,
the inventory copies `xattrs` and `disposition` from that JSON instead of reading/enforcing them.

The passing test creates two sibling, mode-0755 folders under the same temporary directory, leaves
both writable, leaves `recording-link` as a symlink despite its
`materialize_in_working_copy` disposition, and labels them as different read-only stores. The
verifier accepts this as “two signed complete copies.” A single filesystem loss or later mutation
can therefore defeat both the evidence and working copy while the acquisition gate reports success.

**Recommendation:** derive and compare stable device/mount/storage identities from the actual roots;
verify the evidence copy's read-only protection with an observed mount/permission check and safe
write probe; read actual extended attributes; and enforce each disposition (including symlink
materialisation and containment). Add refusal tests for same-device roots, writable evidence,
unmaterialised/external symlinks and claimed xattrs that differ from disk.

### 3. High — recovery evidence is trusted without authentication or checking the referenced evidence

**Affected code:** `app/Services/Import/HistoricImportRecoveryEvidence.php:16-133`,
`app/Console/Commands/VerifyHistoricImportRecoveryCommand.php:28-58` and
`app/Services/Import/HistoricImportOperationCloseout.php:86-90` (`e3981b6cf3`).

The recovery verifier checks the shape of a caller-written JSON document, syntactic SHA-256 strings
and success booleans. It does not authenticate the document, resolve any referenced backup/exercise
artifact, or recompute a digest. An arbitrary `verified_by` string is sufficient. The test's valid
fixture demonstrates this by using repeated placeholder digits for every digest and duplicating the
same backup object as both the on-host and off-host restore.

Writing that claim into encrypted local storage only protects it after intake. Closeout subsequently
checks the stored artifact's format and operation/target fields, so the unauthenticated claim can
satisfy the mandatory recovery gate. This is weaker than the source, production-approval and
operational-closeout evidence added in the same range, all of which carry verified HMAC signatures.

**Recommendation:** require authenticated independent-verifier evidence, exact locators for every
backup/report/exercise artifact, and recompute/compare the referenced digests before retaining the
gate artifact. Prove the on-host and off-host backups are distinct and validate the restored target
fingerprint as an actual target identity. Add tests that reject unsigned, tampered, missing and
same-artifact “independent” evidence.

### 4. High — the production-target guard fails open when any volatile fingerprint component changes

**Affected code:** `app/Services/Import/HistoricImportProductionGuard.php:136-152` and
`app/Services/Import/HistoricImportTargetFingerprint.php:19-50` (`99633ca8b9`).

Outside an `APP_ENV=production` process, the guard protects a production target only when the entire
current target fingerprint exactly equals one configured hash. That fingerprint includes the release
identifier, migration batch/count, service-structure mode, transcription service and public cutoff,
in addition to database/storage identity.

A local/mislabelled shell can therefore point at the production database and storage but use a newer
release, one extra migration, or a different non-identity setting. The hash mismatch makes
`guardsCurrentEnvironment()` return `false`, silently disabling the protection in precisely the
misconfiguration this fallback is meant to catch. The current test covers only a byte-for-byte full
fingerprint match.

**Recommendation:** separate stable production resource identity (database server/schema and storage
account/bucket) from the per-operation configuration fingerprint. Matching any production resource
must fail closed and require approval; volatile configuration drift should be a separate refusal, not
evidence that the target is non-production. Add tests for a production DB/storage identity with a
changed release, schema count and service configuration.

### 5. High — a curation change can reuse a parse resolved under the old manifest

**Affected code:** `app/Console/Commands/ImportOosArchiveCommand.php:558-628` and `:837-856`,
plus `app/Services/Email/OosArchiveIdentityResolver.php:16-21` (`5ab5e3ea41`, with staging changes
in `ac1468b472`).

The parse cache key contains only the source byte hash, parser version and received date. The newly
introduced identity resolver also applies manifest-owned date, service identity and content scope to
the cached object, but the curation plan hash and relevant entry decisions are absent from the cache
key. `synchroniseEmail()` first overwrites the `archive` metadata with the new plan, after which
`parseResult()` can return the old stored result without running the resolver.

For example, changing an approved entry from `full` to `partial` without changing the source bytes
can leave the stored plan marked full. The new plan hash still passes the command-level gate, but the
canonical import consumes the old scope instead of retaining evidence-only partial content. Similar
staleness applies to curated identity and supersession decisions.

**Recommendation:** include the plan hash plus an exact per-entry curation/identity hash in the cache
binding, or cache only the raw parser result and always reapply `OosArchiveIdentityResolver` for the
current entry. Add a feature test that runs unchanged bytes first as full, then with a newly approved
partial manifest, without `--fresh-parse`, and proves no canonical items are projected.

### 6. Medium — an absent Scripture Passage with no outcome is silently treated as an approved absence

**Affected code:** `app/Services/HistoricMedia/HistoricScripturePassageRequirements.php:45-79`
(`fbcdc2dea5`).

When `scripture_passage` is null, `keyFor()` rejects an invalid outcome only if the outcome itself is
non-null. If both the passage and `scripture_passage_outcome` are null or missing, it returns null and
the publication passes preflight/apply. That contradicts the class contract: every absence must carry
an approved terminal reason and an unrecognised/unsettled outcome must not be written.

The tests cover an approved absence and a non-null `pending` outcome, but not the missing/null outcome
case. A partial or malformed Bundle A can therefore lose the destination's Scripture relationship
without failing the zero-write preflight.

**Recommendation:** require an `approved_absent` outcome with an accepted reason whenever the passage
is null, and add missing-key and explicit-null regression cases.

### 7. Medium — exact closeout treats queued deferred email as reconciled before processing finishes

**Affected code:** `app/Services/Import/ImportIngressGate.php:143-178`,
`app/Services/Import/HistoricImportOperationalCloseoutEvidence.php:140-155`,
`app/Services/Import/HistoricImportOperationCloseout.php:101-115` and
`app/Jobs/ProcessInboundOosEmail.php:32-90` (`58da1bd401` and `e3981b6cf3`).

Reopening ingress dispatches each durable outbox row and immediately marks it `dispatched`. Both
closeout checks explicitly accept `dispatched` as terminal, even though the job records the distinct
`processed` state only after parse/import succeeds. The operation can therefore close while an email
is still queued or running. A later permanent failure returns the row to `pending`, but by then the
historic operation may already be complete and its “pending = 0 / failed = 0” evidence is stale.

The retry-safety test codifies the gap by faking the queue, dispatching without executing the job and
then asserting reconciliation succeeds.

**Recommendation:** closeout should require `processed_at`/`state=processed` for every operation row,
and the operational evidence should be compared with exact durable counts at verification time. If
handoff-to-queue is intentionally the terminal contract, rename the gate and preserve a separate
owner/alert that cannot be retired with the historic operation.

### 8. Low — the Scripture API delay applies only after successful lookups

**Affected code:** `app/Console/Commands/EnrichHistoricScripturePassagesCommand.php:101-149`
(`fbcdc2dea5`).

The `--delay` option says it sleeps between API calls, but both exceptions and not-found outcomes
`continue` before the sleep. A run dominated by invalid/not-found references can issue calls as fast
as the loop executes, increasing the chance of throttling during the exact large pre-window pass this
command was added for.

**Recommendation:** sleep in a `finally` path after every attempted API call (but not after a
budget-check path that made no call), validate a non-negative bounded delay, and test unsuccessful
outcomes. The command is also a new one-shot without the deletion-trigger docblock required by
`AGENTS.md`.

## Repository-governance conflict

The range adds 329 lines and deletes 49 in `ImportOosArchiveCommand`, `HistoricVideoImporter` and
their tests.
Those tools are explicitly on `AGENTS.md`'s do-not-invest list because R8/R12 schedule them for
deletion after the production gates. The remainder plan also says the tools must remain until those
gates close, which explains why this work exists, but it does not remove the instruction that changes
touching them are an automatic decline.

That contradiction should be resolved explicitly before more work lands: either record a temporary,
bounded exception for the production-readiness fixes, or move durable identity/curation behaviour
out of the deletion-scheduled commands so the one-shots stay thin. As written, reviewers cannot both
follow the repository rule and approve the current historic-import programme.

## Positive observations

- Quarantined publications are suppressed at the model/read boundary and released explicitly rather
  than becoming accidentally public through controller-only filtering.
- Durable operation IDs, target/binding hashes, checkpoints, item outcomes, append-only journal
  verification and exact no-op/audit checks are a substantial improvement over process-local state.
- The operation-scoped inbound-email outbox is better than sweeping all pending emails, and its
  uniqueness constraint makes duplicate delivery during one window idempotent.
- Source/result inventories use deterministic ordering and content hashes, and many gates fail before
  writes rather than discovering incompatibility halfway through apply.
- The commits include broad happy-path, refusal, retry and rollback coverage. The findings above are
  concentrated at cross-process and trust-boundary seams that the existing tests do not simulate.

## Verification performed

- `git diff --check ceaadb103a6dd9dca70a0e835d1b155c50beb873..HEAD` — clean.
- Focused historic-import suite — 81 tests passed (372 assertions).
- `vendor/bin/sail composer phpstan` — 0 errors across 731 files.
- Full parallel PHPUnit suite — 6,412 tests passed (81,475 assertions), with 137 PHPUnit notices and
  one skipped test.

## Recommendation

Do not run the production historic import/release sequence yet. Findings 1–5 are release blockers:
they undermine asset ownership, source survivability, recovery proof, production target detection and
manifest authority respectively. Finding 7 should also be resolved before exact closeout is relied on.
After fixes, add focused adversarial tests for the stated interleavings/trust boundaries and rerun
PHPStan plus the full parallel suite.
