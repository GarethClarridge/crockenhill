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
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Sermon\SermonCutProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BanksNoWordOutputEdges;
use Tests\TestCase;

class CutAwareEnsembleComposerTest extends TestCase
{
    use BanksNoWordOutputEdges;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
    }

    /** The shape of run 1025 in the 2026-09-30 evaluation: a short `other` span before the closing song. */
    #[Test]
    public function a_filler_span_the_cut_runs_through_either_way_raises_no_question(): void
    {
        $log = $this->decodedRun();

        $result = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Other), $this->transcript(), $log, gatherEvidence: true);

        $this->assertSame([], $result->disputes);
    }

    /** The shape of runs 1028, 1050 and 1358 in canary 9: one draft types a filler span the others leave out. */
    #[Test]
    public function a_filler_span_the_majority_settles_without_moving_the_cut_is_not_on_the_skim_list(): void
    {
        $log = $this->decodedRun();

        $result = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Other, 1), $this->transcript(), $log, gatherEvidence: true);

        $this->assertSame([], $result->disputes);
        $this->assertSame([], $result->majorityDecisions);
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

    /**
     * Two empty plans are not the same cut: before 11-03 every filler question was dropped while the
     * edge words were undecoded, and asked once one version had them (1148 in the 11-02 round).
     */
    #[Test]
    public function a_version_whose_edges_are_not_decoded_is_asked(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->stubProbe(fn (): array => $this->undecoded([['start_time' => 600.0, 'end_time' => 2150.0]]));

        $result = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Other), $this->transcript(), $log);

        $this->assertContains('other', array_column($result->disputes, 'type'));
    }

    /** The shape of 1148, 1150, 1178 and 1190: detection decodes what each version waits on, then compares. */
    #[Test]
    public function detection_gathers_each_versions_evidence_before_comparing_cuts(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->stubProbe(
            fn (): array => $this->undecoded([['start_time' => 600.0, 'end_time' => 2150.0]]),
            fn (): array => ['mode' => 'single_span', 'strategy' => 'identified_sections', 'segments' => [['start_time' => 599.4, 'end_time' => 2151.2]], 'from_sections' => true],
        );

        $gathered = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Other), $this->transcript(), $log, gatherEvidence: true);
        $read = app(CutAwareEnsembleComposer::class)->compose($this->votes(ServiceSectionType::Other), $this->transcript(), $log);

        $this->assertSame([], $gathered->disputes);
        $this->assertContains('other', array_column($read->disputes, 'type'));
    }

    /**
     * @param  callable(ServiceStructure): array<string, mixed>  $asWritten
     * @param  (callable(ServiceStructure): array<string, mixed>)|null  $withEvidence  What the probe plans after gathering
     */
    private function stubProbe(callable $asWritten, ?callable $withEvidence = null): void
    {
        $this->app->instance(SermonCutProbe::class, new class($asWritten, $withEvidence ?? $asWritten) extends SermonCutProbe
        {
            /** @var callable(ServiceStructure): array<string, mixed> */
            private $asWritten;

            /** @var callable(ServiceStructure): array<string, mixed> */
            private $withEvidence;

            public function __construct(callable $asWritten, callable $withEvidence)
            {
                $this->asWritten = $asWritten;
                $this->withEvidence = $withEvidence;
            }

            public function asWritten(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
            {
                return ($this->asWritten)($structure);
            }

            public function asWrittenWithEvidence(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
            {
                return ($this->withEvidence)($structure);
            }
        });
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @return array<string, mixed>
     */
    private function undecoded(array $spans): array
    {
        return ['mode' => 'single_span', 'strategy' => 'edge_word_timings_missing', 'segments' => [], 'from_sections' => true,
            'requires_review' => true, 'edge_word_timings_error' => 'Edge words are not decoded yet', 'structure_segments' => $spans];
    }

    /**
     * A run whose transcript and edge words are banked, so the probe plans real cuts; detection
     * answers where the sermon ends beside the closing song (K16) as it gathers.
     */
    private function decodedRun(): MediaProcessingLog
    {
        OpenAI::fake(array_fill(0, 8, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'sermon_last_line' => 1, 'ties_to_sermon' => false, 'video_last_line' => 1, 'reason' => 'test',
        ], JSON_THROW_ON_ERROR)]]]])));
        $log = MediaProcessingLog::factory()->livestream()->pending()->create(['sermon_start_time' => 600.0, 'sermon_end_time' => 2150.0, 'duration' => 2430.0]);
        $log->putServiceTranscriptPath(app(ServiceArtifactStorage::class)->putJson($log->processing_id, 'normalized', $this->transcript()->toArray()));
        $this->bankNoWordOutputEdges($log);

        return $log->fresh();
    }

    /** @return array<int, ValidationResult> */
    private function votes(ServiceSectionType $fillerType, int $fillerVotes = 2): array
    {
        $draw = fn (bool $filler): ValidationResult => new ValidationResult(ServiceStructure::fromSections(array_values(array_filter([
            new ServiceStructureSection(ServiceSectionType::Sermon, 'Sermon', 600.0, 2150.0, 0.95, null, null, null),
            $filler ? new ServiceStructureSection($fillerType, 'Filler', 2150.0, 2180.0, 0.95, null, null, null) : null,
            new ServiceStructureSection(ServiceSectionType::Song, 'Praise my soul', 2210.0, 2400.0, 0.95, null, 'Praise my soul', null),
        ]))));

        return [0 => $draw(true), 1 => $draw($fillerVotes > 1), 2 => $draw(false), 3 => $draw(false)];
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
