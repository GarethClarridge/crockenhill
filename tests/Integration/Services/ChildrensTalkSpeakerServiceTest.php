<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerMatchResult;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Models\SpeakerProfile;
use App\Services\Preacher\ChildrensTalkSpeakerService;
use App\Support\MediaAssetPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChildrensTalkSpeakerServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Put a real file behind a section's audio path.
     *
     * `predictionPayload()` checks the media disk before spawning the extractor, so a
     * section whose path points at nothing now resolves to `missing_audio` and never
     * reaches `identify()`. Any test that expects a verdict has to stage the file.
     */
    private function stageSectionAudio(string $path = 'sections/talk.mp3'): string
    {
        Storage::fake(MediaAssetPath::disk());
        Storage::disk(MediaAssetPath::disk())->put($path, 'audio');

        return $path;
    }

    // ── detectAndStore ────────────────────────────────────────────────────────

    #[Test]
    public function it_does_nothing_when_section_type_is_not_childrens_talk(): void
    {
        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldNotReceive('identify');

        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $this->assertFalse($section->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_skips_identification_when_speaker_identification_is_disabled(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => false,
            'media-processing.speaker_identification.provider' => 'null',
        ]);

        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldNotReceive('identify');

        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => 'sections/talk.mp3',
            'duration' => 120,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();
        $this->assertFalse($fresh->needs_manual_review);
        $this->assertSame('skipped', $fresh->metadata?->toArray()['childrens_talk_speaker']['predicted']['outcome'] ?? null);
    }

    #[Test]
    public function it_dispositions_rather_than_reviews_when_the_audio_path_is_absent(): void
    {
        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldNotReceive('identify');

        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();
        $this->assertFalse($fresh->needs_manual_review);
        $this->assertSame('missing_audio', $fresh->metadata?->toArray()['childrens_talk_speaker']['predicted']['outcome'] ?? null);
    }

    #[Test]
    public function it_auto_accepts_a_matched_speaker_and_clears_manual_review(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.min_duration' => 30,
            'media-processing.speaker_identification.provider' => 'null',
        ]);

        $preacher = Preacher::factory()->create(['name' => 'Jane Smith']);

        // A SpeakerProfile must exist so eligibleProfiles() returns a non-empty collection
        SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        $matchResult = new SpeakerMatchResult(
            matched: true,
            matchedPreacherId: $preacher->id,
            matchedPreacherName: $preacher->name,
            topScore: 0.92,
            secondScore: 0.45,
            margin: 0.47,
        );

        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldReceive('identify')->once()->andReturn($matchResult);

        $this->stageSectionAudio();
        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => 'sections/talk.mp3',
            'duration' => 120,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();
        $this->assertFalse($fresh->needs_manual_review);

        $speakerData = $fresh->metadata?->toArray()['childrens_talk_speaker'] ?? [];
        $this->assertSame('matched', $speakerData['predicted']['outcome'] ?? null);
        $this->assertSame('auto_accepted', $speakerData['reviewed']['review_mode'] ?? null);
        $this->assertSame($preacher->id, $speakerData['reviewed']['preacher_id'] ?? null);
    }

    #[Test]
    public function it_flags_for_manual_review_when_no_match_is_found(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.min_duration' => 30,
            'media-processing.speaker_identification.provider' => 'null',
        ]);

        // A SpeakerProfile must exist so eligibleProfiles() returns a non-empty collection
        SpeakerProfile::factory()->create();

        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldReceive('identify')->once()->andReturn(SpeakerMatchResult::noMatch(topScore: 0.4));

        $this->stageSectionAudio();
        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => 'sections/talk.mp3',
            'duration' => 120,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();
        $this->assertTrue($fresh->needs_manual_review);
        $this->assertContains('childrens_talk_speaker_review', $fresh->metadata?->toArray()['review_flags'] ?? []);
    }

    #[Test]
    public function it_keeps_a_prediction_without_opening_review_when_no_compatible_profile_exists(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldNotReceive('identify');

        $this->stageSectionAudio();
        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk,
            'extracted_audio_path' => 'sections/talk.mp3',
            'duration' => 120,
            'needs_manual_review' => false,
            'metadata' => ['classification_mode' => 'llm_structure'],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();
        $this->assertFalse($fresh->needs_manual_review);
        $this->assertSame('no_profiles', $fresh->metadata['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertNotContains('childrens_talk_speaker_review', $fresh->metadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_withdraws_review_for_a_short_talk_even_when_profiles_exist(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.min_duration' => 30,
            'media-processing.speaker_identification.provider' => 'null',
        ]);

        // The profile is the point: before 2026-09-03 an eligible profile sent every
        // non-matching outcome down the review branch, so a 12-second talk opened a
        // question about audio too short to answer it.
        SpeakerProfile::factory()->create();

        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldNotReceive('identify');

        $this->stageSectionAudio();
        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => 'sections/talk.mp3',
            // ServiceSectionFactory recomputes `duration` from the timestamps, so setting
            // `duration` alone is silently discarded.
            'start_time' => 0,
            'end_time' => 12,
            'metadata' => ['classification_mode' => 'llm_structure'],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();
        $this->assertFalse($fresh->needs_manual_review);
        $this->assertSame('short_audio', $fresh->metadata?->toArray()['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertNotContains('childrens_talk_speaker_review', $fresh->metadata?->toArray()['review_flags'] ?? []);
    }

    #[Test]
    public function it_reports_missing_audio_without_calling_the_extractor_when_the_file_has_been_reaped(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.min_duration' => 30,
            'media-processing.speaker_identification.provider' => 'null',
        ]);

        SpeakerProfile::factory()->create();

        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldNotReceive('identify');

        // Path recorded, file gone — the ordinary state of a published section whose audio
        // has been cleaned up while the run's full audio is retained.
        Storage::fake(MediaAssetPath::disk());

        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => 'section-publications/671/reaped.mp3',
            'duration' => 300,
            'metadata' => ['classification_mode' => 'llm_structure'],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();
        $this->assertFalse($fresh->needs_manual_review);
        $this->assertSame('missing_audio', $fresh->metadata?->toArray()['childrens_talk_speaker']['predicted']['outcome'] ?? null);
    }

    #[Test]
    public function it_keeps_the_named_shortlist_when_the_model_cannot_separate_the_candidates(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.min_duration' => 30,
            'media-processing.speaker_identification.provider' => 'null',
        ]);

        $first = Preacher::factory()->create(['name' => 'Mark Drury']);
        $second = Preacher::factory()->create(['name' => 'Laurie Everest']);
        SpeakerProfile::factory()->create(['preacher_id' => $first->id]);

        $candidates = [
            ['preacher_id' => $first->id, 'preacher_name' => 'Mark Drury', 'score' => 0.838],
            ['preacher_id' => $second->id, 'preacher_name' => 'Laurie Everest', 'score' => 0.751],
        ];

        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldReceive('identify')->once()->andReturn(SpeakerMatchResult::noMatch(
            topScore: 0.838,
            secondScore: 0.751,
            reason: 'Margin below threshold',
            candidates: $candidates,
        ));

        $this->stageSectionAudio();
        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => 'sections/talk.mp3',
            'duration' => 300,
            'metadata' => ['classification_mode' => 'llm_structure'],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();

        // The question stays open — but it is now a shortlist rather than a blank.
        $this->assertTrue($fresh->needs_manual_review);
        // assertEquals, not assertSame: the metadata wrapper canonicalises JSON key order.
        $this->assertEquals($candidates, $fresh->metadata?->toArray()['childrens_talk_speaker']['predicted']['candidates'] ?? null);
    }

    /**
     * The fault that produced this test: the CBC media drive detached on 2026-09-03 and
     * remounted under a different name, so every exists() against `historic_staging`
     * answered false. Read as `missing_audio` that would have dispositioned each section
     * and withdrawn its review flag — retiring open questions in bulk because a volume
     * was unplugged.
     */
    #[Test]
    public function it_reports_an_error_rather_than_missing_audio_when_the_media_disk_is_detached(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.min_duration' => 30,
            'media-processing.speaker_identification.provider' => 'null',
            'media-processing.storage.sermon_disk' => 'detached_volume',
            'filesystems.disks.detached_volume' => [
                'driver' => 'local',
                'root' => '/mnt/a-volume-that-is-not-mounted',
            ],
        ]);

        SpeakerProfile::factory()->create();

        $speaker = $this->mock(SpeakerIdentificationInterface::class);
        $speaker->shouldNotReceive('identify');

        $this->app->forgetInstance(ChildrensTalkSpeakerService::class);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => 'section-publications/talk.mp3',
            'start_time' => 0,
            'end_time' => 300,
            'metadata' => ['review_flags' => ['childrens_talk_speaker_review']],
        ]);

        app(ChildrensTalkSpeakerService::class)->detectAndStore($section);
        $section->save();

        $fresh = $section->fresh();

        // `error` is a review-opening outcome, so the question survives the outage.
        $this->assertSame('error', $fresh->metadata?->toArray()['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertContains('childrens_talk_speaker_review', $fresh->metadata?->toArray()['review_flags'] ?? []);
        $this->assertTrue($fresh->needs_manual_review);
    }

    // ── storeManualReview ─────────────────────────────────────────────────────

    #[Test]
    public function it_stores_a_manual_review_with_a_preacher_id(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Bob Jones']);

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
                'review_flags' => ['childrens_talk_speaker_review'],
            ],
        ]);

        $service = app(ChildrensTalkSpeakerService::class);
        $service->storeManualReview($section, $preacher->id, null, 1);
        $section->save();

        $fresh = $section->fresh();
        $this->assertFalse($fresh->needs_manual_review);

        $reviewed = $fresh->metadata?->toArray()['childrens_talk_speaker']['reviewed'] ?? [];
        $this->assertSame($preacher->id, $reviewed['preacher_id']);
        $this->assertSame('manual_override', $reviewed['review_mode']);
    }

    #[Test]
    public function it_stores_a_manual_review_with_free_text_speaker_name(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'needs_manual_review' => true,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        $service = app(ChildrensTalkSpeakerService::class);
        $service->storeManualReview($section, null, 'Guest Speaker', 2);
        $section->save();

        $fresh = $section->fresh();
        $this->assertFalse($fresh->needs_manual_review);

        $reviewed = $fresh->metadata?->toArray()['childrens_talk_speaker']['reviewed'] ?? [];
        $this->assertSame('Guest Speaker', $reviewed['preacher_name']);
        $this->assertNull($reviewed['preacher_id']);
        $this->assertSame('manual_free_text', $reviewed['review_mode']);
    }

    #[Test]
    public function it_does_nothing_when_both_preacher_id_and_name_are_absent(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'needs_manual_review' => true,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        $service = app(ChildrensTalkSpeakerService::class);
        $service->storeManualReview($section, null, null, 1);
        $section->save();

        $this->assertTrue($section->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_does_nothing_for_non_childrens_talk_sections_in_store_manual_review(): void
    {
        $preacher = Preacher::factory()->create();

        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $service = app(ChildrensTalkSpeakerService::class);
        $service->storeManualReview($section, $preacher->id, null, 1);
        $section->save();

        $this->assertFalse($section->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_records_the_rank_when_a_reviewer_confirms_a_candidate_the_model_proposed(): void
    {
        $top = Preacher::factory()->create(['name' => 'Mark Drury']);
        $second = Preacher::factory()->create(['name' => 'Laurie Everest']);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'needs_manual_review' => true,
            'metadata' => [
                'review_flags' => ['childrens_talk_speaker_review'],
                'review_reason' => 'childrens_talk_speaker_ambiguous',
                'childrens_talk_speaker' => [
                    'predicted' => [
                        'outcome' => 'ambiguous',
                        'confidence' => 0.838,
                        'candidates' => [
                            ['preacher_id' => $top->id, 'preacher_name' => 'Mark Drury', 'score' => 0.838],
                            ['preacher_id' => $second->id, 'preacher_name' => 'Laurie Everest', 'score' => 0.751],
                        ],
                    ],
                ],
            ],
        ]);

        app(ChildrensTalkSpeakerService::class)->storeManualReview($section, $second->id, null, 7);
        $section->save();

        $reviewed = $section->fresh()->metadata?->toArray()['childrens_talk_speaker']['reviewed'] ?? [];

        // Rank rather than a boolean: "how often was our top candidate right?" has to be
        // answerable from stored reviews before the sub-0.10 margin band could ever be
        // auto-accepted.
        $this->assertSame('proposal_confirmed', $reviewed['review_mode'] ?? null);
        $this->assertSame(2, $reviewed['proposal_rank'] ?? null);
        $this->assertSame($second->id, $reviewed['preacher_id'] ?? null);
        $this->assertFalse($section->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_records_a_manual_override_when_the_reviewer_names_someone_never_proposed(): void
    {
        $proposed = Preacher::factory()->create(['name' => 'Mark Drury']);
        $actual = Preacher::factory()->create(['name' => 'Someone Else']);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'needs_manual_review' => true,
            'metadata' => [
                'review_flags' => ['childrens_talk_speaker_review'],
                'childrens_talk_speaker' => [
                    'predicted' => [
                        'outcome' => 'ambiguous',
                        'candidates' => [
                            ['preacher_id' => $proposed->id, 'preacher_name' => 'Mark Drury', 'score' => 0.838],
                        ],
                    ],
                ],
            ],
        ]);

        app(ChildrensTalkSpeakerService::class)->storeManualReview($section, $actual->id, null, 7);
        $section->save();

        $reviewed = $section->fresh()->metadata?->toArray()['childrens_talk_speaker']['reviewed'] ?? [];

        $this->assertSame('manual_override', $reviewed['review_mode'] ?? null);
        $this->assertArrayHasKey('proposal_rank', $reviewed);
        $this->assertNull($reviewed['proposal_rank']);
    }
}
