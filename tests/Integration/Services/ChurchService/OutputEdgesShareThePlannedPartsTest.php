<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SpokenEdgeSentenceCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WP62 item 3: the exposed edges evidence preparation follows up are the edges the sermon plan cuts,
 * not those of the raw section bounds, so the sentence check is asked where the planner looks for it.
 */
class OutputEdgesShareThePlannedPartsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: MediaProcessingLog, 1: ServiceSection, 2: ServiceSection} */
    private function makeRun(float $readingStart, float $sermonEnd): array
    {
        $run = MediaProcessingLog::factory()->livestream()->create(['duration' => 1000.0]);
        $reading = ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::BibleReading,
            'start_time' => $readingStart, 'end_time' => 300.0, 'section_order' => 1]);
        $sermon = ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::Sermon,
            'start_time' => 300.0, 'end_time' => $sermonEnd, 'section_order' => 2]);

        return [$run, $reading, $sermon];
    }

    #[Test]
    public function the_composed_introduction_opening_is_the_sermon_outputs_start_edge(): void
    {
        [$run, $reading, $sermon] = $this->makeRun(75.0, 900.0);
        $introduction = ['reading_section_id' => $reading->id, 'start_time' => 0.0, 'end_time' => 75.0];

        $edges = app(SpokenEdgeSentenceCheck::class)->outputEdges($run, [$reading, $sermon], [$reading->id, $sermon->id], $introduction);

        self::assertContains(['start', 0.0], $edges);
        self::assertNotContains(['start', 75.0], $edges);
    }

    #[Test]
    public function a_section_ending_just_past_the_recording_is_an_edge_at_the_recordings_end(): void
    {
        [$run, $reading, $sermon] = $this->makeRun(75.0, 1000.004);

        $edges = app(SpokenEdgeSentenceCheck::class)->outputEdges($run, [$reading, $sermon], [$reading->id, $sermon->id]);

        self::assertContains(['end', 1000.0], $edges);
        self::assertNotContains(['end', 1000.004], $edges);
    }
}
