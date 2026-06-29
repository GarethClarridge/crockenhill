# Archived Documentation

This folder contains completed implementation plans and historical audit/refactoring reports that provide context and reference material.

## Completed Feature Plans

| Plan | Completed | Reference |
|------|-----------|-----------|
| **unified-pipeline-migration.md** | March 2026 | Unified media processing architecture with ProcessingRouter, video file preservation, and consistent API responses |
| **service-record-unified-view-plan.md** | March 2026 | Admin timeline view merging planned service items with detected livestream sections |
| **livestream-manual-review-implementation-plan.md** | March 2026 | Admin workflow for manually confirming sermon segments when auto-detection is ambiguous |
| **openlp-service-upload-plan.md** | March 2026 | API endpoint and parser for `.osz` OpenLP file uploads |
| **api-bible-sermon-text-plan.md** | March 2026 | Integration with api.bible for automatic Bible text enrichment on sermons |
| **childrens-talk-identification-patch-plan.md** | March 2026 | Improved heuristics for childrens talk classification in OoS alignment |
| **email-text-upload-plan.md** | March 2026 | Admin UI for manual email text submission (paste-and-process) |
| **local-whisper-implementation-plan.md** | March 2026 | Local Whisper transcription support for development without OpenAI transcription costs |
| **plan-livestream-improvements.md** | March 2026 | Children's talk detection, transcript-informed song matching, and admin service flow view |
| **plan-livestream-processing-fixes.md** | March 2026 | Livestream section ordering, song OCR matching, childrens-talk fixes, and intro/outro reclassification |
| **plan-merge-adjacent-sections.md** | March 2026 | Adjacent same-type section merging in processing and service review |
| **SIMPLIFICATION-PLAN-2026-02-25.md** | April 2026 | Historical execution log for the multi-phase simplification plan after completed phases were split out |
| **song-video-feature-implementation-plan.md** | April 2026 | Song video publication, public display, and admin management |
| **test-coverage-improvement-plan.md** | March 2026 | Full controller, job, service, Livewire, and DTO test coverage plan completion |
| **media-upload-combined-report-and-plan.md** | April 2026 | Historical combined audit and phased media-upload refactor plan after completed context was split out |
| **livestream-derived-service-plan.md** | April 2026 | Livestream-first church service projection, staged merge review, and identity-first matching plan after delivery |
| **SERVICE-UI-CONSOLIDATION-2026-06-10.md** | June 2026 | Service/livestream admin IA refactor consolidating 13 surfaces into 6 (services hub, workbench, review inbox); retired queue pages 302 to inbox filters |
| **SERVICE-DETECTION-IMPROVEMENTS-2026-06-20.md** | June 2026 | Reading-reference resolution (`ResolveReadingReferences`), media-interlude detection (`MediaInterludeCueDetector`), and transition tagging; correctness follow-ups continue in the active `SERMON-SECTION-EXTRACTION-REMAINING-FIXES-2026-06-21.md` |
| **TESTING-REMEDIATION-PLAN.md** | June 2026 | Suite runtime/flakiness remediation (R1–R9 → T1–T9): removed Pwned Passwords + DigitalOcean Spaces + stray-HTTP network calls from tests, re-levelled SEO assertions to presenters, standardised the per-directory DB trait, and cut PHPUnit notices/deprecations |
| **JUNE-2026-REVIEW-IMPLEMENTATION-2026-06-03.md** | June 2026 | Project-wide review execution: Phase 0 correctness fixes, structural R-track moves (namespacing, DTOs, route-name standardisation), and package adoptions (Horizon, spatie/laravel-backup, spatie/laravel-health). One residual staging smoke test split out to `docs/operations/horizon-staging-smoke-test.md` |

## Historical Audits & Reports

| Report | Date | Purpose |
|--------|------|---------|
| **AUDIT-2026-02-25.md** | Feb 25, 2026 | Architecture & code quality audit; N+1 fixes, optimization improvements |
| **REFACTORING-REPORT.md** | Feb 11, 2026 | Comprehensive refactoring completion report with Laravel 12 compliance assessment |
| **Codebase Review - February 2026.md** | Feb 11, 2026 | Fresh codebase review against Laravel 12/PHP 8.4 standards and modern patterns |
| **CODEX-RESTART-NOTES-2026-02-25.md** | Feb 25, 2026 | Simplification findings and priorities from architectural analysis |
| **MEDIA-UPLOAD-REFACTORING.md** | Feb 16, 2026 | Deep-dive into media upload system architecture and refactoring opportunities |

## Purpose

These documents serve as:
- **Implementation references** for understanding design decisions and feature rationale
- **Audit trail** of architectural changes, quality gates, and improvements
- **Learning material** for onboarding developers new to the codebase
- **Historical context** for understanding why components are structured as they are

## How to Use

- **Feature implementation**: Consult the relevant feature plan to understand the design approach, implementation phases, testing strategy, and edge cases.
- **Code quality context**: Review audit and refactoring reports to understand what optimizations were performed and their justification.
- **Troubleshooting**: If investigating a particular subsystem, check if there's a related archived plan or audit that explains the design rationale.

**Do not modify these files**—they are snapshots of completed work and serve as historical reference material.
