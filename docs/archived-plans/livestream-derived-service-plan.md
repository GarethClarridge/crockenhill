# Livestream-Derived Church Service Plan

Archived on 2026-04-06.

## Status

This plan is no longer active. The core work proposed here has already landed in the codebase.

## What Was Delivered

- `livestream` is now a first-class `ChurchServiceItemSource`.
- livestream pipelines project service structure after `ClassifySpeechSections` and before `AlignWithOos`.
- livestream-only services can be created and refreshed from classified sections.
- services that already contain non-livestream items are protected from overwrite by reruns.
- high-confidence conflicts from later sources are staged for review instead of being silently applied.
- admins can resolve pending structure merges by accepting incoming items or keeping the current canonical list.
- merge matching now uses stable identity before position, which avoids false review prompts when songs are reordered.

## Key Implementation References

- `app/Enums/ChurchServiceItemSource.php`
- `app/Jobs/ProjectLivestreamServiceStructure.php`
- `app/Services/ProcessingPipelineBuilder.php`
- `app/Services/LivestreamChurchServiceProjectionService.php`
- `app/Services/LivestreamSectionToServiceItemMapper.php`
- `app/Services/ChurchServiceStructureMergeService.php`
- `app/Services/StructureMergePolicy.php`
- `app/Actions/ServiceReview/ResolvePendingStructureMerge.php`
- `app/Livewire/Admin/ChurchServices/ShowChurchService.php`

## Follow-Up Work That Belongs Elsewhere

- Schema snapshot hygiene is still active work because `database/schema/mysql-schema.sql` lags behind the live migrations.
- Any future operational hardening should be tracked with the relevant active service-review or simplification work, not by reopening this plan.
