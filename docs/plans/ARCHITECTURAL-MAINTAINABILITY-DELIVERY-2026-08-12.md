# Architectural Maintainability Delivery Plan

> **Status (2026-08-12): approved planning document; implementation not started.** Verified against
> `ac1468b47` and the active plan set on 2026-08-12. Immediate safety packages AM1–AM5 and AM11 may
> proceed when their named maintainer decisions are recorded. Permanent processing refactors AM8–AM10
> wait until the historic production operation reaches G9 unless a final-readiness gate proves one is
> required earlier.
>
> **AM3 Delivery 1 blocks the historic production operation.** Historic final-readiness FR-D7
> accepted rotating logs as the operation record in place of Sentry, but AM3's finding is that
> `storage/logs/laravel.log` does not rotate at all. The operation's only accepted monitoring control
> is therefore unbuilt. AM3 Delivery 1 is not free-running infrastructure work and must land before
> the window opens.
>
> **Human authority required:** the maintainer must decide original-recording retention (AM-D1), normal
> rollback policy (AM-D2), the supported runtime matrix (AM-D3), and whether live Shadow mode remains useful
> (AM-D4). Production restore, rotation, rollback and startup acceptance checks are operator-run. No
> production command is authorised by this plan.
>
> **No dependency changes are authorised.** AM14d may remove direct frontend dependencies only after
> explicit approval. Installing Sentry remains separately approval-gated by
> [the Sentry plan](SENTRY-ERROR-TRACKING.md).

## Outcome

Make the application cheaper and safer to change by completing the ownership model the codebase has
already chosen:

1. every durable file has a backup, archive or expiry owner;
2. every long-running operation has one executable timeout/lock budget;
3. every processing run has one terminal-failure owner;
4. every church-service source reaches canonical items through one projector/persister path;
5. server-rendered and reactive document metadata share one contract;
6. browser code owns ephemeral upload state while Livewire owns durable processing state;
7. schema changes and rollback follow one enforced expand/contract lifecycle; and
8. temporary historic-import hooks leave the permanent application after their evidence gates pass.

This is a convergence and contraction programme, not a rewrite. A completed work package must remove
an ownership ambiguity, prove an operational guarantee, or record an evidence-based keep decision.
Moving code without changing one of those outcomes is not progress.

## Authority and boundaries

This plan is the coordination spine for the 2026-08-12 architectural review. It is self-contained for
the work it owns, but it does not duplicate executable steps already owned elsewhere.

| Concern | Sole executable owner | This plan's boundary |
|---|---|---|
| Historic source acquisition, rehearsal, production apply, exact closeout and one-shot retirement | [Historic final readiness](HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md) + [readiness remediation](HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md) | Track the required steady-state outcome; do not add import gates or delete before G9/WP10 |
| `ChurchServiceItemSyncService` evidence/decision and the five flat test-suite fold-ins | [July simplification closeout](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md) R13–R15 | Consume the R13b decision; do not remeasure or extend deletion-scheduled tests here |
| Generic exception capture, Sentry privacy/noise policy and release-tagged error events | [Sentry plan](SENTRY-ERROR-TRACKING.md) | AM3 owns log transport/rotation; AM8 owns terminal state; Sentry Delivery 2 reports only the final owner |
| Broad PHPStan/config/dependency/test-notice cleanup | [Code-quality plan](CODE-QUALITY-REMEDIATION-2026-07-19.md) | AM9 coordinates with WP2.5 but does not absorb the wider quality backlog |
| Brand tokens, shared visual components and visual baselines | [Design-system plan](DESIGN-SYSTEM-REFRESH-2026-07-20.md) | AM5 changes metadata behaviour, not visual design |
| New `/search` behaviour and SEO rules | [Site-search plan](SITE-SEARCH-2026-07-20.md) | Site search consumes AM5's shared head updater instead of creating another one |
| Production source-recording durability, queue timing, logging, generic rollback, runtime versions and startup | This plan | Historic plans may add stricter operation-specific evidence but do not redefine these generic contracts |
| Permanent processing, transcription, upload and admin-action ownership | This plan | Feature plans consume the settled seams |

## Architecture invariants

Every implementation PR must state which invariant it makes true.

1. **One writer:** exactly one application boundary creates/replaces/reorders canonical
   `ChurchServiceItem` rows in steady state.
2. **One terminal owner:** retrying jobs never publish terminal run state; one exhausted-chain
   boundary owns run status, cleanup, dedup release and notification.
3. **Ordered timing:**

   ```text
   subprocess deadline < job timeout <= worker timeout < retry_after
   overlap expiry > job timeout + cleanup/retry grace
   ```

4. **Durability is explicit:** a path described as permanent names its archive/backup and restore
   test; a non-permanent path names its retention and safe-deletion predicate.
5. **Expand/contract:** additive schema, compatible readers/writers, verified backfill, reader/writer
   removal, and only then a later contract migration.
6. **Raw in, escaped once:** one layout owner emits title/description/canonical/robots values; reactive
   updates carry the same complete payload.
7. **State by lifetime:** JavaScript owns byte progress, cancellation listeners and EventSource
   lifetime; Livewire owns validation, accepted processing identity and durable server state.
8. **Authorisation at effects:** every admin action that persists, deletes, dispatches or calls an
   external system authorises directly or delegates to a boundary that does; ephemeral UI state is
   explicitly excluded.
9. **Temporary means removable:** every historic-only hook, worker, command, compatibility reader,
   writer and schema field names the G9/WP10 evidence that permits its removal.

## Maintainer decisions

Record each answer in this table before starting the package it blocks. The recommendation is a
default, not permission to assume an answer.

| ID | Decision | Recommendation | Blocks |
|---|---|---|---|
| AM-D1 | Are original livestream uploads permanent source evidence, or a recoverability cache with finite retention? | Prefer a finite, explicit retention period after all expected derived assets pass audit, unless the church wants to fund durable private archive storage and tested restores | AM1 |
| AM-D2 | What may the normal rollback workflow deploy? | Only the immediately previous successful release; anything older is a database/object restore procedure | AM4b |
| AM-D3 | Which runtime/build matrix is supported? | PHP 8.4 remains the tested minimum/local runtime, PHP 8.5 the production runtime with production-image smoke coverage, and Node 22 the single frontend build version; pin exact Playwright package/image versions | AM12 |
| AM-D4 | Is live production `ServiceStructureMode::Shadow` still used for model/prompt promotion? | Delete runtime Shadow if offline corpus evaluation has replaced it; otherwise retain it with an owner and a named reconsideration *event* (not a calendar date) | AM10 |

Calendar partial failure has a plan default: one skipped upstream event makes the command return
non-zero after completing safe work. A different tolerance requires a maintainer decision recorded in
AM11; do not invent a silent threshold.

## Delivery map

Review-surface sizes describe human review/blast radius, not elapsed time.

**No package in this plan gates on a calendar period.** Every acceptance step must name evidence
that is obtainable on demand — a query over data already persisted, a deliberately triggered
producer, a restart, a probe. Where a question genuinely cannot be answered from accumulated
evidence, record that as the finding and decide conservatively (retain/keep); do not convert it into
a soak, observation window or waiting period that silently blocks everything downstream.

| Package | Outcome | Size | Gate |
|---|---|---:|---|
| AM0 | Baseline, decisions and invariant test homes | S | — |
| AM1 | Original recordings have archive/restore or finite-retention proof | L | AM-D1 |
| AM2 | Queue/subprocess/overlap timing contradictions are impossible | M | — |
| AM3 | Every production log stream has proved bounded rotation | M | **Delivery 1 blocks the historic operation window (FR-D7)** |
| AM4a | New migrations cannot couple to app code or silently mix DDL/DML | S | — |
| AM4b | Normal rollback is schema-compatible by construction | L | AM-D2 |
| AM5 | One SSR/reactive document-head contract | M | Before Site Search Delivery 1 |
| AM6 | Admin side-effect authorisation is classified and executable | M | Prefer after R14 |
| AM7 | One browser owner for upload progress/cancel/SSE | M | — |
| AM8 | One terminal media-processing failure owner | L | G9, then before Sentry Delivery 2 |
| AM9 | Transcription and transcript storage are separate ports | M | Prefer after AM8; coordinate with code-quality WP2.5 |
| AM10 | `DetectServiceStructure` is a queue adapter over a tested workflow | L | G9 + AM-D4 |
| AM11 | Calendar partial failure and overlap are visible | S | — |
| AM12 | Runtime/build versions are explicit and enforced | M | AM-D3 |
| AM13 | Container startup cost is independent of retained data size | M | AM3; preferably AM12 |
| AM14 | Low-priority residue is deleted, fixed or explicitly kept | S slices | Per slice |
| EX-H | Historic hooks/compatibility architecture retire | L | External G9/WP10 owner |
| EX-J | July R13–R15 close and agent guidance is current | M | External July owner |

## Sequencing

```text
AM0 decisions/baseline
 ├─ AM-D1 ──> AM1 source-recording policy
 ├─────────> AM2 queue timing
 ├─────────> AM3 logs (Delivery 1, then Delivery 2) ─────────┐
 ├─────────> AM4a migration guard ─ AM-D2 ─> AM4b rollback   │
 ├─────────> AM5 document head ─> Site Search Delivery 1     ├─> AM13 bounded startup
 ├─────────> AM11 calendar sync                              │
 └─ AM-D3 ──> AM12 runtime matrix ───────────────────────────┘

July R14 ─> AM6 authorisation classification ─> AM14a admin residue
          └> July R15 closes independently; do not hold it for this plan

AM3 Delivery 1 ──────────────────┐  (satisfies FR-D7's accepted log record)
                                 v
Historic readiness/rehearsal ─> production apply ─> G9
                                            ├─> WP10 / EX-H contraction
                                            ├─> AM8 single terminal owner ─> Sentry Delivery 2
                                            ├─> AM9 transcription/storage split
                                            └─ AM-D4 ─> AM10 structure-job split

AM7 upload ownership and independent AM14 slices may run when they do not overlap an active UI PR.
```

Do not combine infrastructure packages merely because they touch Docker/workflow files. AM3 changes
log transport, AM12 changes versions, and AM13 changes startup ownership; each needs a separately
reversible result.

## AM0 — Baseline and decision record

### Purpose

Prevent implementation from silently changing scope or making unmeasured cleanup claims.

### Steps

1. Reconfirm the file/line inventory in this plan against the implementation branch. Update counts,
   not conclusions, when code has moved.
2. Record AM-D1–AM-D4 answers in the table above. Each decision may be committed independently.
3. Capture current evidence without mutating production:
   - configured Horizon worker timeouts and queue `retry_after`;
   - current source-recording volume size/growth and oldest/newest files (operator-run in production);
   - effective production `LOG_CHANNEL`, PHP error-log target and Supervisor log destinations;
   - current/previous deploy image identity available on the host;
   - actual PHP/Node/Playwright versions used by PR CI, production build and running image; and
   - whether any recent production run has Shadow metadata.
4. Record the test homes used by later packages:
   - queue/schedule/config invariants under `tests/Feature/Config/`;
   - deployment/storage/workflow invariants beside existing production configuration tests;
   - Livewire behaviour in component-owned feature tests and Dusk;
   - no one-off verification scripts when a PHPUnit/Dusk test can enforce the contract.
5. Reconfirm worktree scope before every PR. Existing unrelated changes belong to their author.

### Acceptance

- Dated baseline evidence exists for each package; unknown production facts remain explicitly
  unknown.
- No implementation package is blocked by a question that this plan forgot to name.

## AM1 — Original-recording durability or finite retention

**Review finding:** `storage/app/livestream` is called permanent in Compose and tests, but is only a
named Docker volume and is absent from the application backup. A host/disk loss removes the source
needed to re-derive assets.

**Who benefits:** the operator and anyone recovering missing media. **Observable improvement:** every
original recording can either be restored from a tested durable copy or is deleted only after a
documented, verified retention predicate.

### AM1.1 — Decision and policy contract

1. Use the AM0 production inventory to estimate current corpus size, monthly growth and archive cost.
2. Record AM-D1 as one of two contracts:
   - **Archive contract:** private durable object/archive storage, retention/lifecycle rules, named
     owner, checksum, restore frequency and off-host failure domain.
   - **Finite-retention contract:** retention duration plus a deletion predicate proving processing
     terminal success, expected public/private derived assets present, checksums/HTTP storage checks
     green, no open review requiring the source, and no active retry/reprocessing reference.
3. Rename every misleading “permanent” comment/test description in the same policy PR. Persistence
   across deploy and durability across host loss must use different terms.
4. Add a small production-storage policy registry in existing configuration. Every persisted path
   names `purpose`, `durability` (`archive`, `backup`, `reconstructible`, `temporary`) and either a
   policy key or an explicit exemption reason. Do not place credentials or environment-specific
   bucket names in this registry.
5. Extend `ProductionStoragePersistenceTest` (or a focused sibling) so a path cannot be labelled
   permanent/durable without an archive/backup policy, and a temporary path cannot omit retention.

### AM1.2A — Archive implementation (only if AM-D1 chooses archive)

1. Reuse the existing Flysystem/S3-compatible storage stack; no new package. Define a private source
   archive disk/prefix through config and production secrets.
2. Stream uploads to the archive—never load whole recordings into memory—and calculate SHA-256 while
   copying. Persist the archive locator, byte count and checksum in the existing typed processing
   metadata/evidence boundary unless inspection proves a dedicated indexed column is required.
3. A successful copy requires write success, size equality and checksum/read-back verification. A
   failed copy leaves the local source untouched and exposes a retriable failure.
4. Local deletion is a separate idempotent cleanup step admitted only after the durable locator is
   committed. A retry must recover when the object exists but metadata or cleanup did not finish.
5. Add a read-only audit that reports missing/mismatched archive objects without repairing them. If
   this is a recurring operator function, make it a normal command; do not create a one-shot.

### AM1.2B — Finite-retention implementation (only if AM-D1 chooses retention)

1. Put the duration and deletion predicate in configuration; app code uses `config()`, not `env()`.
2. Extend the existing media cleanup command/service rather than creating a second scheduler path.
3. Select candidates in bounded chunks and lock/recheck each processing run immediately before
   deletion. Never infer safety from age alone.
4. Delete the source only after every required derived-asset/storage check passes. Record a durable
   cleanup outcome with source checksum/size, predicate version and deletion time.
5. Schedule with `withoutOverlapping()`, `onOneServer()`, production-only environment and a lock
   lifetime greater than its bounded runtime. Historic ingress/freeze guards remain authoritative
   during the one-time operation.

### Tests

- policy registry and Compose/Dockerfile path agreement;
- streamed copy success, short write, checksum mismatch, existing-identical object, existing-
  different object, metadata-save failure and cleanup retry;
- or, for retention: every deletion predicate failure, locked recheck, partial asset loss, manual
  review, active retry, idempotent already-missing file and scheduler metadata;
- one operator-run restore of a representative archived source into scratch storage with checksum
  equality, or one dry-run retention report reviewed before enabling deletion.

### Rollout and acceptance

Archive/retention starts disabled. Backfill or clean existing sources only after new uploads have
completed the full contract. Do not add the corpus to the existing 5 GB application-backup rotation.
Acceptance requires one proved restore or one reviewed no-delete/dry-run plus a second idempotent run.

## AM2 — Executable queue, subprocess and overlap timing

**Review findings:** `GenerateRmsLog` times out after 3,600 seconds while its FFmpeg subprocess may
run for 7,200; three publication overlap locks expire before their jobs can time out.

**Who benefits:** operators processing long recordings. **Observable improvement:** configuration
cannot boot/test green when a subprocess outlives its job or an overlap lock can expire first.

### Steps

1. Write failing invariant tests first, modelled on `QueueRetryAfterInvariantTest`:
   - RMS subprocess < RMS job <= matching Horizon worker < Redis `retry_after`;
   - every `WithoutOverlapping` expiry > owning job timeout + declared grace;
   - all queues used by these jobs have a matching worker;
   - duration values are integers in seconds and positive.
2. Define the RMS budget once in `config/media-processing.php`. Use the existing production envelope:
   a recommended starting budget is subprocess 6,900 seconds, job 7,080, worker 7,200 and
   `retry_after` 7,260. Recheck real long-run evidence before committing these values.
3. Set the serialised job timeout from the validated configuration at construction/dispatch time and
   pass the subprocess budget into `VideoSegmentationService`; delete the hard-coded 3,600/7,200 pair.
4. Change publication overlap expiry to `job timeout + 120 seconds` (or one named grace constant),
   covering `PrepareSectionPublicationCandidates`, `AutoPublishServiceSection` and
   `PublishApprovedServiceSection`.
5. Audit every job using `WithoutOverlapping`; the invariant test must enumerate dynamically so a new
   job cannot evade it. Where a database row lock intentionally replaces middleware, document that
   decision in the job test rather than adding two competing locks.
6. Decide the supported queue surface. Recommendation: declare Redis as the only asynchronous
   production connection and stop implying database/Beanstalkd share the proved long-job contract.
   If other connections remain supported, extend the strict invariant to each one.
7. Update `AGENTS.md`/production operations timing prose only after the executable values are green.

### Tests and acceptance

- focused config/reflection tests and affected job/service tests;
- one representative long FFmpeg test uses a fake process/clock—do not run a two-hour fixture;
- PHPStan, Pint, full parallel PHPUnit suite;
- production config cache and Horizon config inspection in the deploy smoke environment.

Rollback is configuration-only: restore the prior budgets together. Never roll back only one number
in the ordered chain.

## AM3 — Bounded production logs

**Review finding:** the historic operation accepted rotating logs as its live record, but the
production log files are not bounded the way that acceptance assumes. Docker's JSON-file rotation
(`max-size: 50m`, `max-file: 3`) covers stdout/stderr only, which today means Nginx and PHP-FPM.

Verified against `ac1468b47`, the four producers differ and the fix must not treat them as one case:

- **`storage/logs/laravel.log` is genuinely unbounded.** `config/logging.php` resolves
  `LOG_CHANNEL=stack` to `'channels' => ['single']`, and the `single` driver never rotates. It is
  also the highest-volume producer, because `LOG_LEVEL` defaults to `debug`.
- **PHP's `error_log`** targets a file rather than stderr, so it never reaches Docker rotation.
- **Scheduler and Horizon** write `storage/logs/scheduler.log` and `storage/logs/horizon.log` through
  Supervisor `stdout_logfile` without `stdout_logfile_maxbytes`, so they inherit Supervisor's
  defaults (roughly 50 MB × 10 backups) rather than being unbounded. They are *bounded but large and
  invisible* to `docker logs` — a transport problem, not a runaway-growth problem.

All four sit on the persistent `app-logs` volume. Do not carry "non-rotating" as a blanket claim into
the implementation PR: the urgent correctness item is `laravel.log` plus the PHP error log; Scheduler
and Horizon are moved for visibility and single-owner rotation.

**Who benefits:** the operator during long operations and incidents. **Observable improvement:** all
production process logs are visible through one documented command and bounded by a tested size/file
retention policy.

### Ownership correction

This package owns transport, rotation and retention. The Sentry plan remains an optional third
observability layer for event capture, privacy scrubbing and alerts. If Sentry is installed later,
it consumes AM8's final exception owner.

**Historic final-readiness FR-D7 accepted "rotating logs as the operation record" in place of
Sentry. This package's finding is that the accepted control does not yet exist.** FR-D7's premise
must not be cited as evidence that logging is settled — it is the thing AM3 has to make true.
Sentry is still not an import gate, because FR-D7 declined it on its own merits; but the
alternative FR-D7 chose is unbuilt, so **AM3 Delivery 1 is a prerequisite of the historic
operation**, not free-running infrastructure work. See the loop-back note in the historic
final-readiness plan's FR-D7 entry.

### Delivery 1 — redirect new logs, retain the old volume

1. Add a failing production configuration test covering all process outputs:
   - Compose explicitly forces production Laravel logging to the `errorlog`/stderr channel;
   - PHP `error_log` targets stderr;
   - Nginx, PHP-FPM, Scheduler and Horizon Supervisor programs emit to stdout/stderr; and
   - Compose keeps bounded `json-file` options for the app container.
2. Preserve file-based `single`/`daily` channels for local/CI artifacts. Change only the committed
   production selection; `.env.production` must not silently override it.
3. Change PHP errors to `/proc/self/fd/2` (or the image's proved stderr descriptor).
4. Point Scheduler and Horizon Supervisor output at `/dev/stdout` with unlimited Supervisor-side
   buffering, matching the existing Nginx/PHP-FPM pattern. Docker, not two different layers, rotates.
5. Update operations/runbook commands from `tail storage/logs/...` to bounded `docker logs` commands
   that do not expose secrets in public artifacts.
6. Keep the `app-logs` mount for this first release so old evidence is not made inaccessible during
   the transport change.

### Delivery 2 — verify rotation and contract the old mount

1. Operator: generate identifiable application, PHP, Scheduler and Horizon test lines; prove each is
   present through `docker logs` and rotates under the configured limit.
2. Prove no production process writes into `storage/logs` by obtainable evidence, not elapsed time:
   record the mtime and size of every file under the mount, restart the stack, exercise each of the
   four producers deliberately (application log line, PHP error, a scheduled command, a queued job),
   and confirm all four surfaced through `docker logs` while no file under `storage/logs` advanced.
   A producer that cannot be triggered on demand is named as a residual risk in the same evidence
   note; it does not convert this step into a waiting period.
3. Remove `storage/logs` from the production persistence registry, Compose mount, Dockerfile setup and
   entrypoint ownership paths in one PR. Do not delete the Docker named volume.
4. After the accepted evidence-retention window, the operator may separately remove the orphaned
   volume. Record what was removed and whether an archive exists.

### Tests and acceptance

- config/Compose/Supervisor/PHP cross-file test;
- production image boot and canary;
- config cache test;
- operator evidence for all four log producers and Docker rotation;
- historic operation record updated to name the effective log command and retention budget.

## AM4 — Enforced schema lifecycle and safe normal rollback

**Who benefits:** deployers and developers writing migrations. **Observable improvement:** new mixed
or app-coupled migrations fail CI, and the normal rollback workflow cannot deploy an arbitrary old
image against a newer schema.

### AM4a — Future-migration guard

1. Do not edit deployed migrations. Add a PHPUnit architecture test over migration PHP files that:
   - rejects imports from `App\`;
   - rejects a file containing both schema DDL and data writes/backfill behaviour;
   - requires an explicit forward-fix explanation where `down()` cannot be safely reversible; and
   - does not allow an ever-growing baseline.
2. Grandfather only the currently deployed known offenders in the test's exact allowlist, including
   `2026_08_03_000001_add_projection_state_to_church_services_table.php` and
   `2026_08_09_120000_add_portable_source_key_identity_to_church_service_source_records.php`. The test
   must assert the allowlist equals the observed offender set, so deleting/squashing one shrinks it.
3. Prefer token/reflection analysis to a shell verification script. The failure message explains the
   required split: expand DDL, separately deployable backfill/action, later contract DDL.
4. Add the rule to the migration review checklist in `AGENTS.md` only if the existing generated/manual
   guidance does not already state it.

### AM4b — Previous-release-only rollback

Depends on AM-D2. Recommended implementation:

1. During deploy, resolve the currently running image SHA before the swap. Keep it as the candidate
   previous release; do not alter the accepted manifest yet.
2. After migration, container swap, optimize, sitemap and edge canaries all pass, atomically persist a
   small on-host release manifest with `current_sha`, `previous_sha`, deployment time and whether the
   release contains a contract migration. Do not store secrets or database contents.
3. Remove arbitrary `image_tag` input from the normal rollback workflow. It reads `previous_sha`,
   validates the full SHA shape, refuses current/empty/unknown values and redeploys only that image.
4. A contract migration may ship only when the immediately previous release has already stopped
   reading/writing the contracted fields; this preserves one-release rollback compatibility.
5. Anything older than `previous_sha` uses a separately documented restore procedure with the
   matching database backup/object evidence. The normal workflow must say so and refuse the request.
6. Add required production-environment review if it is not already configured; repository tests can
   verify the workflow declares the environment but cannot prove GitHub settings.

### Tests and rehearsal

- PHPUnit/YAML tests for workflow inputs, release-manifest update ordering, SHA validation and refusal;
- deploy workflow test proving the manifest advances only after successful canaries;
- rollback test fixture for absent/corrupt/same SHA;
- non-production rehearsal: deploy A, deploy additive B, roll back to A, run canaries;
- separate rehearsal for a later contract release proving its immediately previous reader is
  compatible;
- full infrastructure quality gates and production image smoke.

## AM5 — One server/reactive document-head contract

**Review findings:** page titles are deliberately double-escaped, and song filters update only the
browser title while description/canonical remain stale. Site Search would otherwise add a third
metadata implementation.

**Who benefits:** visitors, search engines and developers building public pages. **Observable
improvement:** special characters render once and title/description/canonical/robots always describe
the same URL after SSR, filtering and Livewire navigation.

### Delivery 1 — characterise and fix SSR escaping

1. Activate `frontend-design`, `livewire-development`, `tailwindcss-development` only if markup
   classes change, and `spatie-javascript` for the shared updater.
2. Add failing semantic tests with `&`, quotes and an attempted HTML tag in title/description. Assert
   decoded DOM text/content and absence of executable/raw markup; do not pin the old double-escaped
   source string.
3. Use Blade's inline stack content transport (for example `@push('title', $resolvedTitle)`) to pass
   the raw scalar without rendering it. `layouts/main.blade.php` remains the only output boundary and
   escapes once with `{{ }}`.
4. Standardise page, auth and admin shell title/description producers on the same transport. Preserve
   JSON-LD and OpenGraph component behaviour.
5. Replace `BladeShellRenderingTest` assertions that bless double escaping with semantic output tests.

### Delivery 2 — one complete reactive payload/updater

1. Define one browser payload with optional `title`, `description`, `canonical` and `robots` keys.
   Missing means unchanged; explicit `null` means remove only where the contract permits.
2. Create one imported JavaScript updater/listener in `resources/js/`; register it once from the main
   bundle. Do not keep page-specific Alpine listeners or inline head-manipulation scripts.
3. Make sermon and song filters dispatch the same named event with the complete payload. Bust Livewire
   computed metadata before reading it in batched update hooks.
4. Update pagination/query changes that affect canonical/robots, not only search/facet changes.
5. Amend Site Search Delivery 1 to consume this event for its `q`/robots behaviour; it owns search
   semantics, not another updater.

### Tests and acceptance

- SSR special-character/XSS tests across public, auth and admin shells;
- component tests for sermon/song payload equality after search, range/facet and page changes;
- Dusk: filter, navigate, clear, then assert document title, description, canonical and robots;
- production frontend build, PHPStan, Pint and full parallel PHPUnit; Dusk required;
- no Playwright baseline update unless rendered page pixels actually change.

## AM6 — Executable admin side-effect authorisation

**Review finding:** every admin component is structurally required to use
`WithAdminAuthorization`, but the documented “every mutating action” rule is ambiguous and only a few
methods have structural enforcement.

**Who benefits:** administrators and future maintainers. **Observable improvement:** every public
admin method is classified as lifecycle/UI-only or side-effecting, and every side-effecting method
fails unauthorized before persistence/external work.

### Steps

1. Run after or alongside R14 only if it does not edit a flat deletion-scheduled test. Place all new
   assertions in component-owned/structural homes.
2. Clarify the durable contract in `WithAdminAuthorization` and `AGENTS.md`:
   - side effect = database/file mutation, delete, queue/event/mail/external dispatch or
     authorisation-relevant state;
   - UI-only = ephemeral form/list state such as adding an unsaved point;
   - route middleware plus component trait remains defence in depth.
3. Inventory every public method on `App\Livewire\Admin` components, excluding known Livewire
   lifecycle/render hooks. Classify each through a small explicit attribute or structural allowlist;
   choose the form that follows existing test conventions and creates the least production code.
4. The structural test fails when a new public method has no classification. Side-effect entries must
   either call `authorizeAdmin()` before work or name a delegated action whose test proves the guard.
5. Add unauthorized Livewire behaviour tests for every persisted/external action touched while
   closing gaps. Verify no model, storage, queue or mail side effect occurs.
6. Do not add `authorizeAdmin()` ceremony to harmless UI-only methods merely to satisfy a regex.

### Acceptance

- every admin component still uses the trait;
- every public method is classified;
- every side effect has focused unauthorized behaviour coverage or an explicitly tested guarded
  delegate;
- PHPStan, Pint, full parallel suite; Dusk only if visible interaction changes.

## AM7 — One browser owner for media upload state

**Review finding:** upload state is split among Livewire fields, direct DOM mutation in the JavaScript
controller and a separate inline Alpine/EventSource implementation.

**Who benefits:** operators uploading recordings. **Observable improvement:** cancellation stops the
browser transfer, terminal processing closes one EventSource, and upload progress causes no periodic
server round trips.

### Steps

1. Preserve the resolved O27/O28 regressions with Dusk characterisation before refactoring: cancel
   stops transfer/processing; failed/cancelled/manual-review states cannot expose a dead second picker;
   “Upload another” clears prior identity.
2. Extend `resources/js/livewire/media-upload-controller.js` to own:
   - byte progress and its DOM/ARIA projection;
   - current browser filename;
   - client cancellation plus server reset callback;
   - the processing EventSource, reconnect/error/terminal close rules; and
   - listener cleanup in `destroy()`.
3. Remove the inline `x-data` EventSource object from the Blade form and the throttled
   `updateUploadProgress` calls to PHP. Remove the Livewire progress property/method if no durable
   consumer remains.
4. Keep Livewire as owner of validation, ingress refusal, accepted processing ID, durable
   `UploadState`, status query/result and “upload another” reset.
5. Use one event payload from SSE to decide whether Livewire needs a status refresh. Coalesce bursts;
   a progress event must not cause duplicate concurrent `$wire.checkProcessingStatus()` requests.
6. Keep the single class + Blade Livewire component. Do not create a child component for progress.
7. Delete JavaScript source-string tests made obsolete by behaviour coverage; retain PHP state-machine
   tests and Dusk interaction tests.

### Tests and acceptance

- Livewire feature tests for validation, ingress, processing identity and terminal reset;
- Dusk for start/progress/cancel/error/terminal/retry/navigation-away cleanup;
- browser instrumentation or a focused assertion proving progress no longer calls PHP repeatedly;
- production frontend build, PHPStan, Pint, full suite and Dusk.

## AM8 — One terminal media-processing failure owner

**Gate:** wait for historic G9 unless final readiness identifies this as necessary to make rehearsal
or operation failure evidence correct. Sequence before Sentry Delivery 2 so reporting attaches once
to the final owner.

**Who benefits:** operators diagnosing processing and developers adding jobs. **Observable
improvement:** a retryable error never appears terminal; an exhausted run produces exactly one final
state transition, cleanup decision, dedup release, notification and optional report event.

### Delivery 1 — characterise current semantics

1. Enumerate every job catch that calls `markAsFailed`, every `failed()` callback and every chain
   catch. Classify each responsibility as step context, job-specific compensation or run-terminal
   work.
2. Add profile-level tests for audio, direct video, auto-trim and livestream covering:
   - first-attempt failure followed by success;
   - all attempts exhausted;
   - timeout/fail-on-timeout;
   - cancellation/manual review; and
   - chain callback deserialization after a worker restart.
3. Assert current externally required results, but add explicit failing assertions for one terminal
   transition/notification and no terminal state before retries exhaust.

### Delivery 2 — centralise terminal ownership

1. Make step jobs record local processing-step context and throw. They do not set run-terminal status,
   release cross-run deduplication or notify the operator in `catch`.
2. Keep `failed()` only for irreversible job-local compensation that the chain handler cannot infer.
   Document each retained callback in its test. Remove duplicate callbacks where none remains.
3. Make `ProcessingRunFailureHandler` the single exhausted-chain boundary for every processing
   profile. Route audio/video and auto-trim/livestream through
   `MediaProcessingRunTransitionService` rather than mixing direct updates and transitions.
4. Centralise cleanup, dedup release and notification there. Add an idempotency key/terminal-state
   guard so a replayed chain callback is harmless.
5. Add structured exception context once. If Sentry Delivery 2 is active, `report($exception)` occurs
   here only; enable duplicate suppression and do not report expected manual-review/cancel/retry
   states.
6. Migrate one processing profile per green commit. Do not convert all jobs in one unreviewable diff.

### Acceptance

- all profile characterisation tests green;
- grep shows no job catch writing run-terminal state outside the documented exception list;
- exactly one notification/report on exhausted failure, none on successful retry;
- status API/Livewire consumers observe monotonic non-terminal → terminal state;
- PHPStan, Pint and full parallel suite.

## AM9 — Separate transcription from transcript storage

**Gate:** preferably after AM8 so failure cleanup already has a stable owner. Code-quality WP2.5 may
remove the `'unknown'` defaults earlier and remains free to do so; do not wait merely to batch diffs.

**Who benefits:** developers changing transcription providers and tests using mocks. **Observable
improvement:** external transcription adapters implement external operations only, and transcript
storage/cleanup tests do not instantiate a provider.

### Steps

1. Re-grep all `TranscriptionServiceInterface` callers and adapters. Characterise real, local and
   mock provider behaviour before changing the contract.
2. Reduce `TranscriptionServiceInterface` to `transcribe()` (and only another operation if the caller
   census proves it is provider-specific). Remove store/get/exists/delete/cleanup/path methods.
3. Inject `TranscriptStorageService` directly into `TranscribeAudio` and any real storage consumer.
   After AM8, terminal cleanup belongs at the central failure boundary; job-local cleanup remains only
   where a partial file requires immediate compensation.
4. Remove `HandlesTranscriptStorage` from adapters and delete the trait when grep confirms zero
   consumers.
5. If code-quality WP2.5 has not landed, remove the `'unknown'` processing-ID defaults in this PR and
   mark that external item complete. Every caller supplies a real ID or an explicitly nullable typed
   value—never a sentinel string.
6. Keep service-provider selection fail-closed per code-quality WP2.2; do not mix provider-config
   behaviour changes into this contract PR unless that work already landed.

### Tests and acceptance

- contract tests for each provider's transcription only;
- `TranscriptStorageService` lifecycle and failure tests independent of providers;
- `TranscribeAudio` integration tests for store, cleanup and retry;
- container binding tests; PHPStan catches old interface calls;
- full project gates.

## AM10 — Thin `DetectServiceStructure` into job + workflow + comparator

**Gate:** G9 and AM-D4. Do not destabilize the historic rehearsal/operation for an internal class split.

**Who benefits:** developers changing service-structure detection and operators reading failures.
**Observable improvement:** the job contains queue concerns plus one workflow call; primary workflow
outcomes and proposal comparison are testable without dispatching a job.

### AM10.1 — Resolve Shadow ownership

1. Use AM0 evidence to determine whether production Shadow has ever run and whether anyone reads its
   reports for model promotion. Query the accumulated run metadata that already exists — the
   presence or absence of Shadow proposals across the retained history is the evidence, and it is
   available now. Do not open a fresh watching period.
2. Record AM-D4:
   - **Retain:** name operator, trigger and report consumer, plus the *event* that should trigger
     reconsideration (for example the next model or prompt promotion). Do not set a calendar review
     date that nobody is accountable for reaching.
   - **Delete:** remove runtime `ServiceStructureMode::Shadow`, job branch and runtime config/tests;
     keep offline evaluation commands/fixtures that still earn their place.
3. Make the Shadow decision its own subtractive PR before moving workflow code.

### AM10.2 — Extract two coherent boundaries

1. Freeze behaviour with focused tests for primary success, validation/manual review, recovery,
   transcript/OOS loading failure, sermon-confidence policy, notification and (if retained) Shadow.
2. Add one application workflow/coordinator under the existing church-service Structure domain. It
   receives explicit dependencies and returns a typed result such as accepted, manual-review,
   recoverable failure or shadow-only result.
3. Move the self-contained proposal/baseline diff engine into one comparator with value-object input
   and output. Do not turn each private helper into a service.
4. `DetectServiceStructure` retains serialisation, middleware, cancellation/overlap, retry policy and
   one injected workflow call. Replace late `app()` resolution inside ordinary workflow code.
5. Keep detector, validator, sync and source-evidence seams that already exist. This PR changes
   ownership, not detection policy or prompt/schema.
6. Split the 1,074-line job test by behaviour owner: queue adapter tests, workflow tests and comparator
   tests. Delete duplicated assertions; do not reduce coverage of manual-review or recovery edges.

### Acceptance

- no prompt/model/output behaviour change in fixture/evaluation comparison;
- job source contains queue concerns and one workflow dispatch, not I/O/persistence/diff policy;
- no late service location except framework callback boundaries that cannot receive injection;
- PHPStan, Pint, full parallel suite; real-model evaluation only if current plan/runbook requires it.

## AM11 — Visible, non-overlapping calendar synchronisation

**Who benefits:** calendar visitors and the operator. **Observable improvement:** concurrent syncs
cannot race, and a repeatedly failing event makes schedule monitoring non-green.

### Steps

1. Add a failing schedule test proving `calendar:sync` has `withoutOverlapping()`, an explicit lock
   expiry/grace, `onOneServer()` and production environment restriction.
2. Choose a lock lifetime from measured worst-case runtime and keep it below the four-hour cadence.
   Bound the command or lock rather than allowing a stale lock to suppress the next run indefinitely.
3. Preserve the service's safe behaviour: an event returned by Google but failing locally is not
   deleted as absent.
4. Make the command display processed, skipped, deleted and uncategorized counts. If
   `skipped_events > 0`, emit a structured warning/error and return non-zero after safe work
   completes. A different accepted threshold must be explicit config plus a maintainer decision.
5. Ensure schedule-monitor/health records the non-zero result; do not add a second calendar-specific
   alert system.
6. Coordinate, but do not combine, with code-quality WP2.7's PHPStan-ignore typing cleanup.

### Tests and acceptance

- service tests for mixed success, no accidental deletion and returned IDs/counts;
- command tests for zero/non-zero exit and output;
- scheduler metadata/overlap test;
- one operator observation of the next scheduled run or a controlled non-production partial failure;
- PHPStan, Pint, full suite.

## AM12 — Authoritative runtime and build matrix

**Who benefits:** deployers and developers reproducing production. **Observable improvement:** every
intentional version difference is named/tested, Playwright package/image match, and image changes are
review-visible rather than floating silently.

### Steps

1. Record AM-D3 in one authoritative matrix in `docs/operations/production.md` and a cross-file test.
   The recommended matrix is:
   - PHP 8.4: Composer minimum, Sail/Jules and primary PHPUnit/PHPStan;
   - PHP 8.5: production image plus production-image boot/smoke;
   - Node 22: frontend builder and CI/npm jobs;
   - Playwright package/container: exact same version.
2. If the maintainer instead chooses one PHP version, update Composer, Sail/Jules, shared action,
   production image and agent guidance together. Do not claim support for an untested version.
3. Align the Docker frontend stage with the chosen Node version. Node is a build tool, not a
   production runtime; one version is sufficient.
4. Align Playwright's package and container tag exactly. Update baselines only if rendering actually
   changes.
5. Rename the stale `php8.4-fpm.sock` path when production runs PHP 8.5; update PHP-FPM/Nginx and
   their config tests together.
6. Pin Caddy, MySQL, Redis, Node, Composer and PHP production/build images to reviewed patch tags or
   digests. Use automated update PRs if available; do not silently float on major/minor tags.
7. Add a cross-file runtime test that reports drift with the authoritative value and file location.
8. Update only the manually maintained version prose in `AGENTS.md`; refresh generated Boost blocks
   through the project command when genuinely required, never hand-edit them.

### Tests and acceptance

- shared setup action and Docker/build config tests;
- npm clean install/build on the chosen Node;
- PHPUnit/PHPStan on the supported development PHP and production image smoke on production PHP;
- exact Playwright visual suite in the aligned image;
- Compose config and full image build.

## AM13 — Bounded container startup

**Gate:** after AM3 removes logs from persistent-volume ownership and preferably after AM12 avoids
repeated infrastructure churn.

**Who benefits:** operators restarting, deploying or rolling back production. **Observable
improvement:** steady-state startup time does not grow with recording/archive size while every mount
remains writable by `www`.

### Delivery 1 — outcome-based persistence test

1. Rewrite `ProductionStoragePersistenceTest` so it asserts mount persistence, seeded/root ownership,
   runtime writability and declared durability/retention—not literal recursive `chown -R` commands.
2. Add a container-level smoke that writes/deletes a small probe as `www` on each writable mount.
3. Inventory existing nested ownership once in production; do not assume the root tells the whole
   story before migration.

### Delivery 2 — one-time initialisation, bounded steady state

1. Replace unconditional recursion with a versioned per-volume initialisation marker:
   - marker absent: repair ownership/mode once, verify as `www`, then write marker atomically;
   - marker present: check only root/marker and fail loudly if unwritable; do not traverse contents.
2. New volumes should be seeded with correct ownership by the Dockerfile so even first boot avoids a
   large traversal. Preserve least-required modes; do not make all files globally writable.
3. Roll out one volume class at a time, starting with scratch/temp and ending with the recording
   volume. Capture startup duration and file count before/after.
4. Keep a separately invoked, operator-approved repair command for exceptional ownership recovery;
   normal startup must not hide drift by repairing everything on every boot.

### Acceptance

- first-boot and second-boot script/container tests;
- wrong-owner/unwritable failure test;
- production image canary and mount write probe;
- measured second startup remains bounded as a fixture directory grows;
- operator confirms representative existing volume ownership before removing fallback recursion.

## AM14 — Low-priority residue tranche

These slices are intentionally last or adjacency-triggered. Each may close with a documented keep
decision when deletion/extraction costs more than the complexity it removes.

### AM14a — Admin list/shell residue (after R14)

1. Remove `sortBy`/`sortDirection` from `ListUsers::filterProperties()` so sorting neither sets
   `hasFilters` nor changes when “clear filters” is used. Add namespaced component tests.
2. Reconfirm `resources/views/components/admin/shell.blade.php` has no rendered production consumer.
   Delete it and the obsolete `BladeShellRenderingTest` branch in one commit. Do not confuse it with
   the service-workbench plan's separate orphan partial.
3. Extract shared flash rendering only if two live shells still duplicate it after deletion.

### AM14b — Explicit bespoke page ownership

1. Confirm `pages/christ/free-bible.blade.php` is the only special slug-derived view.
2. Prefer an explicit route/controller/template selection for that one page, or add an explicit
   validated template key only if more special pages are genuinely planned.
3. Remove filesystem probing derived from mutable area/slug. Test that changing ordinary page data
   cannot silently select or lose bespoke behaviour.

### AM14c — Mechanical namespaces and repeated casts

1. Move `LivestreamSegmentationService` from the Sermon namespace to Processing or Media, following
   the majority of its dependencies. Do this only after G9 or while all callers are already changing;
   no behaviour changes in the namespace PR.
2. Compare all metadata casts' malformed/null/scalar behaviour. Introduce one small generic/abstract
   JSON value-object cast only if behaviour is identical and the change removes real duplication.
   Otherwise record a keep decision; do not create an internal framework for five short adapters.

### AM14d — Frontend bootstrap/dependency residue

1. Prove direct Axios/Alpine imports and runtime consumers with `rg`, build output and browser tests.
2. If direct dependencies are unused, request approval to remove them; do not infer approval from this
   plan. Remove bootstrap imports/config and packages in one dependency-only PR.
3. Run clean `npm ci`, production build, Dusk and Playwright. If Livewire/Vite relies on a direct
   package declaration, record why it remains.

### AM14e — Queue connection surface

If AM2 does not settle this, either delete unsupported database/Beanstalkd async configuration or
prove the strict timeout/retry invariant for each. Configuration advertised as supported must have a
worker and test; otherwise remove the surface.

## External closeout outcomes

### EX-H — Historic G9/WP10 contraction and one canonical writer

No implementation is authorised here. The historic plans own the operation and WP10; July R13b owns
the current caller/evidence decision. This plan records the steady-state acceptance that those owners
must hand back:

1. R13b classifies every `ChurchServiceItemSyncService` caller as permanent responsibility or
   compatibility-only and records the intended one-projector target.
2. G9 evidence passes; only then WP10 removes spent commands, historic model/queue/scheduler/Horizon
   hooks and compatibility readers/writers.
3. Manual, Email/OpenLP and livestream adapters all feed
   `IngestChurchServiceSourceRevision` → `ChurchServiceProjector` →
   `ChurchServiceProjectionPersister`.
4. Delete duplicate legacy canonical finalization and writer tests, porting only enduring behaviour to
   adapter/projector tests.
5. Add a structural/domain test proving there is one canonical item write boundary. The persister's
   “only writer” documentation must be true in code.
6. Ship obsolete-schema contraction in a later release than reader/writer removal.
7. Retain exact auditors, immutable evidence, portable contract tests, public read-side tests and the
   normal-output canary.

### EX-J — July closeout and source-of-truth guidance

R13 measurement/decision, R14 fold-ins and R15 archival remain entirely in the July plan. Do not hold
R15 for AM6/AM14. When R15 updates `AGENTS.md`, it must remove completed heuristic/visual do-not-invest
entries and describe the current primary pipeline. Historic-only deletion gates remain referenced
from their current authority until EX-H completes.

## Per-PR quality gates

Every code/config PR:

1. add or update focused tests before changing behaviour; a bug fix proves red → green;
2. run focused tests through Sail;
3. `vendor/bin/sail composer phpstan` at zero errors;
4. `vendor/bin/sail bin pint --dirty`;
5. `vendor/bin/sail artisan test --parallel --compact` for non-trivial changes, capturing the first
   full output with `tee` per `AGENTS.md`;
6. Dusk for browser interaction; Playwright only for visual changes;
7. `vendor/bin/sail npm run build` for frontend/bundle changes;
8. Compose config, production image build/smoke and relevant workflow/config tests for infrastructure;
9. no new dependency without explicit approval; and
10. no production command from an agent. Operator acceptance evidence is recorded after execution.

Deletion/refactor PRs retain characterisation tests until the new owner is green, then delete tests
whose subject is gone in the same PR. Do not preserve tests for removed architecture as historical
documentation.

## Programme acceptance

The plan is complete only when all applicable rows are evidenced, not merely coded:

| Measure | Baseline | Acceptance |
|---|---|---|
| Canonical item write architectures | Item sync/finalizer plus projector/persister | One projector/persister path |
| Run-terminal failure owners | Up to job catch + `failed()` + chain handler | Exactly one exhausted-chain owner |
| Known timing contradictions | RMS pair + three overlap locks | Zero; invariant test covers all long jobs/locks |
| Persisted paths without archive/expiry policy | `storage/app/livestream` | Zero |
| Production file logs without proved rotation | Laravel, PHP, Scheduler, Horizon | Zero |
| Normal rollback targets | Arbitrary SHA | Immediately previous accepted release only, or approved compatibility manifest if AM-D2 differs |
| New migration architecture violations | Not prevented | CI fails on `App\` imports and mixed DDL/DML |
| Reactive document-head implementations | Page-specific sermon/song paths | One payload/updater contract |
| Upload browser state owners | Controller + Livewire mirror + inline SSE | One browser controller; Livewire durable state only |
| Calendar partial failure visibility | Log warning + exit success | Monitored non-zero/accepted threshold |
| Runtime/version drift | PHP, Node, Playwright, socket and floating images | Explicit matrix; every difference intentional/tested |
| Data-size-dependent startup traversal | Every start | One-time initialisation; bounded steady-state |
| Historic-only permanent hooks after WP10 | Boot/model/queue/scheduler/Horizon | Zero unless retained with explicit permanent owner |
| R14 flat suites | Five / 2,992 lines at review baseline | Zero |

## Stop conditions

Stop the current package and return to the maintainer when:

- AM-D1–AM-D4 is unanswered and different answers materially change implementation;
- a proposed “cleanup” is required by an open historic G2–G9 gate;
- a characterisation test reveals the target boundary does not preserve public/admin behaviour;
- an infrastructure change would remove or overwrite an unarchived volume/log/source;
- a migration/rollback change needs production state not available through approved evidence;
- a dependency change becomes necessary; or
- the work overlaps an active unmerged plan PR in the same files and cannot be cleanly rebased.

## Closure

After all owned AM packages and external hand-backs are accepted:

1. remeasure the programme-acceptance table from code and operator evidence;
2. fold any surviving operational rules into `AGENTS.md` and `docs/operations/production.md` without
   copying class-by-class behaviour;
3. update `docs/plans/README.md` and move this plan to `docs/archived-plans/` with a dated completion
   header; and
4. remove any point-in-time review/report whose findings are now fully represented here or in another
   active owner. Git history is the archive.
