# HIR0 baseline — historic import safety remediation

**Recorded 2026-08-13 against `7b1fd7ff66aefa31304e56d0cece760df32a306c`.**

This is the change-control baseline required by
[HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md](../plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md)
§6. It exists so that HIR1–HIR8 cannot silently inherit stale hashes, evidence or production
authority, and so each later package can be reviewed without reconstructing its invalidation
boundary.

**Production remains NO-GO.** Nothing here changes a verdict; the final-readiness plan still owns
that.

## 1. Inventory reconfirmation (step 1)

`git diff ac1468b47..HEAD -- app/ database/ config/ tests/` is **empty**. The two commits since the
reviewed range (`de2f40618`, `7b1fd7ff6`) are documentation only, so the review's file/line inventory
holds verbatim at HEAD and no PR needs to restate moved code.

Two cited ranges are narrower than the method they name; the wider range is the one to review:

| Review citation | Actual current range |
|---|---|
| `HistoricSourceAcquisitionVerifier.php:212-287` | `validateCustody()` is `213-358` |
| `EnrichHistoricScripturePassagesCommand.php:101-149` | `fetch()` is `101-175`; the unpaced loop is `114-149` |

Everything else — `HistoricSermonPublicationService` `copyVerified()`/compensation,
`HistoricImportProductionGuard::guardsCurrentEnvironment()`,
`HistoricImportTargetFingerprint::identity()`, `HistoricImportRecoveryEvidence::verify()`,
`ImportOosArchiveCommand::parseResult()`/`archiveMetadata()`, `OosArchiveIdentityResolver::resolve()`,
`HistoricScripturePassageRequirements::keyFor()`, `ImportIngressGate`'s drain and reconciliation, and
`HistoricImportOperationalCloseoutEvidence::verifyDeferredInbound()` — is at the cited lines.

## 2. Red tests (step 4)

Eight non-vacuous failing tests, one per finding, all tagged `#[Group('hir-red')]`. Run them as a
set with `vendor/bin/sail artisan test --group=hir-red`.

Baseline run 2026-08-13: **8 failed, 29 assertions, 4.09s.** Each failure below is the finding's own
reason, not a setup or schema error.

| # | Sev | Package | Test | Observed failure at baseline |
|---|---|---|---|---|
| 1 | High | HIR7 | `Tests\Feature\HistoricSermonReleaseRaceTest::a_losing_release_attempt_does_not_delete_the_winners_published_asset` | `Unable to find a file or directory at path [sermons/historic/audio.mp3]`. The loser's `binding changed before commit` refusal and the winner's `published`/`public` row both assert *green* first; the failure is specifically that compensation deleted the winner's bytes. |
| 2 | High | HIR4 | `Tests\Feature\Console\VerifyHistoricSourceAcquisitionCommandTest::it_refuses_two_writable_copies_that_share_one_failure_domain` | `Unexpected status code 0`. Same-device, writable roots with an unmaterialised `materialize_in_working_copy` symlink are accepted as two protected copies. The same-device, writable and still-a-symlink facts are asserted before the refusal, so it cannot fail for a different reason. |
| 3 | High | HIR5 | `Tests\Feature\Console\VerifyHistoricImportRecoveryCommandTest::it_refuses_unsigned_evidence_whose_named_artifacts_are_never_opened` | `Unexpected status code 0`. Unsigned evidence, placeholder digests matching no artifact, one backup presented as both on-host and off-host — retained as the mandatory recovery gate's proof. |
| 4 | High | HIR1 | `Tests\Unit\Services\Import\HistoricImportProductionGuardTest::volatile_drift_does_not_disarm_the_guard_on_a_production_database` | `guardsCurrentEnvironment()` returned `false` with the production database anchor unchanged and only the release identifier and transcription service drifted. |
| 5 | High | HIR2 | `Tests\Feature\Console\ImportOosArchiveCommandTest::a_re_curation_to_partial_cannot_reuse_a_parse_resolved_as_a_full_order` | disposition `created`, expected `evidence_retained`. `assertSame(1, $extractor->calls)` passes first, proving the stale cache really was consumed rather than the entry being reparsed. |
| 6 | Med | HIR3 | `Tests\Integration\Services\HistoricMedia\HistoricScripturePassageRequirementsTest::it_refuses_an_absent_passage_with_no_outcome_at_all` | No exception raised: an omitted `scripture_passage_outcome` is read as an approved absence. Covers omitted and explicitly-null shapes. |
| 7 | Med | HIR6 | `Tests\Feature\ImportIngressGateTest::a_queued_but_unprocessed_deferred_email_does_not_count_as_reconciled` | `Failed asserting that exception of type "RuntimeException" is thrown`. A row in `dispatched` with no `processed` outcome satisfies reconciliation. |
| 8 | Low | HIR3 | `Tests\Feature\Console\EnrichHistoricScripturePassagesCommandTest::it_paces_unsuccessful_api_attempts_as_well_as_successful_ones` | Gap between not-found API calls `0.0102s`, required `0.2s`. |

Pacing (8) is measured between the client calls themselves rather than through a faked sleep
boundary, so it states observable behaviour and cannot pass merely because a particular sleep API was
adopted. HIR3 adds the full `Sleep`-faked matrix on top of it.

### Tests these deliberately contradict

Three red tests assert the refusal of a fixture an existing test asserts the *acceptance* of. Those
existing tests are superseded evidence and must be **rebuilt, not deleted**:

| Superseded test | Owner | What must change |
|---|---|---|
| `VerifyHistoricSourceAcquisitionCommandTest::it_verifies_two_signed_complete_copies_and_inventories_hidden_unsupported_and_link_paths` | HIR4 | Fixture becomes two genuinely independent protected copies with the link materialised in the working copy |
| `VerifyHistoricImportRecoveryCommandTest::it_retains_only_exact_successfully_restored_recovery_evidence` | HIR5 | Fixture becomes signed v2 evidence backed by resolvable artifacts |
| `ImportIngressGateTest::release_drains_only_its_operation_outbox_…` and `::reopening_and_drain_are_separate_retry_safe_steps` | HIR6 | Run the job so the rows reach `processed` |

## 3. Change-control checks (steps 2 and 6)

`Tests\Feature\HistoricImportReleaseCandidateBaselineTest` — three passing, one red.

**Passing (step 2, "no usable production authorisation"):**

- `no_committed_environment_carries_a_production_import_authorisation` — no uncommented assignment of
  `HISTORIC_IMPORT_PRODUCTION_APPROVAL`, `HISTORIC_IMPORT_PRODUCTION_TARGET_FINGERPRINT` or
  `HISTORIC_IMPORT_EVIDENCE_SIGNING_KEY` in any committed env file. `.env.example:193-194` carries
  both import variables commented out, which is documentation and stays.
- `the_shipped_configuration_authorises_no_production_import` — with the cutoff and private
  quarantine prerequisites deliberately satisfied so an unrelated refusal cannot mask the result,
  `approvedOperationId()` is null and both `historic:apply` and `historic-import:release-batch`
  refuse naming `HISTORIC_IMPORT_PRODUCTION_APPROVAL`.
- `no_signed_approval_or_release_authorisation_is_committed` — no tracked JSON contains a
  `crockenhill-historic-import-approval` or `crockenhill-historic-release-authorisation` document.

**Red (step 6, one-shot deletion triggers):**

- `every_historic_import_one_shot_declares_its_deletion_trigger` fails for exactly one command,
  `EnrichHistoricScripturePassagesCommand`. No repository test previously enforced `AGENTS.md`'s
  rule. The trigger itself is **HIR3 implementation step 5**, so this test is deliberately left red
  and is *additional to* the eight — do not count it as a ninth finding.

The other six `historic-import:` commands declare triggers and pass.

## 4. Decisions (step 3)

HIR-D1–HIR-D5 are recorded in the plan itself: §4 status column, §4.1 (D1, measured against the
production Spaces bucket), §4.2/§4.2.1 (D2 and its compensating control), §4.3 (D3). D4 and D5 took
their recommendations as written. HIR-D1's outcome is also mirrored into the final-readiness plan's
FR-D6 entry.

**No decision here was inferred from a test fixture**, per §4's warning. In particular the local
release fake in red test 1 proves nothing about Spaces; §4.1 measured Spaces directly and found
conditional delete is silently ignored.

## 5. Governance (step 7)

`AGENTS.md`'s do-not-invest list already carries the HIR-D5 bounded exception for
`ImportOosArchiveCommand` and `HistoricVideoImporter` — safety and correctness only, no refactoring,
features or polish, expiring when HIR8 evidence is incorporated. Red test 5 and HIR2's cache-binding
service fall inside it; the cache-binding logic goes in a small service outside the command, as the
exception requires.

## 6. Superseded artifacts (step 5)

Retained, labelled and **ineligible** to satisfy a repaired gate. Nothing here is deleted.

### 6.1 Parser and parse cache

| Item | Value |
|---|---|
| Parser/cache version | `archive-v11` (`ImportOosArchiveCommand::ParserVersion`, line 54) |
| Cache key in code | `input_hash` + `parser_version` + `received_date` |
| Cache key advertised in reports | `["input_hash", "parser_version"]` (`parse_evidence.cache_key`) |
| Cache policy | `reuse-if-input-and-parser-match` |

The report under-states its own key, and neither version of it carries any curation authority. HIR2
supersedes both the key and the reported evidence shape.

### 6.2 OoS curation plan

| Item | Value |
|---|---|
| Batch key | `oos-curated-2026-08-12` |
| Manifest hash | `474d32c44284af7d1ef35b20f5454a5feab5609dac2626e5ad7e66bfd6ed8451` |
| Plan hash | `6795f1497d54d85baac353d026544445f78a151ad0c77c254cf58ce9ba016cda` |
| Counts | raw 535, include 534, exclude 1, full 465, partial 69, superseded 10, inferred-date 9, verbatim-only 274, formatted-only 2 |
| Staging report | `storage/scratch/rehearsal-staging-archive-v11-2026-08-12.json`, generated `2026-08-12T17:15:18+00:00`, mode `import`, pipeline `multi_service`, 534 selected |
| Corpus roots | verbatim `storage/scratch/oos-verbatim`, formatted `storage/scratch/oos`, manifest `storage/scratch/oos-curation-manifest.json` |

`storage/scratch/` is gitignored, so **these artifacts are not recoverable from git**. The hashes
above are the record; keep the files.

### 6.3 Bundle and report format versions

No exported bundle, custody report, recovery report or closeout report exists on this host — so
there is no v1 *instance* to supersede, only the formats a v2 must move past.

| Artifact | Format | Version | Artifact key |
|---|---|---|---|
| Bundle A | `crockenhill-historic-processing-result` | 2 | — |
| Bundle B / OoS assertions | `crockenhill-oos-assertions` | 1 | — |
| Source custody input | `crockenhill-historic-source-custody` | 1 | — |
| Source acquisition report | `crockenhill-historic-source-acquisition` | 1 | — |
| Recovery evidence | `crockenhill-historic-import-recovery` | 1 | `recovery-rehearsal` |
| Operational closeout | `crockenhill-historic-import-operational-closeout` | 1 | `operational-closeout-readiness` |
| Import approval | `crockenhill-historic-import-approval` | 1 | — |
| Release authorisation | `crockenhill-historic-release-authorisation` | — | — |

### 6.4 Operations and approvals

**None exist.** `historic_import_operations` and `historic_import_artifacts` are both empty.

`storage/app/private/historic-import/` holds 61 operation-shaped directories with 82 empty
`recovery/`/`closeout/` subdirectories and **zero files** — skeletons left by transactional test runs
whose rows rolled back. They are residue, not evidence, and carry no operation identity.

So HIR1's "prepare a new operation and approval on the exact release" loop-back has nothing to
invalidate; it starts clean.

### 6.5 Local staging database state

Observed on the default connection, superseded by HIR2/HIR8's clean full re-stage:

| Measure | Value |
|---|---|
| `church_services` | 408 |
| `church_service_source_records` | 1 |
| `inbound_emails` | 238 (237 synthetic archive) |
| Parser versions present | `archive-v6` ×101, `archive-v7` ×67, `archive-v11` ×69, none ×1 |
| Archive content scopes | full 111, partial 25, absent 102 |
| `import_deferred_inbound_emails` | 0 |
| Quarantined sermons | 0 |
| Latest `inbound_emails.updated_at` | `2026-08-12 11:10:06` |

Two things follow, and both matter for HIR8:

1. **This database is a stratified accumulation across three parser versions, not a staging run.**
   It is exactly what §8's "treat existing resolved-only cache metadata as version 0" is for, and it
   cannot be used to certify anything.
2. **The archive-v11 report is not a record of this database.** The report is timestamped `17:15:18`
   on 2026-08-12 and the newest row here is `11:10:06` the same day, so the two must not be read as
   one ledger. HIR8's clean re-stage resolves this; until then, cite the report or the database, and
   say which.

## 7. Quality gates at the baseline

Run through Sail on 2026-08-13:

| Gate | Result |
|---|---|
| `bin pint --dirty` | clean (one file auto-fixed: `HistoricSermonReleaseRaceTest`) |
| `composer phpstan` | **0 errors** across 731 files |
| `artisan test --parallel --compact` | **6,424 tests, 81,543 assertions, 9 failures, 137 PHPUnit notices** |
| `artisan dusk` | 55 passed, 119 assertions |

**The nine failures are the nine tests HIR0 adds and nothing else** — the eight `hir-red` tests plus
the deletion-trigger structural test. Compared with the review's own run (6,412 tests / 81,475
assertions / 137 notices) the suite gained exactly the twelve tests added here and broke nothing.

This is the intended state of the branch between HIR0 and HIR7. A green suite before HIR1 lands
would mean a red test had been weakened. When triaging an unrelated failure, diff against this list
rather than assuming the suite should be clean.

## 8. What HIR0 does not claim

- No package is implemented. Every red test above stays red until its owner lands.
- Green unit tests would not change NO-GO even when they arrive; only the final-readiness checklist
  does that.
- The recovery signature reuses the application's approval key (HIR-D3), so HIR5's assurance comes
  from byte-level artifact verification. Neither this document nor HIR5 may claim verifier
  independence.
