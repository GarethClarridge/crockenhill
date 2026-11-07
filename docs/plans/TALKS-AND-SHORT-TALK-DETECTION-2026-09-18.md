# Talks — remaining acceptance and production work

**Reconciled from recorded evidence 2026-11-06.** PR1–PR4 were recorded landed on 09-23/24.
PR3's separate evaluation and PR5's re-detection were transferred to the historic video work on
09-24. This is the remaining worklist, not an instruction to repeat the implementation. No fresh
production verification or browser test was run during this documentation cleanup.

The complete design, migration details, September census and implementation journal are in the
[archived implementation record](../archived-plans/TALKS-IMPLEMENTATION-RECORD-2026-11-06.md).
Its “planned” code and proposed parallel PR sequence are historical. Current code and the
[rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md) take precedence.

## 2. Decisions

| Concern | Current authority |
|---|---|
| Talk storage and types | T1: retain `sermons` / `Sermon`; `sermon`, `childrens_talk`, `partner_update`, `testimony`. No catch-all or table/class rename. |
| Detection versus publication | Detect `short_talk`; the proposed talk type is not publication authority. T2 requires the reviewed type. |
| Presentation | One `/christ/talks` archive with type filtering; legacy archive/detail/feed redirects remain part of acceptance. |
| Audience | G10/G11 and programme §8.4 govern current access. The older design's per-type configuration is a mechanism, not permission to expose held content or services. |
| Review | G7/Q15: minimise manual review through supported recommendations, reusable decisions and avoiding repeated questions. Explicit type confirmation remains required. |
| Repair | G3/T6: repairs through the pipeline, no hand-retyping the historic bucket. G12's independent-output direction belongs to architecture AM8. |

## 5. Remaining delivery

### Remaining (2026-09-24)

These were not verified closed by the later records reviewed for this cleanup. Recheck their
current status before doing work; dated counts are not today's backlog.

- [ ] **Visual acceptance:** confirm whether Talks Playwright baselines were regenerated after
  the archive/navigation move. The September record says they were outstanding. Follow the design
  refresh's ownership; behaviour tests remain Dusk's responsibility.
- [ ] **Production actions:** verify the exposure-variable rename, podcast directory feed URLs,
  and Talks heading image through [PROD-ACTIONS-PENDING §§5–7](PROD-ACTIONS-PENDING.md#5-rename-the-childrens-talk-exposure-variable-talks-plan-pr1).
  That document owns the operational instructions and completion evidence. Confirm deployed
  migrations and restarted workers; do not rerun already-applied migrations manually.
- [ ] **Historic detection acceptance:** reconcile the former 191-section `other` bucket with
  current candidates/dispositions and the historic round's detection evidence. PR3 evaluation/PR5
  are not separate dispatch tasks. The original nine challenge runs and false-positive cases are
  preserved in [the archived remaining-work record](../archived-plans/TALKS-IMPLEMENTATION-RECORD-2026-11-06.md#remaining-2026-09-24).
- [ ] **Type/speaker review:** recount eligible candidates and reuse valid confirmations before
  presenting residual decisions. “151 pending” was a September local count, not an instruction
  to review 151 items now. Historic speaker automation remains disabled under P3.

## 6. Operator workflow after PR4

Detection supplies a proposed type, speaker evidence and boundaries. The operator confirms the
required type/speaker once against current evidence, then approves; publication uses that reviewed
type and the applicable access policy. A material change invalidates its dependent approval.
Candidate media and classification/approval signatures have distinct purposes: metadata-only
confirmation must not force a new media cut. Existing implementation details and tests are in
the archive; verify current behaviour rather than recreating the migrations.

## 7. Acceptance

Code acceptance 1/3/4/5 was recorded passed on 09-24. Visual acceptance and production checks above
remain unverified; detection evaluation and bucket reconciliation moved to the historic programme.
Retain regression protection for redirects, multiple talks from one service, per-type exposure,
missing-type refusal and re-approval after a reviewed type changes. Do not call the whole feature
production-verified from the old implementation status alone.

## 8. Ownership boundaries

The [video worklist](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md) owns current
extraction repairs and weekly readiness; the [audit](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md)
owns output/type/identity acceptance; programme §8 owns release. Design refresh owns broad template
consolidation and visual baselines. Independent output orchestration is architecture AM8. None of
these transfers authorises speaker retraining or early weekly resumption.

## 9. Evidence

[Implementation and dated acceptance record](../archived-plans/TALKS-IMPLEMENTATION-RECORD-2026-11-06.md).
Use it for rationale and exact old cohorts, never as a live census or execution checklist.
