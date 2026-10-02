<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Sermon\SermonCutProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonCutProbeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_plans_the_production_cut_from_a_proposal_and_writes_nothing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $existing = ServiceSection::factory()->create(['media_processing_log_id' => $log->id]);
        $logs = MediaProcessingLog::query()->count();
        $sections = ServiceSection::query()->count();

        $cut = app(SermonCutProbe::class)->probe($log, $this->structure([]), $this->transcript());

        $this->assertSame('concat_spans', $cut['as_written']['mode'], $cut['as_written']['error'] ?? '');
        $this->assertSame('identified_sections', $cut['as_written']['strategy']);
        $this->assertEquals([['start_time' => 420.0, 'end_time' => 590.0], ['start_time' => 600.0, 'end_time' => 2200.0]], $cut['as_written']['segments']);
        $this->assertSame($logs, MediaProcessingLog::query()->count());
        $this->assertSame($sections, ServiceSection::query()->count());
        $this->assertNotNull($existing->fresh());
    }

    #[Test]
    public function an_ensemble_question_on_the_sermon_holds_the_gated_cut_but_not_the_cut_as_written(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 610.0,
            'sermon_end_time' => 2190.0,
        ]);

        $cut = app(SermonCutProbe::class)->probe(
            $log,
            $this->structure([ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES]),
            $this->transcript(),
        );

        $this->assertSame('concat_spans', $cut['gated']['mode'], $cut['gated']['error'] ?? '');
        $this->assertTrue($cut['gated']['from_sections']);
        $this->assertTrue($cut['gated']['requires_review']);
        $this->assertFalse($cut['as_written']['requires_review']);
        $this->assertTrue($cut['as_written']['from_sections']);
        $this->assertEquals([['start_time' => 420.0, 'end_time' => 590.0], ['start_time' => 600.0, 'end_time' => 2200.0]], $cut['as_written']['segments']);
    }

    /** @param  list<string>  $sermonFlags */
    private function structure(array $sermonFlags): ServiceStructure
    {
        return ServiceStructure::fromSections([
            new ServiceStructureSection(ServiceSectionType::Welcome, 'Welcome', 0.0, 60.0, 0.95, null, null, null),
            new ServiceStructureSection(ServiceSectionType::BibleReading, 'Reading', 420.0, 590.0, 0.95, null, null, 'John 3'),
            new ServiceStructureSection(
                ServiceSectionType::Sermon, 'Sermon', 600.0, 2200.0, 0.95, null, null, null, 'John 3', reviewFlags: $sermonFlags,
            ),
            new ServiceStructureSection(ServiceSectionType::Song, 'Praise my soul', 2210.0, 2400.0, 0.95, null, 'Praise my soul', null),
        ]);
    }

    private function transcript(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 30.0, 'text' => 'Good morning everyone and a very warm welcome.'],
            ['start' => 420.0, 'end' => 590.0, 'text' => 'Our reading is from John chapter three.'],
            ['start' => 600.0, 'end' => 2200.0, 'text' => 'Please turn with me to the passage we have just read.'],
            ['start' => 2210.0, 'end' => 2400.0, 'text' => 'Praise my soul the King of heaven.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
    }
}
