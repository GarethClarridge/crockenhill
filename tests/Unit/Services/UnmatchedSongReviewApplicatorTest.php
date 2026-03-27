<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\UnmatchedSongReviewApplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnmatchedSongReviewApplicatorTest extends TestCase
{
    use RefreshDatabase;

    private UnmatchedSongReviewApplicator $applicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicator = app(UnmatchedSongReviewApplicator::class);
    }

    /**
     * The service mutates sections in-memory; callers are responsible for persisting.
     *
     * @param  Collection<int, ServiceSection>  $unmatched
     */
    private function persistUnmatched(Collection $unmatched): void
    {
        foreach ($unmatched as $section) {
            $section->save();
        }
    }

    #[Test]
    public function it_returns_an_empty_collection_when_all_song_sections_are_matched(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG->value,
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();

        $unmatched = $this->applicator->apply($sections, [$section->id]);

        $this->assertCount(0, $unmatched);
    }

    #[Test]
    public function it_flags_an_unmatched_song_section_for_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG->value,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $this->assertCount(1, $unmatched);
        $this->assertTrue($section->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_appends_the_unmatched_song_section_review_flag(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG->value,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $freshMetadata = $section->fresh()->metadata?->toArray() ?? [];
        $this->assertContains('unmatched_song_section', $freshMetadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_sets_song_match_type_to_unmatched_when_not_previously_set(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG->value,
            'song_match_type' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $this->assertSame(ServiceSectionSongMatchType::UNMATCHED, $section->fresh()->song_match_type);
    }

    #[Test]
    public function it_decreases_confidence_on_unmatched_song_sections(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG->value,
            'confidence' => 0.9,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $this->assertLessThan(0.9, $section->fresh()->confidence);
    }

    #[Test]
    public function it_does_not_flag_non_song_sections(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SERMON->value,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();

        $unmatched = $this->applicator->apply($sections, []);

        $this->assertCount(0, $unmatched);
    }

    #[Test]
    public function it_does_not_overwrite_an_existing_review_reason(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG->value,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'audio_only',
                'review_reason' => 'pre_existing_reason',
            ],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $freshMetadata = $section->fresh()->metadata?->toArray() ?? [];
        $this->assertSame('pre_existing_reason', $freshMetadata['review_reason']);
    }
}
