# Ensemble detection — current evaluation follow-up

**Reconciled 2026-11-06.** The ensemble was recorded built/evaluated in September, with subsequent
repairs in the shared pipeline. Its original delivery specification, numbered decisions, experiments,
rejected variants and canary results are archived in the
[implementation record](../archived-plans/HISTORIC-ENSEMBLE-IMPLEMENTATION-RECORD-2026-11-06.md).
This file carries current constraints and remaining evaluation ownership; it is not a new composer
build plan. No fresh model evaluation was performed during cleanup.

## 0. Decisions and authority

The [rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md#3-detection-and-structure) is the sole
current decision register. References to the original decisions 1–23 or old numbered design
sections resolve to the [archived specification](../archived-plans/HISTORIC-ENSEMBLE-IMPLEMENTATION-RECORD-2026-11-06.md#0-operator-decisions-this-plan-implements-2026-09-28).
Later amendments take precedence, especially G7/G8, D24, K20/K21 and E27 from 11-06.

- D8 retains four equal draws provisionally; failed validation does not vote. A real whole-job
  retry follows D10. This cleanup neither changes the configured models nor authorises paid calls.
- D24 treats consensus as disagreement detection, not proof of complete content. Shared transcript
  faults can survive unanimity. A composer's accepted structure is not accepted rendered media.
- G7/Q15 make minimal manual review and usable complete outputs the goal. Report material errors
  and operator effort, not merely fewer flags or more agreeing draws.
- D9/Q13 require measured rules, counterexamples and honest treatment of listening evidence.
  Reuse valid answers; record uncertainty rather than manufacture agreement from missing evidence.
- K21 concerns the separate sermon-ending selector: a middle/earlier line among differing model
  answers does not resolve consequential semantic disagreement. Harmless alternatives need not
  create another operator question.

## 3. Implementation principles retained

The old specification remains useful for design rationale: durable per-draw evidence; explicit
partial failure and deadlines; coherent supported alternatives; propagation of consequential
uncertainty; deterministic replay; scoped, evidence-bound corrections; and invalidation of only
dependent outputs. Current code and tests determine what is built. Generic class splitting or
repeating the original canary is not the next deliverable.

## 8. Risks and evaluation limits

Unanimous omissions, shared transcription errors, stale answer identities and overgeneralisation
remain relevant. Corpus regression proves remembered examples; source-first checks on fresh
recordings assess generalisation. Historical 232-draw results and dated dispute rates are neither
current error rates nor proof that the operator workload is acceptable. Missing source audio
cannot be replaced by additional text-model votes.

## 9. Remaining work and owners

| Work | Owner / completion evidence |
|---|---|
| Source-first checks of unanimous and unflagged results | Audit §§0/5/7: current output/source hashes, omissions, unflagged errors, unassessable cases and review time on fresh evidence. Retain the independent sampling contract. |
| Introduction and ending-selector blind spots | Video §4.1b/§4.1c: K20's known 1301 miss and K21's measured replacement; preserve counterexamples and existing rulings. |
| Stable replay and local corrections | Video §4.1a/§4.6 and audit controlled-variation lane: re-score current rulings, preserve identities, and verify changes reach only their dependencies. Older “finish the loop” instructions are not evidence it remains unbuilt. |
| Degraded draws, worker isolation and partial failure | Reconcile the original specification's promises with current regression/worker evidence before claiming them verified. Audit §7 owns the recovery/evaluation obligation; do not reimplement already-built machinery from the archive. |
| Runtime Shadow / offline evaluation ownership | Architecture AM-D4/AM10 at its existing gate. The old Q6 recommendation is historical, not a new deletion instruction. |

Application and acceptance remain with the [video worklist](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md)
and [audit](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md). Weekly processing stays paused until
historic completion; public release remains a separately authorised programme act.
