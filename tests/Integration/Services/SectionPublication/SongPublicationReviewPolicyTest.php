<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\SectionPublication\SongPublicationReviewPolicy;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongPublicationReviewPolicyTest extends TestCase
{
    use RefreshDatabase;

    private SongPublicationReviewPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = app(SongPublicationReviewPolicy::class);
    }

    /**
     * A recording graded `short_partial` or `fragmented` does not hold enough of
     * the service to assert on its own which songs were sung, so its clips reach
     * a reviewer unless another source says the same thing.
     */
    #[Test]
    public function it_holds_a_clip_from_an_uncorroborated_partial_recording(): void
    {
        $section = $this->section('short_partial', ['livestream']);

        $this->assertSame(
            ['uncorroborated_partial_recording'],
            array_column($this->policy->reviewReasons($section), 'kind'),
        );
    }

    #[Test]
    public function it_releases_a_partial_recording_another_source_corroborates(): void
    {
        $section = $this->section('short_partial', ['livestream', 'openlp']);

        $this->assertSame([], $this->policy->reviewReasons($section));
    }

    #[Test]
    public function it_releases_a_whole_recording_no_other_source_saw(): void
    {
        $section = $this->section('full', ['livestream']);

        $this->assertSame([], $this->policy->reviewReasons($section));
    }

    #[Test]
    public function it_holds_an_inferred_match_even_when_confidence_is_high_and_the_marker_disagrees(): void
    {
        $section = $this->section(
            'full',
            ['livestream'],
            ServiceSectionSongMatchType::Inferred,
            [
                'review_flags' => [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
                'transcript_song_match' => [
                    'confidence' => 1.0,
                    'match_source' => 'title_hint_first_line',
                ],
            ],
        );

        $reasons = $this->policy->reviewReasons($section);

        $this->assertSame(['inferred_song_match'], array_column($reasons, 'kind'));
        $this->assertStringContainsString('inferred', $reasons[0]['detail']);
    }

    #[Test]
    public function it_holds_an_inferred_match_without_confidence_metadata(): void
    {
        $section = $this->section('full', ['livestream'], ServiceSectionSongMatchType::Inferred);

        $this->assertSame(
            ['inferred_song_match'],
            array_column($this->policy->reviewReasons($section), 'kind'),
        );
    }

    /**
     * @param  list<string>  $provenance
     * @param  array<string, mixed>  $metadata
     */
    private function section(
        string $grade,
        array $provenance,
        ServiceSectionSongMatchType $matchType = ServiceSectionSongMatchType::Confirmed,
        array $metadata = [],
    ): ServiceSection {
        $churchService = ChurchService::factory()->create(['date' => '2020-03-22']);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => Song::factory()->create()->id,
            'source' => ChurchServiceItemSource::Livestream,
            'metadata' => [
                'source_evidence' => array_fill_keys($provenance, ['recorded_at' => '2020-03-22T10:00:00Z']),
            ],
        ]);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
            'processing_metadata' => [
                'historic_import' => ['corroboration_grade' => $grade],
            ],
        ]);

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => $matchType->value,
            'start_time' => 600.0,
            'end_time' => 840.0,
            'duration' => 240.0,
            'metadata' => $metadata,
        ])->fresh();
    }
}
