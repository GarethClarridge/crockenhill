# HIR-D5/D6 scope wording — clarification (HIR-D7)

**Recorded:** 2026-08-14
**Status:** Applied 2026-08-14. HIR-D7 is decided and landed directly in
`HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md` §4.5 (plus the decision table and §5 item 7) and
in `AGENTS.md`'s bounded-exception paragraph. This file is kept as the rationale record — the
governing plan doc and AGENTS.md are now authoritative for the decision text itself.

## The problem

HIR-D5 permits changes inside `ImportOosArchiveCommand`/`OosArchiveEvaluator` that are
"correctness only — never refactoring, features or polish." HIR-D6 added nuance on top of that
(read-only observability is in scope; a change to what the importer imports unattended needs its
own decision) but kept the original three-word list unchanged.

That list has now caused the same misreading in multiple sessions, across two different coding
agents (Claude and Codex): a change that makes the extraction more *accurate* — a stronger model,
a better reasoning-effort setting, item-type-aware classification of what needs review — reads as
a "feature" or "polish" and gets default-declined, even though it serves the tool's own stated
purpose (correctly stage the approved corpus) more directly than most things that would pass the
literal "correctness" test (e.g. a narrow defect patch).

The wording bundles two genuinely different questions into one axis, and every misreading has
picked the wrong branch of that bundle:

1. **Engineering-investment discipline** — is this worth building for a tool scheduled for
   deletion? This is a *scope* question. Its purpose is stopping unrelated refactors, generalising
   the tool for reuse, or cosmetic cleanup — investment that doesn't serve the one shot this tool
   exists to take.
2. **Unattended-import risk** — does this change what gets written to the canonical historic
   record without an operator looking at it? This is a *safety* question, independent of how much
   engineering effort the change took or how much it looks like a "feature."

Reading "correctness only, never features or polish" as a single spectrum makes (1) swallow (2):
anything that isn't a narrow bug patch gets treated as risky, including changes that never touch
the import gate at all. The fix is to make the two axes explicit and separately answerable, not to
relax either one.

## What HIR-D7 should say

**Axis A — investment discipline, reframed around the tool's own purpose, not a category label.**
In scope: any change whose purpose is making the one-shot import correctly stage the approved
corpus — this explicitly includes extraction *accuracy* (model choice, reasoning effort, prompt
changes), and classification of what does or doesn't need operator review (e.g. reusing
`ServiceSectionType::requiresStructuralUncertaintyReview()`'s item-type distinction for review
flagging). Out of scope: anything that doesn't serve that purpose — generalising the command for
reuse beyond this import, cosmetic/structural cleanup, or building capability the corpus run
doesn't need.

**Axis B — unattended-import risk, unchanged from HIR-D6.** Any change to what the importer writes
without an operator — the 0.75/0.90 thresholds, `consensus` semantics, adjudication clearing the
gate, weakening a validator — still needs its own recorded, evidence-backed decision. This is not
relaxed by Axis A being clarified; if anything it's sharpened, because Axis A no longer has to do
double duty as an implicit safety brake.

**Worked examples**, so the next session doesn't have to re-derive the boundary:

| Change | Axis | In scope under HIR-D7? |
|---|---|---|
| Swap the extraction model or reasoning effort | A | Yes — sample first, since it's live production code (`OosEmailParserService` also serves `ProcessInboundOosEmail`), not archive-only |
| Add item-type-aware demotion of *review flags* (song/reading/sermon matter, notices/welcome don't) | A | Yes, for what gets flagged for review |
| The same item-type demotion applied to the *auto-import gate* | B | No — needs its own decision and corpus evidence |
| Let adjudication set `consensus` | B | No — HIR-D6 already refused this explicitly |
| Refactor the command's internal structure for readability | Neither (pure polish) | No |
| Add a report field that only describes what happened (Slice A shape) | A (or read-only per HIR-D6) | Yes |

## What was changed

Applied as these four edits (matching this project's own rule that widening happens by *adding* a
dated row, never editing HIR-D5's text):

### 1. `docs/plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md` — decision table

Add a row after the HIR-D6 row (§ line ~132):

```
| HIR-D7 | Does "correctness only" in HIR-D5 cover extraction-accuracy work (model/effort/classification), not just defect patches? | Split the exception into two axes: investment discipline (does this serve the import's own purpose — extraction accuracy is now explicit) and unattended-import risk (unchanged from HIR-D6). Neither axis is relaxed; they were previously conflated | Repeated misreading across sessions/agents declining accuracy work as "features" | **Proposed 2026-08-14, not yet decided — see §4.5** |
```

### 2. Same file — new `### 4.5` section after `### 4.4`

Insert the "The problem" and "What HIR-D7 should say" content above, dated and worded as a decided
outcome once accepted (drop "proposed"/"not yet decided" framing at that point).

### 3. Same file — §5 delivery map, item 7

Extend the existing HIR-D6 addition:

> 7. Resolve the do-not-invest contradiction through HIR-D5. The exception permits safety/
>    correctness only, not refactoring or new features. HIR-D6 (§4.4) adds read-only observability
>    and runs the exception to the production operation's closeout rather than to HIR8.
>    **HIR-D7 (§4.5) clarifies that extraction-accuracy work is investment-discipline-scoped, not
>    safety-scoped — it does not need HIR-D6's "read-only" qualifier and is evaluated only against
>    the import's own purpose.** Neither decision covers a change to what the importer imports
>    unattended.

### 4. `AGENTS.md` — the bounded-exception paragraph (currently line ~353)

Extend the existing sentence after the HIR-D6 addition:

> **HIR-D6 (2026-08-14, §4.4)** additionally permits read-only reporting needed to evidence the
> go/no-go, and runs the exception to the production operation's closeout rather than to HIR8.
> **HIR-D7 (2026-08-14, §4.5) clarifies that "correctness only" already covers extraction-accuracy
> work — model, reasoning effort, review classification — evaluated against whether it serves the
> import's own purpose, not against whether it resembles a "feature."** Neither decision covers a
> change to what the importer imports without an operator; that needs its own recorded decision.

## Why this is worth fixing now rather than living with the misreading

This is the second time (after HIR-D6 itself) that scope wording needed a correction, and the
correction was needed by two different coding agents independently, which is stronger evidence the
*wording* is the defect than either agent's individual read of it. Left uncorrected, it will keep
producing the same default-decline on real accuracy work — including the concrete next steps from
the [F64/F65 follow-up report](historic-import-f64-f65-parser-follow-up-2026-08-14.md) (model/
effort comparison, item-type-aware review classification) that this project actually wants done.
