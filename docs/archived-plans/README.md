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
