<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Jobs\ReclassifyIntroOutroSections;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReclassifyIntroOutroSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.section_classification.intro_max_start_seconds', 120);
        Config::set('media-processing.section_classification.outro_min_remaining_seconds', 30);
        Config::set('media-processing.section_classification.outro_max_duration_seconds', 60);
    }

    // ---- Intro detection ----

    #[Test]
    public function it_reclassifies_unmatched_song_at_start_as_other_with_intro_flag(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4200.0]);

        $introSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 103.0,
            'song_match_type' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $introSection->refresh();

        $this->assertEquals(ServiceSectionType::Other->value, $introSection->section_type->value);
        $this->assertTrue($introSection->needs_manual_review);
        $this->assertEquals('possible_musical_intro', $introSection->metadata['review_reason']);
        $this->assertEquals(ServiceSectionType::Song->value, $introSection->metadata['original_section_type']);
    }

    #[Test]
    public function it_does_not_reclassify_matched_song_at_start(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4200.0]);

        $matchedIntro = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 103.0,
            'song_match_type' => ServiceSectionSongMatchType::Inferred,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $matchedIntro->refresh();

        $this->assertEquals(ServiceSectionType::Song->value, $matchedIntro->section_type->value);
    }

    #[Test]
    public function it_does_not_reclassify_legitimate_opening_song_starting_after_intro_window(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4200.0]);

        $openingSong = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $openingSong->refresh();

        $this->assertEquals(ServiceSectionType::Song->value, $openingSong->section_type->value);
    }

    // ---- Outro detection ----

    #[Test]
    public function it_reclassifies_short_unmatched_song_near_end_as_other_with_outro_flag(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4142.0]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'song_match_type' => null,
        ]);

        $outroSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 4129.0,
            'end_time' => 4142.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $outroSection->refresh();

        $this->assertEquals(ServiceSectionType::Other->value, $outroSection->section_type->value);
        $this->assertTrue($outroSection->needs_manual_review);
        $this->assertEquals('possible_musical_outro', $outroSection->metadata['review_reason']);
        $this->assertEquals(ServiceSectionType::Song->value, $outroSection->metadata['original_section_type']);
    }

    #[Test]
    public function it_does_not_reclassify_outro_that_is_too_long(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4200.0]);

        $longOutro = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 4000.0,
            'end_time' => 4190.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $longOutro->refresh();

        $this->assertEquals(ServiceSectionType::Song->value, $longOutro->section_type->value);
    }

    #[Test]
    public function it_does_not_reclassify_outro_that_ends_too_far_from_recording_end(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4200.0]);

        $midSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 3500.0,
            'end_time' => 3550.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $midSection->refresh();

        $this->assertEquals(ServiceSectionType::Song->value, $midSection->section_type->value);
    }

    #[Test]
    public function it_does_not_reclassify_matched_song_near_end(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4142.0]);

        $matchedOutro = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 4129.0,
            'end_time' => 4142.0,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $matchedOutro->refresh();

        $this->assertEquals(ServiceSectionType::Song->value, $matchedOutro->section_type->value);
    }

    // ---- Transition tagging ----

    #[Test]
    public function it_tags_a_short_empty_other_section_between_songs_as_a_transition(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4200.0]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 500.0,
            'song_match_type' => null,
        ]);

        $transition = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 500.0,
            'end_time' => 510.0,
            'duration' => 10.0,
            'needs_manual_review' => true,
            'metadata' => ['classification_mode' => 'audio_only', 'transcript' => ''],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 3,
            'start_time' => 510.0,
            'end_time' => 700.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $transition->refresh();

        $this->assertTrue(($transition->metadata['is_transition'] ?? false) === true);
        $this->assertSame('inter_song', $transition->metadata['transition_kind'] ?? null);
        $this->assertFalse($transition->needs_manual_review);
    }

    #[Test]
    public function it_does_not_tag_a_long_other_speech_section_as_a_transition(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 4200.0]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 500.0,
            'song_match_type' => null,
        ]);

        $speech = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 500.0,
            'end_time' => 1100.0,
            'duration' => 600.0,
            'metadata' => ['classification_mode' => 'ai_transcript', 'transcript' => 'A substantial spoken reflection that runs well beyond any transition blip.'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 3,
            'start_time' => 1100.0,
            'end_time' => 1300.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $speech->refresh();

        $this->assertArrayNotHasKey('is_transition', $speech->metadata?->toArray() ?? []);
    }

    // ---- Skip conditions ----

    #[Test]
    public function it_skips_for_non_livestream_processing_type(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create(['duration' => 4200.0]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'start_time' => 0.0,
            'end_time' => 103.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $section->refresh();

        $this->assertEquals(ServiceSectionType::Song->value, $section->section_type->value);
    }

    #[Test]
    public function it_skips_when_no_recording_duration_available(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => null]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'start_time' => 0.0,
            'end_time' => 103.0,
            'song_match_type' => null,
        ]);

        (new ReclassifyIntroOutroSections($log))->handle();

        $section->refresh();

        $this->assertEquals(ServiceSectionType::Song->value, $section->section_type->value);
    }
}
