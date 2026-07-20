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
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChildrensTalkSpeakerServiceTest extends TestCase
{
    use RefreshDatabase;

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
    public function it_marks_as_manual_review_when_audio_path_is_missing(): void
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
}
