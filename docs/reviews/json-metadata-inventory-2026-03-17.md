# JSON Metadata Inventory

Date: 2026-03-17

## Scope

This inventory covers the current JSON metadata and analysis blobs used by:

- `sermons`
- `church_services`
- `church_service_items`
- `service_sections`
- `media_processing_logs`
- `livestream_segments`

It focuses on runtime write/read paths in `app/`, then calls out legacy or test-only shapes separately. It does not fully inventory unrelated JSON columns such as `songs.import_metadata`, `inbound_emails.processing_metadata`, or vendor/media-library tables.

Those excluded columns are adjacent but outside this pass because they belong to song-catalog and inbox/intake workflows rather than the sermon/service/media-processing lifecycle traced here. They deserve a separate inventory instead of being mixed into this one.

## Recommendation legend

- `JSON`: keep as raw JSON
- `DTO/VO`: keep in JSON storage, but wrap in a typed DTO/value object or custom cast
- `Schema`: promote to first-class columns/relations and stop treating it as ad hoc metadata

## Column summary

| JSON field | Purpose | Recommended direction |
| --- | --- | --- |
| `sermons.thumbnail_metadata` | Thumbnail generation output and asset paths | `DTO/VO` |
| `church_services.import_metadata` | Import provenance plus review/conflict state | Split: provenance `DTO/VO`, review/conflict state `Schema` |
| `church_service_items.metadata` | Parsed item hints and song-link hints | Split: `section_type` -> `Schema`, rest mostly `DTO/VO`/`JSON` |
| `service_sections.source_segment_ids` | Lineage from detected livestream segments | `JSON` |
| `service_sections.metadata` | Classification, OoS alignment, review state, publication state | Mostly `DTO/VO`, with a few strong `Schema` candidates |
| `media_processing_logs.ai_analysis` | Structured sermon analysis result | `DTO/VO` |
| `media_processing_logs.processing_metadata` | Ingestion provenance, manual-review state, temp paths, pipeline context | Split: business state `DTO/VO` or `Schema`, telemetry/temp paths `JSON` |
| `media_processing_logs.rms_stats` | Thresholding telemetry | `JSON` |
| `media_processing_logs.visual_samples` | Visual analysis telemetry | `JSON` |
| `media_processing_logs.song_clusters` | Visual song-cluster artifacts used downstream | `DTO/VO` |
| `livestream_segments.metadata` | Segment-boundary provenance | Split: duplicated promoted fields -> `Schema`, remainder `JSON` |

## `sermons.thumbnail_metadata`

Canonical writer: `app/Services/ThumbnailGenerationService.php::generateThumbnail()`, persisted by `app/Jobs/GenerateThumbnail.php::handle()`

Main readers: `app/Models/Sermon.php::getPlainThumbnailFilePathAttribute()`, `app/Http/Resources/SermonResource.php`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `timestamp` | `ThumbnailGenerationService::generateThumbnail()` | Exposed via `SermonResource` | Audit/debug only | `DTO/VO` |
| `video_duration` | `ThumbnailGenerationService::generateThumbnail()` | Exposed via `SermonResource` | Audit/debug only | `DTO/VO` |
| `video_resolution.{width,height}` | `ThumbnailGenerationService::generateThumbnail()` | Exposed via `SermonResource` | Audit/debug only | `DTO/VO` |
| `thumbnail_sizes.web.{width,height,quality}` | `ThumbnailGenerationService::generateThumbnail()` | Exposed via `SermonResource` | Audit/debug only | `DTO/VO` |
| `generated_at` | `ThumbnailGenerationService::generateThumbnail()` | Exposed via `SermonResource` | Audit/debug only | `DTO/VO` |
| `plain_thumbnail_path` | `ThumbnailGenerationService::generateThumbnail()` | `Sermon::getPlainThumbnailFilePathAttribute()` | Determines whether the plain thumbnail asset can be served | `DTO/VO` |
| `overlay_thumbnail_path` | `ThumbnailGenerationService::generateThumbnail()` | Exposed via `SermonResource` | Determines where the branded variant lives | `DTO/VO` |

Notes:

- The only key that currently changes runtime behavior directly is `plain_thumbnail_path`.
- The current production writer does not emit the older flat/test-only keys listed in the appendix.

## `church_services.import_metadata`

Canonical writers:

- `app/Services/OosEmailParserService.php::parse()`
- `app/Services/OpenLpServiceParser.php::parse()`
- `app/Services/InboundEmailImportService.php::import()`
- `app/Livewire/Admin/ChurchServices/ManageChurchService.php::save()`
- `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php::markServiceReviewed()`
- `app/Services/ChurchServiceCanonicalUpdateService.php::finalize()`
- `app/Services/OosAlignmentService.php::syncChurchServiceReviewState()`

Main readers:

- `app/Services/OosAlignmentService.php::hasImportReviewSignal()`
- `app/Livewire/Admin/ChurchServices/ShowChurchService.php::render()`
- `app/Services/ChurchServiceCanonicalUpdateService.php::finalize()`
- `app/Services/ChurchServiceReviewStateService.php`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `confidence_score` | `OosEmailParserService`, `OpenLpServiceParser` | `OosAlignmentService::hasImportReviewSignal()`, `ShowChurchService::render()` | Keeps `church_services.needs_review` open for medium-confidence imports | `DTO/VO` |
| `parse_method` | `OosEmailParserService`, `OpenLpServiceParser` | Displayed through `import_metadata` consumers | Explains provenance only | `DTO/VO` |
| `warnings[]` | `OosEmailParserService`, `OpenLpServiceParser` | `ShowChurchService::render()` | Operator review context only | `DTO/VO` |
| `date_extraction.{value,confidence,method}` | `OosEmailParserService` | Displayed through `import_metadata` consumers | Audit/provenance only | `DTO/VO` |
| `service_extraction.{value,confidence,method}` | `OosEmailParserService` | Displayed through `import_metadata` consumers | Audit/provenance only | `DTO/VO` |
| `item_extraction.{confidence,item_count,notes[]}` | `OosEmailParserService` | Displayed through `import_metadata` consumers | Audit/provenance only | `DTO/VO` |
| `source_message_id` | `OosEmailParserService` | Displayed through `import_metadata` consumers | Audit/provenance only | `DTO/VO` |
| `source_subject` | `OosEmailParserService` | Displayed through `import_metadata` consumers | Audit/provenance only | `DTO/VO` |
| `filename_mismatch` | `OpenLpServiceParser` | Displayed through `import_metadata` consumers | Review hint only | `DTO/VO` |
| `upload_filename` | `OpenLpServiceParser` | Displayed through `import_metadata` consumers | Provenance only | `DTO/VO` |
| `embedded_filename` | `OpenLpServiceParser` | Displayed through `import_metadata` consumers | Provenance only | `DTO/VO` |
| `upload_identity.{date,service,slot_known}` | `OpenLpServiceParser` | Displayed through `import_metadata` consumers | Provenance only | `DTO/VO` |
| `embedded_identity.{date,service,slot_known}` | `OpenLpServiceParser` | Displayed through `import_metadata` consumers | Provenance only | `DTO/VO` |
| `admin_review.{approved_at,approved_by_user_id,mode}` | `InboundEmailImportService::import()` | No strong production reader beyond serialized metadata | Audit of direct/admin import approval | `DTO/VO` |
| `manual_edit.{saved_at,saved_by_user_id,item_count}` | `ManageChurchService::save()` | No strong production reader beyond serialized metadata | Audit of manual edits | `JSON` |
| `manual_review.{reviewed_at,reviewed_by_user_id}` | `ServiceReviewDashboard::markServiceReviewed()` | `ChurchServiceCanonicalUpdateService::finalize()`, `ChurchServiceReviewStateService::hasOutstandingCanonicalConflict()` | Determines whether later changes reopen review and whether review is now complete | `Schema` |
| `manual_review.{reopened_at,reopened_by_source}` | `ChurchServiceCanonicalUpdateService::finalize()` | Serialized metadata only today | Audit trail when a reviewed service is reopened | `Schema` |
| `canonical_conflict.{detected_at,incoming_source,review_reopened,reviewed_previously,canonical_changed,changes[],conflicts[]}` | `ChurchServiceCanonicalUpdateService::finalize()` | `ChurchServiceReviewStateService::hasOutstandingCanonicalConflict()` | Keeps service review open after canonical drift or sync conflicts | `Schema` |
| `canonical_conflict_history[]` | `ChurchServiceReviewStateService::withRecordedCanonicalConflict()` | Display/tests/history | Append-only audit of prior conflicts | `JSON` |
| `review_triggers[]` | `OosAlignmentService::syncChurchServiceReviewState()` | Serialized metadata and tests | Explains why review remains open after alignment | `JSON` |

Notes:

- `confidence_score` is already partly projected into `church_services.needs_review`; if the system starts querying/reporting confidence directly, it becomes a stronger schema candidate.
- `manual_review.*` and `canonical_conflict.*` are not just provenance; they are workflow state.

## `church_service_items.metadata`

Canonical writers:

- `app/Livewire/Admin/ChurchServices/ManageChurchService.php::buildSyncPayload()`
- `app/Services/OosEmailParserService.php::normaliseItems()`
- `app/Services/OpenLpServiceParser.php::extractItems()`

Main readers:

- `ManageChurchService::resolveSectionTypeFromParsedItem()`
- `ManageChurchService::resolveSectionType()`
- `app/Services/OosAlignmentService.php::resolvedItemType()`
- `app/Services/OosAlignmentService.php::makePresentationDecision()`
- `app/Services/ChurchServiceSongLinker.php::resolveSearchTitle()`
- `app/Services/ChurchServiceCanonicalStateService.php`
- `app/Services/ChurchServiceItemSyncService.php`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `section_type` | `ManageChurchService::buildSyncPayload()` | `ManageChurchService`, `OosAlignmentService` | Drives structural item classification and presentation-item resolution | `Schema` |
| `linked_song_canonical_key` | `ManageChurchService::buildSyncPayload()` | `ChurchServiceSongLinker::resolveSearchTitle()` | Changes how songs are matched/resolved without relying on raw title text | `DTO/VO` |
| `email_type` | `OosEmailParserService::normaliseItems()` | `ManageChurchService::resolveSectionTypeFromParsedItem()` | Seeds manual-editor section type for email-derived custom items | `DTO/VO` |
| `theme` | `OpenLpServiceParser::extractItems()` | Canonical diff/sync only | Provenance only | `JSON` |
| `authors` | `OpenLpServiceParser::extractItems()` | Canonical diff/sync only | Provenance only | `JSON` |

Notes:

- `section_type` is the clearest normalization candidate in this table because it already behaves like domain state rather than provenance.

## `service_sections.source_segment_ids`

Canonical writers:

- `app/Services/ServiceSectionClassifier.php::classify()`
- `app/Jobs/ClassifySpeechSections.php`
- persisted by `app/Services/ServiceSectionSyncService.php::sync()`

Main readers:

- `app/Jobs/ClassifySpeechSections.php` merge and fold logic

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `source_segment_ids[]` | `ServiceSectionClassifier`, `ClassifySpeechSections` | `ClassifySpeechSections` | Preserves lineage back to original livestream segments when sections are split/merged | `JSON` |

Notes:

- This is good JSON: ordered lineage, not stable relational state.

## `service_sections.metadata`

### Classification and review keys

Canonical writers:

- `app/Services/ServiceSectionClassifier.php::classify()`
- `app/Services/SpeechSectionClassificationService.php::classifySection()`
- `app/Jobs/TranscribeSpeechSegments.php`
- `app/Jobs/ClassifySpeechSections.php`
- `app/Services/OosAlignmentService.php`
- `app/Services/ServiceSectionReviewTriggerEvaluator.php`
- `app/Services/ChildrensTalkSpeakerService.php`
- `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php::saveSectionChanges()`
- `app/Livewire/Admin/ChurchServices/Concerns/ManagesSectionPublication.php::approveSectionForPublication()`
- `app/Services/ServiceSectionSyncService.php`

Main readers:

- `app/Support/ServiceSectionConfidence.php`
- `app/Jobs/PrepareSectionPublicationCandidates.php`
- `app/Support/ServiceRecordTimeline.php`
- `app/Services/ServiceSectionReviewTriggerEvaluator.php`
- `app/Jobs/PublishApprovedServiceSection.php`
- `app/Models/ServiceSection.php`
- `app/Services/PublicSongUsageService.php`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `confidence_level` | `ServiceSectionClassifier`, `SpeechSectionClassificationService`, `OosAlignmentService::persistConfidenceLevel()` | `ServiceSectionConfidence`, `ServiceRecordTimeline` | Explains reviewability and timeline state; numeric confidence remains authoritative | `DTO/VO` |
| `classification_mode` | `ServiceSectionClassifier`, `SpeechSectionClassificationService` | Serialized metadata; legacy alignment reset path | Provenance only | `DTO/VO` |
| `detected_segment_class` | `ServiceSectionClassifier` | No strong production reader found | Provenance only | `JSON` |
| `sermon_detection_strategy` | `ServiceSectionClassifier` | No strong production reader found | Provenance only | `JSON` |
| `confidence_source` | `SpeechSectionClassificationService`, `ClassifySpeechSections::fallbackPayload()` | Serialized metadata | Provenance only | `DTO/VO` |
| `confidence_score` | `SpeechSectionClassificationService`, `OosAlignmentService::persistConfidenceLevel()` | `ServiceSectionConfidence`, `PrepareSectionPublicationCandidates`, `ServiceRecordTimeline` | Reviewability and publication-prep decisions | `DTO/VO` |
| `review_reason` | `ServiceSectionClassifier`, `SpeechSectionClassificationService`, `ClassifySpeechSections`, `OosAlignmentService`, `ServiceSectionReviewTriggerEvaluator`, `ChildrensTalkSpeakerService` | `ServiceSectionReviewTriggerEvaluator`, `ServiceRecordTimeline`, review dashboard | Explains why a section requires review and influences review-trigger evaluation | `DTO/VO` |
| `review_flags[]` | `OosAlignmentService`, `ServiceSectionReviewTriggerEvaluator`, `ChildrensTalkSpeakerService` | Review dashboard/timeline helpers | Tracks multiple concurrent review causes | `DTO/VO` |
| `ai_requested_section_type` | `SpeechSectionClassificationService` | Serialized metadata | Provenance for transcript classifier output | `DTO/VO` |
| `ai_notes[]` | `SpeechSectionClassificationService` | Serialized metadata | Operator/debug context | `DTO/VO` |
| `ai_anomalies[]` | `SpeechSectionClassificationService` | Serialized metadata | Operator/debug context | `DTO/VO` |
| `transcript` | `TranscribeSpeechSegments`, `SpeechSectionClassificationService` | `ClassifySpeechSections::shouldClassify()` | Gates whether speech sections can be AI-reclassified | `DTO/VO` |
| `transcript_scope` | `SpeechSectionClassificationService` | Serialized metadata | Provenance only | `DTO/VO` |
| `parent_transcript_available` | `SpeechSectionClassificationService` | Serialized metadata | Provenance only | `DTO/VO` |
| `source_service_section_id` | `SpeechSectionClassificationService` | Serialized metadata | Provenance only | `DTO/VO` |
| `relative_start_seconds` | `SpeechSectionClassificationService` | Serialized metadata | Provenance only | `DTO/VO` |
| `relative_end_seconds` | `SpeechSectionClassificationService` | Serialized metadata | Provenance only | `DTO/VO` |
| `derived_from_section_type` | `ClassifySpeechSections::payloadFromClassifiedSection()` | Serialized metadata | Provenance for section splits | `DTO/VO` |
| `folded_song_sections[]` | `ClassifySpeechSections::foldShortSongsIntoSermon()` | Serialized metadata | Explains merged sermon ranges | `JSON` |
| `folded_song_duration_seconds` | `ClassifySpeechSections::foldShortSongsIntoSermon()` | Serialized metadata | Explains merged sermon ranges | `JSON` |
| `song_id` | `OosAlignmentService::alignSongs()` | `ServiceSectionReviewTriggerEvaluator`, `OosAlignmentService::songMatchScore()` | Carries confirmed song linkage into review logic | `DTO/VO` |
| `reading_reference` | `OosAlignmentService::alignStructuralSections()` | `ServiceSectionReviewTriggerEvaluator`, `ServiceRecordTimeline` | Supplies Bible-reading reference for review/timeline output | `DTO/VO` |

### `oos_alignment` nested keys

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `oos_alignment.song_match_type` | `OosAlignmentService`, `ServiceSectionReviewTriggerEvaluator` | `ServiceSection::songMatchType()`, `PublicSongUsageService`, `ServiceSectionReviewTriggerEvaluator`, timeline helpers | Drives public song usage counting and section review state, and is currently queried through a production JSON `WHERE` path | `Schema` |
| `oos_alignment.song_match_score` | `OosAlignmentService::alignSongs()` | Serialized metadata | Review/debug context | `DTO/VO` |
| `oos_alignment.song_match_strategy` | `OosAlignmentService::alignSongs()`, `applyInferredSongItem()` | Serialized metadata | Review/debug context | `DTO/VO` |
| `oos_alignment.song_title_matched` | `OosAlignmentService::alignSongs()`, `applyInferredSongItem()` | `OosAlignmentService::songCandidatesFromSection()` | Helps infer canonical song candidates | `DTO/VO` |
| `oos_alignment.reclassified_from` | `OosAlignmentService::alignStructuralSections()` | Serialized metadata | Audit of OoS-driven type changes | `DTO/VO` |
| `oos_alignment.reclassified_by` | `OosAlignmentService::alignStructuralSections()` | Serialized metadata | Audit of OoS-driven type changes | `DTO/VO` |
| `oos_alignment.presentation_inference.{resolved_type,suspected_type,evidence,reason}` | `OosAlignmentService::applyPresentationDecisionMetadata()` | Serialized metadata and tests | Explains weak/strong presentation-item inference | `DTO/VO` |
| `oos_alignment.matched_item_id` | `OosAlignmentService::applyMatchedItem()`, `applyInferredSongItem()` | Timeline/build-row helpers | Links detected section back to planned item | `Schema` |
| `oos_alignment.matched_item_type` | `OosAlignmentService::applyMatchedItem()`, `applyInferredSongItem()` | Timeline/build-row helpers | Human-readable planned-item context | `DTO/VO` |
| `oos_alignment.matched_item_title` | `OosAlignmentService::applyMatchedItem()`, `applyInferredSongItem()` | `OosAlignmentService::songCandidatesFromSection()`, timeline helpers | Human-readable planned-item context | `DTO/VO` |
| `oos_alignment.mismatch_reason` | `OosAlignmentService::markMismatch()` | `ServiceRecordTimeline` | Explains why a section diverged from OoS structure | `DTO/VO` |
| `oos_alignment.expected_item_id` | `OosAlignmentService::markMismatch()` | Timeline helpers | Captures the planned item that should have matched | `Schema` |
| `oos_alignment.expected_item_title` | `OosAlignmentService::markMismatch()` | Timeline helpers | Human-readable mismatch context | `DTO/VO` |
| `oos_alignment.expected_section_type` | `OosAlignmentService::markMismatch()` | Timeline helpers | Human-readable mismatch context | `DTO/VO` |
| `oos_alignment.base_confidence` | `OosAlignmentService::baseAlignmentMetadata()` | `OosAlignmentService::prepareSectionForAlignment()` | Allows alignment reruns to restore pre-alignment confidence | `DTO/VO` |
| `oos_alignment.base_needs_manual_review` | `OosAlignmentService::baseAlignmentMetadata()` | `OosAlignmentService::prepareSectionForAlignment()` | Allows alignment reruns to restore review state | `DTO/VO` |
| `oos_alignment.base_title` | `OosAlignmentService::baseAlignmentMetadata()` | `OosAlignmentService::prepareSectionForAlignment()` | Allows alignment reruns to restore pre-alignment title | `DTO/VO` |
| `oos_alignment.base_church_service_item_id` | `OosAlignmentService::baseAlignmentMetadata()` | `OosAlignmentService::prepareSectionForAlignment()` | Allows alignment reruns to restore pre-alignment linkage | `DTO/VO` |

### `childrens_talk_speaker` nested keys

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `childrens_talk_speaker.predicted.{outcome,reason,preacher_id,preacher_name,confidence,second_confidence,margin,matched_profile_id,source,decided_at}` | `ChildrensTalkSpeakerService::detectAndStore()` | `ServiceSection` helper methods, review UI/tests | Determines whether children’s talk speaker auto-resolution succeeded or needs review | `DTO/VO` |
| `childrens_talk_speaker.reviewed.{preacher_id,preacher_name,source,confidence,review_mode,reviewed_at,reviewed_by_user_id}` | `ChildrensTalkSpeakerService::detectAndStore()`, `storeManualReview()` | `ServiceSection::publicationChildrensTalkSpeaker()`, publication-prep flow | Governs whether a children’s talk can be published and which preacher gets attached | `DTO/VO` |

### Publication, manual-review, and supersede keys

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `publication.approved_signature` | `ManagesSectionPublication::approveSectionForPublication()` | `PublishApprovedServiceSection` | Blocks publication if the section changed after approval | `DTO/VO` |
| `publication.approved_at` | `ManagesSectionPublication::approveSectionForPublication()` | Serialized metadata | Audit of approval timing | `DTO/VO` |
| `publication.batch_approvals[].{batch_id,approved_at,approved_by_user_id,source,action,church_service_id}` | `ManagesSectionPublication::approveSectionForPublication()` | Review dashboard/tests | Append-only audit history for batch approvals | `JSON` |
| `manual_review.{updated_at,updated_by_user_id}` | `ServiceReviewDashboard::saveSectionChanges()` | Serialized metadata | Audit of manual section edits | `JSON` |
| `superseded.{at,previous_signature,next_signature,previous_published_sermon_id}` | `ServiceSectionSyncService::supersededReplacementPayload()` | Serialized metadata/tests | Explains why a previously published candidate was invalidated | `JSON` |
| `publishable_type_after_supersede` | `ServiceSectionSyncService::supersededReplacementPayload()` | Serialized metadata/tests | Audit of whether the replacement section type remained publishable | `JSON` |

## `media_processing_logs.ai_analysis`

Canonical writer: `app/Jobs/ProcessTranscriptWithAI.php::handle()`

Main readers:

- `app/Jobs/CreateSermonRecord.php::handle()`
- `app/Services/SermonCreationService.php`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `title` | `ProcessTranscriptWithAI::handle()` | `CreateSermonRecord`, `SermonCreationService::generateTitleAiWithFallback()` | Determines sermon title when ID3 title is missing or filename-like | `DTO/VO` |
| `series` | `ProcessTranscriptWithAI::handle()` | `CreateSermonRecord`, `SermonCreationService::createSermon()` | Populates sermon series when ID3 is absent | `DTO/VO` |
| `reference` | `ProcessTranscriptWithAI::handle()` | `CreateSermonRecord`, `SermonCreationService::createSermon()` | Populates sermon reference and triggers scripture re-enrichment when changed | `DTO/VO` |
| `points[]` | `ProcessTranscriptWithAI::handle()` | `CreateSermonRecord`, `SermonCreationService::createSermon()` | Populates sermon outline points | `DTO/VO` |
| `summary` | `ProcessTranscriptWithAI::handle()` | `ProcessTranscriptWithAI::handle()` sermon update path | Populates sermon summary | `DTO/VO` |
| `transcript` | `ProcessTranscriptWithAI::handle()` | `SermonAnalysis` validation/fallback flows | Ensures the AI result is usable | `DTO/VO` |

Notes:

- This blob already has a natural type: `App\Data\SermonAnalysis`.

## `media_processing_logs.processing_metadata`

### Identity, ID3, and ingestion keys

Canonical writers:

- `app/Services/ProcessingInitiator.php::initiateProcessing()`
- `app/Services/UnifiedMediaProcessor.php::processAudio()`
- `app/Services/LivestreamSegmentationService.php::processLivestream()`
- `app/Jobs/SubmitToProcessing.php::handle()`
- `app/Jobs/GenerateThumbnail.php::resolveVideoPath()`
- `app/Services/ChurchServiceReconciliationDispatcher.php::recordTriggerContext()`

Main readers:

- `app/Services/MediaProcessingIdentityResolver.php`
- `app/Console/Commands/BackfillMediaProcessingIdentityCommand.php`
- `app/Services/SermonCreationService.php`
- `app/Jobs/CreateSermonRecord.php`
- `app/Jobs/ProcessTranscriptWithAI.php`
- `app/Jobs/GenerateThumbnail.php`
- `app/Services/LivestreamSegmentationService.php`
- `app/Services/SermonJobPipelineService.php`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `extracted_date` | `ProcessingInitiator::initiateProcessing()` | `MediaProcessingIdentityResolver`, `BackfillMediaProcessingIdentityCommand`, `SermonCreationService::extractDate()` | Determines resolved sermon/service identity when columns are missing | `Schema` |
| `extracted_datetime` | `ProcessingInitiator::initiateProcessing()` | No strong production reader found | Provenance only | `JSON` |
| `extracted_service` | `ProcessingInitiator::initiateProcessing()` | `MediaProcessingIdentityResolver`, `BackfillMediaProcessingIdentityCommand`, `SermonCreationService::extractServiceType()` | Determines resolved service slot when columns are missing | `Schema` |
| `date_extraction_method` | `ProcessingInitiator::initiateProcessing()` | `SermonCreationService::extractDate()` logging | Audit/provenance only | `DTO/VO` |
| `service_extraction_method` | `ProcessingInitiator::initiateProcessing()` | `SermonCreationService::extractServiceType()` logging | Audit/provenance only | `DTO/VO` |
| `id3_metadata.{title,preacher,series,reference}` | `UnifiedMediaProcessor::processAudio()` | `CreateSermonRecord`, `ProcessTranscriptWithAI`, `SermonCreationService` | ID3 fields override AI for sermon creation/update; `preacher` also suppresses later speaker-identification reassignment by setting preacher source to ID3 | `DTO/VO` |
| `upload_time` | `LivestreamSegmentationService::processLivestream()` | Serialized metadata | Provenance only | `JSON` |
| `format_details` | `LivestreamSegmentationService::processLivestream()` | Serialized metadata only | Opaque FFprobe/blob provenance | `JSON` |
| `mime_type` | `LivestreamSegmentationService::processLivestream()` | Serialized metadata only | Provenance only | `JSON` |
| `file_format` | `LivestreamSegmentationService::processLivestream()` | `LivestreamSegmentationService::buildProcessingResult()` | Returned in processing-result payloads | `JSON` |
| `source_type` | Alternate/test paths such as `ProcessVideoCommand` and callers that seed `SermonJobPipelineService` | `SermonJobPipelineService` | Chooses alternate job-pipeline behavior for legacy/video-upload style flows | `Schema` |
| `livestream_processing_id` | `SubmitToProcessing::handle()` metadata for `SermonCreationOptions::fromLivestream()` | `SermonCreationOptions::fromLivestream()` | Carries log identity into livestream sermon creation | `Schema` |
| `original_filename` | `SubmitToProcessing::handle()` metadata for `SermonCreationOptions::fromLivestream()` | `SermonCreationOptions::fromLivestream()` | Carries filename into livestream sermon creation | `Schema` |
| `segment_start_time` | `SubmitToProcessing::handle()` metadata for `SermonCreationOptions::fromLivestream()` | `SermonCreationOptions::fromLivestream()` | Carries sermon segment bounds into sermon creation | `Schema` |
| `segment_end_time` | `SubmitToProcessing::handle()` metadata for `SermonCreationOptions::fromLivestream()` | `SermonCreationOptions::fromLivestream()` | Carries sermon segment bounds into sermon creation | `Schema` |
| `video_file_path` | Alternate pipeline paths including `SubmitToProcessing::handle()` metadata | `SermonCreationOptions::fromLivestream()` | Carries source video into livestream sermon creation | `Schema` |
| `final_video_path` | `SubmitToProcessing::handle()` | `GenerateThumbnail::resolveVideoPath()` | Thumbnail generation fallback when direct video path is unavailable | `Schema` |
| `sermon_creation_completed_at` | `SubmitToProcessing::handle()` | Serialized metadata | Audit only | `JSON` |
| `visual_analysis_progress` | `PerformVisualAnalysis` | Serialized metadata | Progress reporting only | `JSON` |
| `extracted_segment_path` | Livestream extraction/cleanup flows | `LivestreamSegmentationService::cancelProcessing()`, failure cleanup, `CleanupTemporaryFiles` | Temporary-file cleanup only | `JSON` |
| `extracted_audio_path` | Livestream extraction/cleanup flows | `LivestreamSegmentationService::cancelProcessing()`, failure cleanup, `CleanupTemporaryFiles` | Temporary-file cleanup only | `JSON` |
| `temp_video_path` | Livestream extraction/cleanup flows | `LivestreamSegmentationService::cancelProcessing()`, failure cleanup, `CleanupTemporaryFiles` | Temporary-file cleanup only | `JSON` |
| `reconciliation_triggers[].triggered_at` | `ChurchServiceReconciliationDispatcher::recordTriggerContext()` | Serialized metadata/tests | Audit of why reconciliation reran | `JSON` |
| `reconciliation_triggers[].*` | `ChurchServiceReconciliationDispatcher::recordTriggerContext()` | Serialized metadata/tests | Dynamic trigger context; commonly `event` and `source` | `JSON` |

### Audio compression, manual review, and speaker identification

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `audio_compression.{original_size_mb,final_size_mb,compression_applied,compression_ratio,valid_for_transcription}` | `ExtractSermon`, `ExtractAudioFromVideo` | Serialized metadata/tests | Operational visibility; no major branching beyond status/context | `JSON` |
| `manual_review.status` | `MediaProcessingLog::markForManualReview()`, `confirmSermonSegment()` | `MediaProcessingLog::requiresManualSermonReview()` | Determines whether a livestream run is awaiting manual sermon review or has been confirmed | `DTO/VO` |
| `manual_review.reason_code` | `MediaProcessingLog::markForManualReview()` | `MediaProcessingLog::scopeAwaitingManualSermonReview()`, helpers, review UI | Determines which failures count as sermon-review work | `DTO/VO` |
| `manual_review.reason_message` | `MediaProcessingLog::markForManualReview()` | Review UI/helpers | Operator-facing review explanation | `DTO/VO` |
| `manual_review.flagged_at` | `MediaProcessingLog::markForManualReview()` | Helpers/UI | Audit of review timing | `DTO/VO` |
| `manual_review.speech_segments[].{segment_id,start_time,end_time,duration}` | `MediaProcessingLog::markForManualReview()`, `SermonCandidateConfidenceService` payload | Processing review UI, manual confirmation flows | Lets admins choose the correct sermon segment | `DTO/VO` |
| `manual_review.confirmed_segment_id` | `MediaProcessingLog::confirmSermonSegment()` | `MediaProcessingLog::manuallyConfirmedSegmentId()`, `ConfirmLivestreamSermonSegment` | Determines which segment restarts extraction | `DTO/VO` |
| `manual_review.confirmed_by_user_id` | `MediaProcessingLog::confirmSermonSegment()` | Serialized metadata/helpers | Audit of confirmation actor | `DTO/VO` |
| `manual_review.confirmed_at` | `MediaProcessingLog::confirmSermonSegment()` | Serialized metadata/helpers | Audit of confirmation time | `DTO/VO` |
| `speaker_identification.{outcome,reason,decided_at}` | `IdentifySpeaker::storeDecision()` | Serialized metadata/tests | Records whether speaker identification matched, failed, or was skipped | `DTO/VO` |
| `speaker_identification.{matched,errored,matched_profile_id,matched_preacher_id,matched_preacher_name,top_score,second_score,margin}` | `IdentifySpeaker::storeDecision()` via `SpeakerMatchResult::toLogArray()` | Serialized metadata/tests | Audit/debug detail for the identification outcome | `DTO/VO` |

Notes:

- `extracted_date` and `extracted_service` are already duplicated in dedicated columns on `media_processing_logs`; the metadata copies are legacy compatibility state.
- `format_details` is intentionally treated as an opaque external blob because the application is not reading nested keys from it today.

## `media_processing_logs.rms_stats`

Canonical writer: `app/Services/RmsAnalysisService.php`, persisted by `app/Jobs/AnalyzeSegments.php::storeThresholdMetadata()`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `sample_count` | `RmsAnalysisService` | Serialized metadata/tests | Telemetry only | `JSON` |
| `min` | `RmsAnalysisService` | Serialized metadata/tests | Telemetry only | `JSON` |
| `max` | `RmsAnalysisService` | Serialized metadata/tests | Telemetry only | `JSON` |
| `mean` | `RmsAnalysisService` | Serialized metadata/tests | Telemetry only | `JSON` |
| `p25` | `RmsAnalysisService` | Serialized metadata/tests | Telemetry only | `JSON` |
| `p50` | `RmsAnalysisService` | Serialized metadata/tests | Telemetry only | `JSON` |
| `p75` | `RmsAnalysisService` | Serialized metadata/tests | Telemetry only | `JSON` |
| `adaptive_threshold` | `RmsAnalysisService` | Serialized metadata/tests | Telemetry only; the actual chosen threshold is also projected elsewhere | `JSON` |

## `media_processing_logs.visual_samples`

Canonical writer: `app/Services/VisualAnalysisService.php`, persisted by `app/Jobs/PerformVisualAnalysis.php::storeVisualAnalysis()`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `visual_samples[].timestamp` | `VisualAnalysisService::analyzeVideo()` | Downstream clustering before persistence; otherwise serialized only | Analysis artifact only | `JSON` |
| `visual_samples[].classification` | `VisualAnalysisService::analyzeVideo()` | Downstream clustering before persistence | Analysis artifact only | `JSON` |
| `visual_samples[].confidence` | `VisualAnalysisService::analyzeVideo()` | Downstream clustering before persistence | Analysis artifact only | `JSON` |
| `visual_samples[].brightness` | `VisualAnalysisService::analyzeVideo()` | Serialized metadata only | Analysis artifact only | `JSON` |
| `visual_samples[].contrast` | `VisualAnalysisService::analyzeVideo()` | Serialized metadata only | Analysis artifact only | `JSON` |
| `visual_samples[].edge_density` | `VisualAnalysisService::analyzeVideo()` | Serialized metadata only | Analysis artifact only | `JSON` |

## `media_processing_logs.song_clusters`

Canonical writers:

- `app/Services/SongClusteringService.php`
- `app/Jobs/PerformVisualAnalysis.php`

Main reader:

- `app/Jobs/AnalyzeSegments.php`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `song_clusters[].start_estimate` | `SongClusteringService::clusterSongs()` | `AnalyzeSegments` | Seeds visual song-boundary detection | `DTO/VO` |
| `song_clusters[].end_estimate` | `SongClusteringService::clusterSongs()` | `AnalyzeSegments` | Seeds visual song-boundary detection | `DTO/VO` |
| `song_clusters[].sample_count` | `SongClusteringService::clusterSongs()` | `AnalyzeSegments` | Confidence/context for cluster quality | `DTO/VO` |
| `song_clusters[].samples[]` | `SongClusteringService::clusterSongs()` | `AnalyzeSegments`, boundary refinement | Exact sample timestamps used for segmentation | `DTO/VO` |
| `song_clusters[].confidence` | `SongClusteringService::clusterSongs()` | `AnalyzeSegments`, `VideoSegmentationService` | Helps determine how much to trust the cluster | `DTO/VO` |
| `song_clusters[].refined_visual_start` | `PerformVisualAnalysis::handle()` | `AnalyzeSegments` | Refines song boundaries before segment persistence | `DTO/VO` |
| `song_clusters[].refined_visual_end` | `PerformVisualAnalysis::handle()` | `AnalyzeSegments` | Refines song boundaries before segment persistence | `DTO/VO` |
| `song_clusters[].dense_sample_count` | `PerformVisualAnalysis::handle()` | `AnalyzeSegments` | Indicates refinement density/quality | `DTO/VO` |

## `livestream_segments.metadata`

Canonical writers:

- `app/Services/VideoSegmentationService.php::detectBoundariesForCluster()`
- `app/Jobs/AnalyzeSegments.php`

Main readers:

- `AnalyzeSegments::storeSegments()`
- `LivestreamSegmentationService::buildProcessingResult()`

| Key path | Written by | Read by | Business decision | Recommendation |
| --- | --- | --- | --- | --- |
| `threshold_used` | `VideoSegmentationService::detectBoundariesForCluster()` | Serialized metadata/result payloads | Boundary-provenance only | `JSON` |
| `visual_sample_count` | `VideoSegmentationService::detectBoundariesForCluster()` | `AnalyzeSegments::storeSegments()` | Already projected into dedicated `livestream_segments.visual_sample_count` | `Schema` |
| `visual_confidence` | `VideoSegmentationService::detectBoundariesForCluster()` | `AnalyzeSegments::storeSegments()` | Already projected into dedicated `livestream_segments.visual_confidence` | `Schema` |
| `calibration_method` | `VideoSegmentationService::detectBoundariesForCluster()` | `AnalyzeSegments::storeSegments()` | Already projected into dedicated `livestream_segments.calibration_method` | `Schema` |
| `boundary_method` | `VideoSegmentationService::detectBoundariesForCluster()` | Serialized metadata/result payloads | Boundary-provenance only | `JSON` |
| `visual_start` | `VideoSegmentationService::detectBoundariesForCluster()` | Serialized metadata/result payloads | Boundary-provenance only | `JSON` |
| `visual_end` | `VideoSegmentationService::detectBoundariesForCluster()` | Serialized metadata/result payloads | Boundary-provenance only | `JSON` |
| `rms_start` | `VideoSegmentationService::detectBoundariesForCluster()` | Serialized metadata/result payloads | Boundary-provenance only | `JSON` |
| `rms_end` | `VideoSegmentationService::detectBoundariesForCluster()` | Serialized metadata/result payloads | Boundary-provenance only | `JSON` |
| `song_avg_rms` | `AnalyzeSegments::generateSongSegmentsWithVisualAnalysis()` | Serialized metadata/result payloads | Boundary-provenance only | `JSON` |
| `speech_avg_rms` | `AnalyzeSegments::generateSongSegmentsWithVisualAnalysis()` | Serialized metadata/result payloads | Boundary-provenance only | `JSON` |

## Notable inconsistencies and cleanup candidates

1. `service_sections.metadata.presentation_inference` is read by `app/Support/ServiceRecordTimeline.php::buildRow()`, but current writers store `oos_alignment.presentation_inference`.
2. `linked_song_canonical_key` currently behaves like a dual-source pattern, not a clean mismatch. `app/Services/ChurchServiceSongLinker.php` correctly reads the canonical item-level value from `church_service_items.metadata`, while `app/Services/OosAlignmentService.php::songCandidatesFromSection()` also checks `service_sections.metadata.linked_song_canonical_key` as a secondary fallback. I did not find a current production writer for that section-level copy, so this should be clarified as either an intentional fallback that needs an explicit writer or a stale fallback that should be removed.
3. `service_sections.metadata.source_segment_ids` is written as a duplicate shadow copy during `ClassifySpeechSections`, while the canonical lineage field already exists at top level on `service_sections.source_segment_ids`.
4. `media_processing_logs.processing_metadata.source_type` is read by `app/Services/SermonJobPipelineService.php`, but it appears to come from alternate/test/command paths rather than the main initiator flows.
5. `media_processing_logs.processing_metadata` duplicates identity and video-context data that already exists in first-class columns (`extracted_date`, `extracted_service`, `video_file_path`, `sermon_start_time`, `sermon_end_time`).

## Legacy and test-only shapes

### Legacy schema

- `database/schema/mysql-schema.sql` still contains a legacy `sermon_processing_logs` table with `source_metadata` JSON and an older `ai_analysis` field. I did not find active application reads/writes against that table in the current runtime paths.

### Test-only or non-canonical `thumbnail_metadata` keys

These keys appear in tests but are not emitted by the current production thumbnail generator:

- `width`
- `height`
- `generation_info.brand_position`
- `file_info.{size_bytes,mime_type,quality}`
- `formats[]`

## Prioritized next steps

| Priority | Change | Why now | Migration impact / backfill |
| --- | --- | --- | --- |
| `P0` | Fix `presentation_inference` drift | This is a real read/write bug: writers store `oos_alignment.presentation_inference`, but `ServiceRecordTimeline` reads top-level `metadata.presentation_inference` | No schema migration required. Update the reader to the nested path, and keep a temporary fallback to the old top-level path if historical rows may still exist. |
| `P1` | Promote `service_sections.metadata.oos_alignment.song_match_type` to a first-class column | Highest-leverage schema promotion: it already affects runtime behavior and is queried in production via `metadata->oos_alignment->song_match_type` in `PublicSongUsageService`, which is brittle and non-index-friendly | Add a nullable enum column plus an index, backfill from JSON, switch writers/readers and query code, then retire the JSON copy after one compatibility window. |
| `P1` | Promote `church_service_items.metadata.section_type` to a first-class column | This is domain state, not provenance. It drives the manual editor, OoS alignment, and canonical-state comparison logic | Add a nullable enum column, backfill from JSON, update parser/manual-editor writers plus alignment and sync readers, then stop duplicating it into JSON. This touches parser, admin, and reconciliation flows rather than a single isolated class. |
| `P1` | Promote `service_sections.metadata.oos_alignment.{matched_item_id,expected_item_id}` to columns or FKs | These keys already behave like relations and are used by reconciliation and timeline logic | Add nullable FK columns, backfill from JSON, update OoS-alignment writers and timeline readers, then keep the JSON fields only for transitional compatibility or human-readable context. |
| `P1` | Remove duplicated identity/video fields from `media_processing_logs.processing_metadata` | `extracted_date`, `extracted_service`, and core video/segment context already have dedicated columns, so the duplicated JSON copies can drift | Backfill any missing first-class columns from JSON, switch readers to prefer columns, stop writing duplicate metadata, then prune the legacy keys. |
| `P2` | Normalize `church_services.import_metadata.manual_review.*` into review-state columns | These keys hold active workflow state, not just provenance | Add review-state columns, backfill from JSON for existing rows, update review writers/readers, and keep the JSON copy read-only for a short compatibility period if needed. |
| `P2` | Normalize current `church_services.import_metadata.canonical_conflict.*` while deciding how to retain history | Current conflict state affects workflow directly, but `canonical_conflict_history[]` is append-only history | Backfill the current conflict snapshot into columns or a dedicated current-state table. Keep `canonical_conflict_history[]` as JSON or move it to a dedicated history table in a second step so historical data is not lost. |
| `P2` | Clarify the `linked_song_canonical_key` dual-source pattern | Not a confirmed bug, but the section-level fallback is undocumented and appears to lack a current writer | No migration required. Either document it and add an explicit section-level writer, or remove the fallback so item metadata stays the single source of truth. |
| `P2` | Wrap the remaining stable JSON blobs in typed casts/DTOs | Lower risk than schema changes and improves safety at the app boundary | No data migration needed. Reuse `App\\Data\\SermonAnalysis` rather than creating a duplicate analysis DTO, then add focused types such as `ThumbnailMetadata`, `ChurchServiceImportMetadata`, `SectionClassificationMetadata`, `SectionOosAlignment`, `ChildrensTalkSpeakerMetadata`, `SectionPublicationMetadata`, `ProcessingManualReviewMetadata`, `Id3Metadata`, and `SongClusterCollection`. |
