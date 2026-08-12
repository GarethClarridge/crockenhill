# July 2026 Simplification — closeout plan

> **Status (reconciled 2026-08-12): R1-R11 are complete; only R13, R14 and R15 remain.**
> The former parent backlog is now an
> [archived decision record](../archived-plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md).
> This file is deliberately self-contained for the remaining work.
>
> **Ownership correction:** R8 and R12 are not work packages in this plan any more. Historic
> acquisition, Email/OpenLP/video convergence, hymn evidence, Bundle A/B promotion and every
> import-related one-shot retirement are owned exclusively by
> [final readiness](HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md) and
> [readiness remediation](HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md). Their production
> gates and G9/WP10 decide when those tools can be deleted. Do not reconstruct that operation from
> this plan or its archived parent.
>
> **Agents must not:** run production commands; add coverage to the five legacy flat test suites;
> change the staged-merge policy without measured evidence; or hold R14/R15 behind the historic
> import, which is now a separate programme.

## Outcome

Close the July simplification programme without keeping an active tracker full of completed work:

1. make an evidence-based keep/simplify decision for the two deferred church-service seams;
2. fold the five duplicate Livewire suites into their component-owned homes; and
3. archive this closeout plan after its own work is complete.

## Disposition of the former R-items

| Items | Disposition |
|---|---|
| R1-R7 | Complete 2026-07-20/21 |
| R8 | Delegated to historic readiness G9/WP10; no deletion is authorised here |
| R9-R11 | Complete 2026-07-21; their PHPStan/workbench gates are released |
| R12 | Delegated to historic readiness; the old paragraph and runbook are superseded |
| R13 | Open here: two independent evidence-and-decision slices |
| R14 | Open here and unblocked: five duplicate-suite fold-ins |
| R15 | Open here: archive this tracker after R13/R14 |

The detailed implementation history remains in git and the archived parent record. It is omitted
here so completed/delegated instructions cannot be mistaken for current work.

## Recommended delivery order

Start the R13 measurement window immediately, then execute R14 while the window runs. R13's two
decisions may finish in either order. R15 is last.

```text
R13a measurement starts ────────────────┐
R14 duplicate-suite fold-ins ──────────┼─> R15 archive
R13b sync-service reassessment ────────┘
```

Each R13 decision is independently deliverable as either a small implementation PR or an explicit
keep decision. R14 is independent of R13 and of the historic import.

## R13a — staged structure-merge decision

**Question:** does the pending-structure-merge workflow still earn its complexity under the
primary pipeline?

1. Agree a live measurement window with the maintainer (recommendation: four ordinary Sunday
   cycles, extended if no merge opportunity occurs). Do not substitute bulk historic staging for
   the weekly-path evidence: its source ordering is different.
2. From persisted proposals/review state and operator decisions, record:
   - how often a pending merge was raised;
   - the source pairing and reason;
   - whether the operator accepted incoming, kept current or edited manually; and
   - whether the choice changed any publication outcome.
3. Prefer an existing read-only query/report. Add durable instrumentation only if the current data
   cannot answer the question; do not create a one-shot command merely to count it once.
4. Append the measured totals and decision here:
   - **simplify** when the workflow is rare and the operator consistently accepts the same policy;
     the follow-up PR may apply that policy automatically, retain `needs_review` plus a readable
     diff reason, and delete only machinery made unreachable; or
   - **keep** when real choices vary or the workflow prevents wrong canonical state. Record why and
     close the item without refactoring.

The measurement note is a complete deliverable even when the decision is “keep”.

## R13b — `ChurchServiceItemSyncService` reassessment

This is a separate decision from R13a and does not wait for its measurement window.

Separate the **current pre-G9 decision** from the **permanent post-G9 target**. Historic-readiness
may temporarily justify compatibility callers/writers; it must not make them permanent by inertia.
Record whether the intended steady state is one path from immutable source revision through
`ChurchServiceProjector`/its persister to canonical items, and name the historic G9/WP10 deletion
trigger for any caller that is retained only for the import window.

1. Enumerate current callers and classify each responsibility: source projection, manual editing,
   catalogue-song resolution, merge application or compatibility-only behaviour.
2. Verify whether livestream projection still needs full merge authority and whether cross-source
   song-title matching still fires in production-shaped tests.
3. Choose one outcome:
   - **keep as one service** when splitting would duplicate ordering/identity rules;
   - **extract a pure resolver/policy** only where at least two callers genuinely share it; or
   - **delete a dead branch** after proving it has no caller and no retained evidence contract.
4. Add focused characterisation tests only for surviving behaviour touched by an implementation
   PR. A “keep” decision needs no new tests.

Append the caller map, evidence and decision here before any code change.

## R14 — duplicate-suite fold-ins

This work is unblocked. Do it while R13a gathers live evidence. The legacy files are
deletion-scheduled, so port missing assertions; do not polish or extend them in place.

| Legacy flat file | Component-owned home |
|---|---|
| `tests/Feature/Livewire/Admin/EditSermonTest.php` | `tests/Feature/Livewire/Admin/Sermons/EditSermonTest.php` |
| `tests/Feature/Livewire/Admin/ListSermonsTest.php` | `tests/Feature/Livewire/Admin/Sermons/` |
| `tests/Feature/Livewire/AdminUserTest.php` | `tests/Feature/Livewire/Admin/Users/` |
| `tests/Feature/Livewire/AdminMeetingTest.php` | `tests/Feature/Livewire/Admin/Meetings/` |
| `tests/Feature/Livewire/AdminChurchServiceTest.php` | `tests/Feature/Livewire/Admin/ChurchServices/` |

For each file, in its own green commit:

1. diff every test method against the component-owned suites;
2. port only behaviour not already covered, placing cross-cutting trait/invariant coverage in its
   single structural home;
3. run the focused old and new suites together before deletion;
4. delete the flat file in the same commit; and
5. run PHPStan, Pint and the full parallel suite before merge.

Re-diff `AdminChurchServiceTest.php` rather than relying on the July inventory: the service
workbench and historic-import programme both changed church-service behaviour after this item was
first drafted. New workbench/familiarity tests belong only in the namespaced suites.

After the fold-ins, confirm `AGENTS.md` still states the durable conventions: one suite per
component, tests deleted with their subject, one integrity home, and eval manifests for
probabilistic seams. Amend it only if a rule is missing; do not duplicate rules already present.

## R15 — closeout

R15 depends only on R13 and R14, not on the historic import.

1. Ensure both R13 decisions are recorded and any resulting PRs are merged or explicitly parked.
2. Confirm all five flat suites are gone and the full suite is green.
3. Update `docs/plans/README.md` and `docs/README.md`.
4. Move this file to `docs/archived-plans/` with a dated completion header.

Historic one-shot cleanup remains visible in its own plans until their G9/WP10 closes; it is not a
reason to keep this simplification tracker active.

## Quality gates

For every code PR: focused PHPUnit coverage first; `vendor/bin/sail composer phpstan` at zero;
`vendor/bin/sail bin pint --dirty`; full `vendor/bin/sail artisan test --parallel --compact`.
Dusk is required only if an R13 implementation changes browser behaviour.
