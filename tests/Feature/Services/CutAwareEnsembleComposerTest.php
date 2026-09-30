<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\CutAwareEnsembleComposer;
use App\Services\ChurchService\Structure\ValidationResult;
use App\Services\Sermon\SermonCutProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CutAwareEnsembleComposerTest extends TestCase
{
    use RefreshDatabase;

    /** The shape of run 1025 in the 2026-09-30 evaluation: a short `other` span before the closing song. */
    #[Test]
    public function a_filler_span_the_cut_runs_through_either_way_raises_no_question(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create(['sermon_start_time' => 600.0, 'sermon_end_time' => 2150.0]);

        $result = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Other), $this->transcript(), $log);

        $this->assertSame([], $result->disputes);
    }

    #[Test]
    public function notices_are_still_asked_and_nothing_is_dropped_without_a_run(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create(['sermon_start_time' => 600.0, 'sermon_end_time' => 2150.0]);

        $notices = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Notices), $this->transcript(), $log);
        $noRun = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Other), $this->transcript(), null);

        $this->assertContains('notices', array_column($notices->disputes, 'type'));
        $this->assertContains('other', array_column($noRun->disputes, 'type'));
    }

    #[Test]
    public function a_filler_span_that_moves_the_cut_is_still_asked(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->app->instance(SermonCutProbe::class, new class extends SermonCutProbe
        {
            public function __construct() {}

            public function asWritten(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
            {
                $end = $structure->sectionsOfType(ServiceSectionType::Other) === [] ? 2210.0 : 2150.0;

                return ['mode' => 'single_span', 'segments' => [['start_time' => 600.0, 'end_time' => $end]], 'from_sections' => true];
            }
        });

        $result = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Other), $this->transcript(), $log);

        $this->assertContains('other', array_column($result->disputes, 'type'));
    }

    /** @return array<int, ValidationResult> */
    private function votes(ServiceSectionType $fillerType): array
    {
        $draw = fn (bool $filler): ValidationResult => new ValidationResult(ServiceStructure::fromSections(array_values(array_filter([
            new ServiceStructureSection(ServiceSectionType::Sermon, 'Sermon', 600.0, 2150.0, 0.95, null, null, null),
            $filler ? new ServiceStructureSection($fillerType, 'Filler', 2150.0, 2180.0, 0.95, null, null, null) : null,
            new ServiceStructureSection(ServiceSectionType::Song, 'Praise my soul', 2210.0, 2400.0, 0.95, null, 'Praise my soul', null),
        ]))));

        return [0 => $draw(true), 1 => $draw(true), 2 => $draw(false), 3 => $draw(false)];
    }

    private function transcript(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 600.0, 'end' => 2150.0, 'text' => 'Please turn with me to the passage.'],
            ['start' => 2150.0, 'end' => 2180.0, 'text' => 'Let us stand to sing.'],
            ['start' => 2210.0, 'end' => 2400.0, 'text' => 'Praise my soul the King of heaven.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
    }
}
