<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonCutProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\BanksNoWordOutputEdges;
use Tests\TestCase;

class SermonCutProbeTest extends TestCase
{
    use BanksNoWordOutputEdges;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
    }

    #[Test]
    public function it_reads_the_source_runs_banked_edge_evidence_without_changing_its_identity_or_records(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create(['duration' => 2430.0]);
        $existing = ServiceSection::factory()->create(['media_processing_log_id' => $log->id]);
        $transcript = $this->transcript();
        $storedCues = $transcript->cues;
        $storedCues[2]['start'] = 610.0;
        $storedTranscript = ChurchServiceTranscript::fromCues($storedCues, 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
        $path = app(ServiceArtifactStorage::class)->putJson($log->processing_id, 'normalized', $storedTranscript->toArray());
        $log->putServiceTranscriptPath($path);
        $evidence = app(OutputEdgeWordTimings::class);
        foreach ([420.0, 600.0, 2200.0] as $edge) {
            $window = $evidence->window($transcript->cues, $edge, $log->duration, $log);
            $this->assertNotNull($window);
            $identity = $evidence->identity($log, $window);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), [
                'identity' => $identity, 'words' => [],
            ]);
        }
        $log->refresh();
        $before = $log->getRawOriginal();
        $sectionBefore = $existing->fresh()->getRawOriginal();
        $files = Storage::disk('local')->allFiles();

        $cut = app(SermonCutProbe::class)->probe($log, $this->structure([]), $transcript);

        $this->assertSame('identified_sections', $cut['as_written']['strategy'], $cut['as_written']['edge_word_timings_error'] ?? '');
        $this->assertNotEmpty($cut['as_written']['segments']);
        $this->assertEquals(600.0, $cut['as_written']['segments'][1]['start_time']);
        $this->assertSame($before, $log->fresh()->getRawOriginal());
        $this->assertSame($sectionBefore, $existing->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(1, MediaProcessingLog::query()->count());
        $this->assertSame(1, ServiceSection::query()->count());
    }

    #[Test]
    public function genuinely_missing_edge_evidence_reports_the_source_identity_and_required_window(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create(['duration' => 2430.0]);

        $cut = app(SermonCutProbe::class)->probe($log, $this->structure([]), $this->transcript());

        $this->assertSame('edge_word_timings_missing', $cut['as_written']['strategy']);
        $this->assertStringContainsString($log->processing_id, $cut['as_written']['edge_word_timings_error']);
        $this->assertStringContainsString('419.000000–591.000000', $cut['as_written']['edge_word_timings_error']);
        $this->assertNotEmpty($cut['as_written']['structure_segments']);
        $this->assertSame([], $cut['as_written']['segments']);
        $this->assertSame(0, ServiceSection::query()->count());
    }

    #[Test]
    public function an_unasked_sermon_song_handover_is_reported_without_calling_a_provider(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create(['duration' => 2430.0]);
        $this->bankTranscript($log);
        $before = $log->fresh()->getRawOriginal();
        $files = Storage::disk('local')->allFiles();
        $structure = ServiceStructure::fromSections([
            ...$this->structure([])->sections,
            new ServiceStructureSection(ServiceSectionType::Song, 'Praise my soul', 2210.0, 2400.0, 0.95, null, 'Praise my soul', null),
        ]);

        $cut = app(SermonCutProbe::class)->probe($log, $structure, $this->transcript());

        $request = $cut['as_written']['pending_evidence']['sermon_song_introduction'];
        $this->assertSame($log->processing_id, $request['identity']['processing_id']);
        $this->assertSame(2200.0, $request['identity']['sermon_end']);
        $this->assertNotEmpty($request['lines']);
        $this->assertNotEmpty($request['prompt']);
        $this->assertSame([], $cut['as_written']['segments']);
        $this->assertSame($before, $log->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(0, ServiceSection::query()->count());
    }

    #[Test]
    public function it_plans_the_production_cut_from_a_proposal_and_writes_nothing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $existing = ServiceSection::factory()->create(['media_processing_log_id' => $log->id]);
        $logs = MediaProcessingLog::query()->count();
        $sections = ServiceSection::query()->count();
        $this->bankTranscript($log);

        $cut = app(SermonCutProbe::class)->probe($log, $this->structure([]), $this->transcript());

        $this->assertSame('concat_spans', $cut['as_written']['mode'], $cut['as_written']['error'] ?? '');
        $this->assertSame('identified_sections', $cut['as_written']['strategy']);
        // The 10 s between reading and sermon plays as the reading's; the parts meet (amended D1).
        $this->assertEquals([['start_time' => 420.0, 'end_time' => 600.0], ['start_time' => 600.0, 'end_time' => 2200.0]], $cut['as_written']['segments']);
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
        $this->bankTranscript($log);

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
        // The 10 s between reading and sermon plays as the reading's; the parts meet (amended D1).
        $this->assertEquals([['start_time' => 420.0, 'end_time' => 600.0], ['start_time' => 600.0, 'end_time' => 2200.0]], $cut['as_written']['segments']);
    }

    /**
     * Detection's probe (11-03): the edge words and the sermon-end answer a proposal's cut waits on
     * are gathered onto the run itself, so the plan is a cut and every later replay reads the same.
     */
    #[Test]
    public function gathering_banks_the_edge_words_and_sermon_end_answer_on_the_run_and_plans_the_cut(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create(['duration' => 2430.0]);
        $log->putServiceTranscriptPath(app(ServiceArtifactStorage::class)->putJson($log->processing_id, 'normalized', $this->transcript()->toArray()));
        $decoded = $this->decodeWithNoWords();
        OpenAI::fake(array_fill(0, 2, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'sermon_last_line' => 1, 'ties_to_sermon' => false, 'video_last_line' => 1, 'reason' => 'test',
        ], JSON_THROW_ON_ERROR)]]]])));

        $read = app(SermonCutProbe::class)->asWritten($log->fresh(), $this->withClosingSong(), $this->transcript());
        $gathered = app(SermonCutProbe::class)->asWrittenWithEvidence($log->fresh(), $this->withClosingSong(), $this->transcript());
        $replayed = app(SermonCutProbe::class)->asWritten($log->fresh(), $this->withClosingSong(), $this->transcript());

        $this->assertSame('edge_word_timings_missing', $read['strategy']);
        $this->assertSame('identified_sections', $gathered['strategy'], $gathered['edge_word_timings_error'] ?? '');
        $this->assertNotSame([], $decoded->windows);
        $this->assertSame($gathered['segments'], $replayed['segments']);
        $kinds = array_column(ServiceArtifactStorage::recordedFor($log->fresh()), 'kind');
        $this->assertNotEmpty(array_filter($kinds, static fn (string $kind): bool => str_starts_with($kind, 'edge-words-')));
        $this->assertSame(1, MediaProcessingLog::query()->count());
        $this->assertSame(0, ServiceSection::query()->count());
    }

    #[Test]
    public function a_gather_that_fails_returns_the_waiting_plan(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create(['duration' => 2430.0]);
        $log->putServiceTranscriptPath(app(ServiceArtifactStorage::class)->putJson($log->processing_id, 'normalized', $this->transcript()->toArray()));
        $this->app->instance(PrepareOutputEdgeWordTimings::class, new class(app(OutputEdgeWordTimings::class), app(ServiceTranscriptionInterface::class), app(ServiceAudioWindowExtractor::class), app(ServiceArtifactStorage::class), app(StorageAdapterHelper::class)) extends PrepareOutputEdgeWordTimings
        {
            public function prepareWindows(MediaProcessingLog $log, array $windows): array
            {
                throw new RuntimeException('edge_word_timings_source_missing: no archived service audio');
            }
        });

        $plan = app(SermonCutProbe::class)->asWrittenWithEvidence($log, $this->structure([]), $this->transcript());

        $this->assertSame('edge_word_timings_missing', $plan['strategy']);
        $this->assertSame([], $plan['segments']);
    }

    /** A decoder that banks every window it is asked for with no words, recording what it was asked. */
    private function decodeWithNoWords(): object
    {
        $decoder = new class(app(OutputEdgeWordTimings::class), app(ServiceTranscriptionInterface::class), app(ServiceAudioWindowExtractor::class), app(ServiceArtifactStorage::class), app(StorageAdapterHelper::class)) extends PrepareOutputEdgeWordTimings
        {
            /** @var list<array<string, mixed>> */
            public array $windows = [];

            public function prepareWindows(MediaProcessingLog $log, array $windows): array
            {
                $evidence = app(OutputEdgeWordTimings::class);
                foreach ($windows as $window) {
                    $this->windows[] = $window;
                    $identity = $evidence->identity($log, $window);
                    app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => [], 'compute_seconds' => 0.0]);
                }

                return ['windows' => count($windows), 'decoded' => count($windows), 'compute_seconds' => 0.0];
            }
        };
        $this->app->instance(PrepareOutputEdgeWordTimings::class, $decoder);

        return $decoder;
    }

    private function withClosingSong(): ServiceStructure
    {
        return ServiceStructure::fromSections([
            ...$this->structure([])->sections,
            new ServiceStructureSection(ServiceSectionType::Song, 'Praise my soul', 2210.0, 2400.0, 0.95, null, 'Praise my soul', null),
        ]);
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
        ]);
    }

    private function bankTranscript(MediaProcessingLog $log): void
    {
        $path = app(ServiceArtifactStorage::class)->putJson($log->processing_id, 'normalized', $this->transcript()->toArray());
        $log->putServiceTranscriptPath($path);
        $this->bankNoWordOutputEdges($log);
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
