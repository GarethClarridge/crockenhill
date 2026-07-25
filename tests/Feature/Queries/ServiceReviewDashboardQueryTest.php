<?php

declare(strict_types=1);

namespace Tests\Feature\Queries;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SpeakerProfile;
use App\Queries\ServiceReviewDashboardQuery;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\ServiceSectionConfidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceReviewDashboardQueryTest extends TestCase
{
    use RefreshDatabase;

    private ServiceReviewDashboardQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = app(ServiceReviewDashboardQuery::class);
    }

    #[Test]
    public function review_groups_returns_empty_when_nothing_flagged(): void
    {
        $groups = $this->query->reviewGroups();

        $this->assertSame([], $groups);
    }

    #[Test]
    public function review_groups_includes_services_flagged_for_review(): void
    {
        ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertCount(1, $groups);
        $this->assertSame('24 May 2026', $groups[0]['date_label']);
        $this->assertSame('Morning', $groups[0]['service_label']);
        $this->assertSame([], $groups[0]['sections']);
    }

    #[Test]
    public function review_groups_includes_sections_needing_manual_review(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-25',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'title' => 'Flagged Song',
            'needs_manual_review' => true,
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertCount(1, $groups);
        $this->assertCount(1, $groups[0]['sections']);
        $this->assertSame('Flagged Song', $groups[0]['sections'][0]['section']->title);
    }

    #[Test]
    public function review_groups_excludes_sections_with_no_review_reasons(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-26',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'title' => 'Clean Section',
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertSame([], $groups);
    }

    #[Test]
    public function review_groups_section_limit_caps_collection_and_still_resolves_group_services(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        // No church_service_id FK — the group's service must resolve via the
        // extracted-identity lookup, which the capped path performs lazily.
        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-24',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->count(3)->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'needs_manual_review' => true,
        ]);

        $unlimited = $this->query->reviewGroups();
        $this->assertSame(3, collect($unlimited)->sum(fn (array $group): int => count($group['sections'])));

        $limited = $this->query->reviewGroups(2);
        $this->assertCount(1, $limited);
        $this->assertCount(2, $limited[0]['sections']);
        $this->assertTrue($service->is($limited[0]['service']));
    }

    #[Test]
    public function capped_review_groups_omit_section_less_flagged_services(): void
    {
        // The capped inbox path only reads section entries, so it must not
        // hydrate (or emit groups for) every needs_review service.
        ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $this->assertCount(1, $this->query->reviewGroups());
        $this->assertSame([], $this->query->reviewGroups(10));
    }

    #[Test]
    public function review_groups_sorts_by_date_descending_then_service_ascending(): void
    {
        $olderService = ChurchService::factory()->create([
            'date' => '2026-05-10',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $newerService = ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertCount(2, $groups);
        $this->assertSame($newerService->date->toDateString(), $groups[0]['date']);
        $this->assertSame($olderService->date->toDateString(), $groups[1]['date']);
    }

    #[Test]
    public function review_groups_sorts_morning_before_evening_on_same_date(): void
    {
        ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Evening,
            'needs_review' => true,
        ]);

        ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertCount(2, $groups);
        $this->assertSame(SermonService::Morning, $groups[0]['service_enum']);
        $this->assertSame(SermonService::Evening, $groups[1]['service_enum']);
    }

    #[Test]
    public function review_groups_counts_pending_approvals_and_batch_readiness(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-05-24',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'needs_manual_review' => false,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'needs_manual_review' => true,
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['pending_approval_count']);
        $this->assertSame(1, $groups[0]['batch_ready_count']);
        $this->assertSame(1, $groups[0]['batch_blocked_count']);
    }

    #[Test]
    public function review_groups_generate_guarded_preview_urls_for_candidate_media(): void
    {
        Storage::fake('local');

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-24',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_audio_path' => 'section-publications/501/audio.mp3',
            'extracted_video_path' => 'section-publications/501/video.mp4',
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertSame(
            route('admin.services.section-publications.preview-audio', $section),
            $groups[0]['sections'][0]['audio_url'],
        );
        $this->assertSame(
            route('admin.services.section-publications.preview-video', $section),
            $groups[0]['sections'][0]['video_url'],
        );
    }

    #[Test]
    public function review_groups_do_not_generate_candidate_preview_urls_for_published_sections(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-24',
            'extracted_service' => SermonService::Morning->value,
        ]);
        $sermon = Sermon::factory()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'published_sermon_id' => $sermon->id,
            'needs_manual_review' => true,
            'extracted_audio_path' => 'section-publications/502/audio.mp3',
            'extracted_video_path' => 'section-publications/502/video.mp4',
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertNull($groups[0]['sections'][0]['audio_url']);
        $this->assertNull($groups[0]['sections'][0]['video_url']);
    }

    #[Test]
    public function summary_returns_correct_counts(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-05-24',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $groups = $this->query->reviewGroups();
        $summary = $this->query->summary($groups);

        $this->assertSame(1, $summary['service_groups']);
        $this->assertSame(1, $summary['sections']);
        $this->assertSame(1, $summary['services_needing_review']);
        $this->assertSame(1, $summary['pending_approvals']);
    }

    #[Test]
    public function summary_returns_zeros_for_empty_groups(): void
    {
        $summary = $this->query->summary([]);

        $this->assertSame(0, $summary['service_groups']);
        $this->assertSame(0, $summary['sections']);
        $this->assertSame(0, $summary['services_needing_review']);
        $this->assertSame(0, $summary['pending_approvals']);
    }

    #[Test]
    public function review_reasons_returns_manual_review_flag(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        $reasons = $this->query->reviewReasons($section);

        $this->assertCount(1, $reasons);
        $this->assertSame('needs_manual_review', $reasons[0]['key']);
    }

    #[Test]
    public function review_reasons_returns_pending_approval_flag(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $reasons = $this->query->reviewReasons($section);

        $this->assertCount(1, $reasons);
        $this->assertSame('pending_approval', $reasons[0]['key']);
    }

    #[Test]
    public function review_reasons_returns_low_confidence_flag(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'needs_manual_review' => false,
            'confidence' => ServiceSectionConfidence::HIGH_THRESHOLD - 0.01,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $reasons = $this->query->reviewReasons($section);

        $keys = array_column($reasons, 'key');
        $this->assertContains('low_confidence', $keys);
    }

    #[Test]
    public function a_suspected_closing_benediction_is_exempt_from_low_confidence_review(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $attributes = [
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::BibleReading,
            'needs_manual_review' => false,
            'confidence' => ServiceSectionConfidence::HIGH_THRESHOLD - 0.05,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ];

        $benediction = ServiceSection::factory()->create([
            ...$attributes,
            'metadata' => ['review_flags' => [ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT]],
        ]);
        $plainReading = ServiceSection::factory()->create([
            ...$attributes,
            'metadata' => ['review_flags' => []],
        ]);

        // The suspected benediction drops out of both the live PHP reasons and
        // the SQL candidate set; an identical reading without the flag stays
        // flagged. reviewCandidateSectionCount() exercises the base query, and
        // with RefreshDatabase only these two sections exist.
        $this->assertFalse($this->query->isReviewCandidate($benediction));
        $this->assertTrue($this->query->isReviewCandidate($plainReading));
        $this->assertSame(1, $this->query->reviewCandidateSectionCount());
    }

    #[Test]
    public function a_confirmed_song_match_is_exempt_from_low_confidence_review(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $attributes = [
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'needs_manual_review' => false,
            'confidence' => ServiceSectionConfidence::HIGH_THRESHOLD - 0.05,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ];

        // A positively identified song: the transcript match already cleared the
        // confidence bar, so its low structural-placement score must not, on its
        // own, keep it in review.
        $confirmedSong = ServiceSection::factory()->create([
            ...$attributes,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
        ]);
        // An unidentified low-confidence song still warrants a look.
        $unmatchedSong = ServiceSection::factory()->create([
            ...$attributes,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
        ]);

        // The confirmed match drops out of both the live PHP reasons and the SQL
        // candidate set; the unmatched song stays flagged. reviewCandidateSectionCount()
        // exercises the base query, and with RefreshDatabase only these two sections exist.
        $this->assertFalse($this->query->isReviewCandidate($confirmedSong));
        $this->assertTrue($this->query->isReviewCandidate($unmatchedSong));
        $this->assertSame(1, $this->query->reviewCandidateSectionCount());
    }

    #[Test]
    public function childrens_talk_prediction_is_not_a_review_reason_without_a_compatible_profile(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk,
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'metadata' => [
                'childrens_talk_speaker' => [
                    'predicted' => ['outcome' => 'no_profiles'],
                ],
            ],
        ]);

        $this->assertFalse($this->query->isReviewCandidate($section));
        $this->assertSame([], $this->query->reviewGroups());
    }

    #[Test]
    public function childrens_talk_prediction_remains_a_review_reason_with_a_compatible_profile(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);
        SpeakerProfile::factory()->create([
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk,
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'metadata' => [
                'childrens_talk_speaker' => [
                    'predicted' => ['outcome' => 'no_match'],
                ],
            ],
        ]);

        $this->assertTrue($this->query->isReviewCandidate($section));
        $this->assertSame('speaker_review', $this->query->reviewReasons($section)[0]['key']);
    }

    #[Test]
    public function review_reasons_returns_inferred_song_label_flag(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $inferredItem = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'song_id' => null,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $inferredItem->id,
            'section_type' => ServiceSectionType::Song->value,
            'needs_manual_review' => true,
            'confidence' => 0.99,
            'song_match_type' => 'inferred',
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['oos_alignment' => ['song_match_type' => 'inferred']],
        ]);

        $section->load('churchServiceItem');

        $reasons = $this->query->reviewReasons($section);

        $keys = array_column($reasons, 'key');
        $this->assertContains('inferred_song_label', $keys);
    }

    #[Test]
    public function review_reasons_returns_unmatched_song_flag(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'church_service_item_id' => null,
            'needs_manual_review' => true,
            'confidence' => 0.99,
            'song_match_type' => 'unmatched',
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['oos_alignment' => ['song_match_type' => 'unmatched']],
        ]);

        $reasons = $this->query->reviewReasons($section);

        $keys = array_column($reasons, 'key');
        $this->assertContains('unmatched_song', $keys);
    }

    #[Test]
    public function review_entry_marks_one_click_confirmable_sections(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $confirmable = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Other->value,
            'needs_manual_review' => true,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $unmatchedSong = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'church_service_item_id' => null,
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'song_match_type' => 'unmatched',
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertTrue($this->query->reviewEntryFor($confirmable)['confirmable']);
        $this->assertFalse($this->query->reviewEntryFor($unmatchedSong)['confirmable']);
    }

    #[Test]
    public function is_review_candidate_returns_false_for_clean_section(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertFalse($this->query->isReviewCandidate($section));
    }

    #[Test]
    public function is_review_candidate_returns_true_for_flagged_section(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        $this->assertTrue($this->query->isReviewCandidate($section));
    }

    #[Test]
    public function batch_approval_skip_reason_returns_null_for_pending_only_section(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $this->assertNull($this->query->batchApprovalSkipReason($section));
    }

    #[Test]
    public function batch_approval_skip_reason_returns_blocked_when_other_review_flags_present(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $this->assertSame('blocked by other review flags', $this->query->batchApprovalSkipReason($section));
    }

    #[Test]
    public function batch_approval_skip_reason_returns_blocked_for_heuristic_demotion_flag(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['heuristic_demotion'],
            ],
        ]);

        $this->assertSame('blocked by other review flags', $this->query->batchApprovalSkipReason($section));
    }

    #[Test]
    public function pending_publication_sections_for_service_returns_only_that_services_sections(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-01',
            'service' => SermonService::Morning,
        ]);

        $otherService = ChurchService::factory()->create([
            'date' => '2026-06-01',
            'service' => SermonService::Evening,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-01',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $otherRun = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $otherService->id,
            'extracted_date' => '2026-06-01',
            'extracted_service' => SermonService::Evening->value,
        ]);

        $matchedSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $otherRun->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $result = $this->query->pendingPublicationSectionsForService($service);

        $this->assertCount(1, $result);
        $this->assertSame($matchedSection->id, $result->first()->id);
    }

    #[Test]
    public function pending_publication_sections_for_service_includes_fallback_matched_repaired_runs(): void
    {
        $processingId = '55555555-5555-5555-5555-555555555555';

        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => 'Sermon',
            'livestream_processing_id' => $processingId,
        ]);

        $repairedRun = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => $processingId,
            'church_service_id' => null,
            'extracted_date' => '2026-01-01',
            'extracted_service' => SermonService::Evening->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $repairedRun->id,
            'church_service_item_id' => null,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $result = $this->query->pendingPublicationSectionsForService($service);

        $this->assertCount(1, $result);
        $this->assertSame($section->id, $result->first()->id);
    }

    #[Test]
    public function review_groups_includes_sections_flagged_only_by_heuristic_demotion(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-31',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'title' => 'Demoted Talk',
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['heuristic_demotion'],
            ],
        ]);

        $groups = $this->query->reviewGroups();

        $this->assertCount(1, $groups);
        $this->assertCount(1, $groups[0]['sections']);
        $this->assertSame('Demoted Talk', $groups[0]['sections'][0]['section']->title);
        $this->assertContains(
            'heuristic_demotion',
            array_column($groups[0]['sections'][0]['reasons'], 'key')
        );
    }
}
