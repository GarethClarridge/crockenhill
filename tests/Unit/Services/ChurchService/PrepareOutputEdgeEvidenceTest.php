<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\DeadFeedExcision;
use App\Services\ChurchService\DependentSongOpening;
use App\Services\ChurchService\OutputEdgeDecodeWindow;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeEvidence;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use App\Services\ChurchService\PublicationPlanValidator;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\ChurchService\SongTailSpeechOnset;
use App\Services\ChurchService\SpokenEdgeSentenceCheck;
use App\Services\ChurchService\Structure\SermonContinuationScreen;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Scripture\SpokenPassage;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Application;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PrepareOutputEdgeEvidenceTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__, 4));
        $this->app->instance('config', new Repository);
        $this->app->instance(Generator::class, Factory::create('en_GB'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->flush();
        Application::setInstance(null);

        parent::tearDown();
    }

    /** WP19 R3: T stems use the replaced cut even when it was silence, and survive an outro move. */
    #[Test]
    public function fade_cuts_include_a_tail_ends_replaced_cut(): void
    {
        $reader = (new \ReflectionClass(PrepareOutputEdgeEvidence::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($reader, 'fadeCuts');
        $entry = ['edge' => 'end', 'reason' => SongTailSpeechOnset::REASON,
            'time' => 3775.26, 'original_time' => 3779.0,
            'replaced' => ['time' => 3772.51, 'reason' => 'song_end_silence']];

        self::assertSame([['cut' => 3772.51, 'tail_end' => 3779.0]], $method->invoke($reader, [$entry]));
        $entry['time'] = 3773.8;
        $entry['song_end_outro'] = 'silence';
        self::assertSame([['cut' => 3772.51, 'tail_end' => 3779.0]], $method->invoke($reader, [$entry]));
        $entry['replaced'] = null;
        self::assertSame([['cut' => 3779.0, 'tail_end' => 3779.0]], $method->invoke($reader, [$entry]));
        $entry['edge'] = 'start';
        self::assertSame([], $method->invoke($reader, [$entry]));
    }

    #[Test]
    #[TestWith([10.78, 233.11])]
    #[TestWith([7.95, 67.88])]
    #[TestWith([4.06, 75.0])]
    public function preparation_enumerates_the_opening_composed_before_an_unsectioned_introduction(float $firstCueEnd, float $readingStart): void
    {
        $reading = ServiceSection::factory()->make(['id' => 1, 'media_processing_log_id' => null,
            'church_service_item_id' => null, 'section_type' => ServiceSectionType::BibleReading,
            'start_time' => $readingStart, 'end_time' => 300.0, 'metadata' => ['reading_reference' => 'John 3:1-16']]);
        $sermon = ServiceSection::factory()->make(['id' => 2, 'media_processing_log_id' => null,
            'church_service_item_id' => null, 'section_type' => ServiceSectionType::Sermon,
            'start_time' => 300.0, 'end_time' => 900.0, 'metadata' => ['sermon_reference' => 'John 3:1-16']]);
        $sections = new Collection([$reading, $sermon]);
        $query = Mockery::mock(HasMany::class);
        $query->shouldReceive('orderBy', 'whereIn')->andReturnSelf();
        $query->shouldReceive('get')->andReturn($sections);
        $run = Mockery::mock(MediaProcessingLog::class)->makePartial();
        $run->setRawAttributes(MediaProcessingLog::factory()->livestream()->make(['duration' => 1000.0])->getAttributes());
        $run->setRelation('churchService', null);
        $run->shouldReceive('serviceSections')->andReturn($query);
        $run->shouldReceive('refresh', 'fresh')->andReturnSelf();
        $run->shouldReceive('storedServiceTranscriptContents')->andReturnNull();
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => $firstCueEnd, 'text' => 'Please turn to John chapter three.'],
        ], 1000.0, ChurchServiceTranscript::SOURCE_MOCK);
        $cutPlans = Mockery::mock(CueSafeExtractionPlan::class);
        $cutPlans->shouldReceive('audioTimeline')->andReturnNull();
        $cutPlans->shouldReceive('spokenEndsBesideSongs')->andReturn([]);
        $this->app->instance(CueSafeExtractionPlan::class, $cutPlans);
        $deadFeed = Mockery::mock(DeadFeedExcision::class);
        $deadFeed->shouldReceive('identity')->andReturnNull();
        $deadFeed->shouldReceive('prepare')->once();
        $this->app->instance(DeadFeedExcision::class, $deadFeed);
        $references = Mockery::mock(ScriptureReferenceResolver::class);
        $references->shouldReceive('sermonReadingMembership')->andReturn(['selected' => [0], 'review' => false, 'could_be_cut' => []]);
        $references->shouldReceive('normalizeAll')->andReturn('John 3:1-16');
        $resolver = new SermonExtractionPlanResolver($references, new SermonContinuationScreen,
            Mockery::mock(PublicationPlanValidator::class), new SpokenPassage($references), new SermonExposurePolicy);

        $composition = $resolver->compose($run, $transcript);

        self::assertSame(['reading_section_id' => 1, 'start_time' => 0.0, 'end_time' => $readingStart], $composition['introduction']);
        $cutPlans->shouldReceive('forSpans')->once()->andThrow(new OutputEdgeTimingsMissing('No decoding in this test.'));
        $plan = $resolver->resolve($run, transcript: $transcript);
        self::assertSame(0.0, $plan['metadata']['structure_segments'][0]['start_time']);

        $enumerated = $this->captureSpans($run, $resolver, $composition, $cutPlans, $deadFeed);
        self::assertContains([0.0, $firstCueEnd + 1.0], $this->windows($run, $enumerated, $transcript),
            'The composed introduction opening must be enumerated for word timings.');
    }

    /**
     * Section edges and touching cues read from the ten failed runs in the 11-12 postflight.
     *
     * @param  list<array{0: float, 1: float}>  $cues
     * @param  array{0: float, 1: float}  $expected
     */
    #[Test]
    #[TestWith(['bible_reading', 0.0, [[0.0, 1.62]], [0.0, 2.62]], '1003')]
    #[TestWith(['bible_reading', 13.22, [[13.22, 17.2]], [12.22, 18.2]], '1039')]
    #[TestWith(['sermon', 0.0, [[0.0, 20.68]], [0.0, 21.68]], '1084')]
    #[TestWith(['prayer', 50.23, [[42.44, 50.230000000000004], [50.230000000000004, 54.660000000000004]], [41.44, 55.66]], '1095')]
    #[TestWith(['bible_reading', 0.0, [[0.0, 4.38]], [0.0, 5.38]], '1107')]
    #[TestWith(['sermon', 0.0, [[0.0, 11.96]], [0.0, 12.96]], '1121')]
    #[TestWith(['sermon', 0.0, [[0.0, 14.08]], [0.0, 15.08]], '1176')]
    #[TestWith(['sermon', 0.0, [[0.0, 17.27]], [0.0, 18.27]], '1187')]
    #[TestWith(['sermon', 0.0, [[0.0, 4.42]], [0.0, 5.42]], '1373')]
    #[TestWith(['sermon', 0.0, [[0.0, 15.09]], [0.0, 16.09]], '1380')]
    public function explicitly_selected_failed_runs_enumerate_their_missing_section_edge_window(string $type, float $edge, array $cues, array $expected): void
    {
        $section = ServiceSection::factory()->make(['id' => 1, 'media_processing_log_id' => null,
            'church_service_item_id' => null, 'section_type' => ServiceSectionType::from($type),
            'start_time' => $edge, 'end_time' => 900.0]);
        $query = Mockery::mock(HasMany::class);
        $query->shouldReceive('orderBy', 'whereIn')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new Collection([$section]));
        $run = Mockery::mock(MediaProcessingLog::class)->makePartial();
        $run->setRawAttributes(MediaProcessingLog::factory()->livestream()->make(['duration' => 1000.0, 'status' => ProcessingStatus::Failed])->getAttributes());
        $run->shouldReceive('serviceSections')->andReturn($query);
        $run->shouldReceive('refresh', 'fresh')->andReturnSelf();
        $resolver = Mockery::mock(SermonExtractionPlanResolver::class)->makePartial();
        $cutPlans = Mockery::mock(CueSafeExtractionPlan::class);
        $cutPlans->shouldReceive('spokenEndsBesideSongs')->andReturn([]);
        $deadFeed = Mockery::mock(DeadFeedExcision::class);
        $deadFeed->shouldReceive('prepare')->once();
        $transcript = ChurchServiceTranscript::fromCues(array_map(static fn (array $cue): array => [
            'start' => $cue[0], 'end' => $cue[1], 'text' => 'Spoken words.',
        ], $cues), 1000.0, ChurchServiceTranscript::SOURCE_MOCK);

        $spans = $this->captureSpans($run, $resolver, ['selected_section_ids' => [1]], $cutPlans, $deadFeed);
        $windows = $this->windows($run, $spans, $transcript);

        self::assertTrue(array_any($windows, static fn (array $window): bool => abs($window[0] - $expected[0]) < 0.000001
            && abs($window[1] - $expected[1]) < 0.000001), 'The missing section-edge window must be enumerated.');
    }

    /** WP62 item 1: the planner ends a selected section a fraction past the recording at the recording's end. */
    #[Test]
    public function preparation_enumerates_a_selected_section_end_as_the_planner_bounds_it(): void
    {
        $section = ServiceSection::factory()->make(['id' => 1, 'media_processing_log_id' => null,
            'church_service_item_id' => null, 'section_type' => ServiceSectionType::Sermon,
            'start_time' => 100.0, 'end_time' => 1000.004]);
        $query = Mockery::mock(HasMany::class);
        $query->shouldReceive('orderBy', 'whereIn')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new Collection([$section]));
        $run = Mockery::mock(MediaProcessingLog::class)->makePartial();
        $run->setRawAttributes(MediaProcessingLog::factory()->livestream()->make(['duration' => 1000.0])->getAttributes());
        $run->shouldReceive('serviceSections')->andReturn($query);
        $run->shouldReceive('refresh', 'fresh')->andReturnSelf();
        $cutPlans = Mockery::mock(CueSafeExtractionPlan::class);
        $cutPlans->shouldReceive('spokenEndsBesideSongs')->andReturn([]);
        $deadFeed = Mockery::mock(DeadFeedExcision::class);
        $deadFeed->shouldReceive('prepare')->once();

        $spans = $this->captureSpans($run, Mockery::mock(SermonExtractionPlanResolver::class)->makePartial(), ['selected_section_ids' => [1]], $cutPlans, $deadFeed);

        self::assertNotEmpty($spans);
        foreach ($spans as $span) {
            self::assertSame(1000.0, $span['end_time'], 'Preparation must ask for the end the plan cuts at, not the raw section end.');
        }
    }

    /** WP62 item 2: the dependent opening is decoded where the plan reviewed it, not where an override left the cut. */
    #[Test]
    public function preparation_decodes_the_dependent_opening_window_the_plan_asked_for(): void
    {
        $song = ServiceSection::factory()->make(['id' => 1, 'media_processing_log_id' => null,
            'church_service_item_id' => null, 'section_type' => ServiceSectionType::Song,
            'start_time' => 100.0, 'end_time' => 400.0]);
        $song->setRelation('churchServiceItem', null);
        $query = Mockery::mock(HasMany::class);
        $query->shouldReceive('orderBy', 'whereIn', 'where')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new Collection([$song]));
        $run = Mockery::mock(MediaProcessingLog::class)->makePartial();
        $run->setRawAttributes(MediaProcessingLog::factory()->livestream()->make(['duration' => 1000.0])->getAttributes());
        $run->setRelation('churchService', null);
        $run->shouldReceive('serviceSections')->andReturn($query);
        $run->shouldReceive('refresh', 'fresh')->andReturnSelf();
        $decodeWindows = new OutputEdgeDecodeWindow;
        $this->app->instance(OutputEdgeDecodeWindow::class, $decodeWindows);
        $cues = [['start' => 100.2, 'end' => 103.0, 'text' => 'And then let us sing together.'],
            ['start' => 130.0, 'end' => 133.0, 'text' => 'The next verse follows on.']];
        $timings = Mockery::mock(OutputEdgeWordTimings::class, [$decodeWindows])->makePartial();
        $timings->shouldReceive('cues')->andReturn($cues);
        $this->app->instance(OutputEdgeWordTimings::class, $timings);
        $this->app->instance(OutputEdgeDecodeWindow::class, $decodeWindows);
        $planned = app(DependentSongOpening::class)->window($run, $cues, 100.0);
        self::assertNotNull($planned, 'The plan reviewed the cut at 100.0, where a linking announcement opens the song.');
        $cutPlans = Mockery::mock(CueSafeExtractionPlan::class);
        $cutPlans->shouldReceive('spokenEndsBesideSongs', 'songEndSpeechEdges')->andReturn([]);
        $cutPlans->shouldReceive('forSpans')->andReturn(['segments' => [['start_time' => 130.0, 'end_time' => 400.0]],
            'cue_edge_widening' => [['span_index' => 0, 'edge' => 'start', 'original_time' => 100.0, 'time' => 130.0,
                'reason' => 'song_start_dependent', 'dependent_opening' => ['window' => $planned]]]]);
        $deadFeed = Mockery::mock(DeadFeedExcision::class);
        $deadFeed->shouldReceive('prepare')->once();
        $resolver = Mockery::mock(SermonExtractionPlanResolver::class)->makePartial();
        $resolver->shouldReceive('compose')->andReturn(['selected_section_ids' => []]);
        $introductionCheck = Mockery::mock(SermonSongIntroductionCheck::class);
        $introductionCheck->shouldReceive('prepare')->andReturn([]);
        $introductionCheck->shouldReceive('innerParts')->andReturn([]);
        $sentenceCheck = Mockery::mock(SpokenEdgeSentenceCheck::class);
        $sentenceCheck->shouldReceive('outputEdges')->andReturn([]);
        $sentenceCheck->shouldReceive('prepare')->andReturn([]);
        $prepare = Mockery::mock(PrepareOutputEdgeWordTimings::class);
        $prepare->shouldReceive('prepare')->andReturn([]);
        $asked = [];
        $prepare->shouldReceive('prepareWindows')->andReturnUsing(function (MediaProcessingLog $log, array $windows) use (&$asked): array {
            array_push($asked, ...array_values($windows));

            return [];
        });

        (new PrepareOutputEdgeEvidence($prepare, $resolver, $cutPlans, $sentenceCheck, $introductionCheck, $deadFeed, Mockery::mock(StorageAdapterHelper::class)))->prepare($run);

        self::assertContains($planned, $asked, 'Preparation must decode the window the plan requests for the dependent opening.');
    }

    /**
     * @param  array<string, mixed>  $composition
     * @return list<array{start_time: float, end_time: float}>
     */
    private function captureSpans(MediaProcessingLog $run, SermonExtractionPlanResolver $resolver, array $composition, CueSafeExtractionPlan $cutPlans, DeadFeedExcision $deadFeed): array
    {
        $preparedResolver = $resolver instanceof MockInterface ? $resolver : Mockery::mock($resolver)->makePartial();
        $preparedResolver->shouldReceive('compose')->once()->with($run)->andReturn($composition);
        $introductionCheck = Mockery::mock(SermonSongIntroductionCheck::class);
        $introductionCheck->shouldReceive('prepare')->andReturn([]);
        $introductionCheck->shouldReceive('innerParts')->andReturn([]);
        $enumerated = [];
        $prepare = Mockery::mock(PrepareOutputEdgeWordTimings::class);
        $prepare->shouldReceive('prepare')->once()->andReturnUsing(function (MediaProcessingLog $log, array $spans) use (&$enumerated): never {
            $enumerated = $spans;

            throw new RuntimeException('Enumeration captured; no evidence is written.');
        });
        $evidence = new PrepareOutputEdgeEvidence($prepare, $preparedResolver, $cutPlans,
            Mockery::mock(SpokenEdgeSentenceCheck::class), $introductionCheck, $deadFeed, Mockery::mock(StorageAdapterHelper::class));

        try {
            $evidence->prepare($run);
            self::fail('The test must stop before evidence preparation.');
        } catch (RuntimeException $exception) {
            self::assertSame('Enumeration captured; no evidence is written.', $exception->getMessage());
        }

        return $enumerated;
    }

    #[Test]
    #[TestWith([0.0, 1, 0.0])]
    #[TestWith([40.0, 1, 40.0])]
    #[TestWith([0.0, 2, 100.0])]
    public function only_the_introductions_selected_opening_reading_moves(float $opening, int $readingId, float $expected): void
    {
        $section = ServiceSection::factory()->make(['id' => 1, 'media_processing_log_id' => null,
            'church_service_item_id' => null, 'section_type' => ServiceSectionType::BibleReading,
            'start_time' => 100.0, 'end_time' => 200.0]);
        $spans = [['start_time' => 100.0, 'end_time' => 200.0], ['start_time' => 210.0, 'end_time' => 900.0]];
        $resolver = Mockery::mock(SermonExtractionPlanResolver::class)->makePartial();

        self::assertSame([['start_time' => $expected, 'end_time' => 200.0], $spans[1]],
            $resolver->withIntroduction($spans, [$section], ['reading_section_id' => $readingId, 'start_time' => $opening, 'end_time' => 100.0]));
        self::assertSame($spans, $resolver->withIntroduction($spans, [$section], null));
        self::assertSame([], $resolver->withIntroduction([], [], ['reading_section_id' => $readingId, 'start_time' => $opening, 'end_time' => 100.0]));
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @return list<array{0: float, 1: float}>
     */
    private function windows(MediaProcessingLog $run, array $spans, ChurchServiceTranscript $transcript): array
    {
        $decodeWindows = new OutputEdgeDecodeWindow;
        $this->app->instance(OutputEdgeDecodeWindow::class, $decodeWindows);
        $windowPreparer = new PrepareOutputEdgeWordTimings(new OutputEdgeWordTimings($decodeWindows),
            Mockery::mock(ServiceTranscriptionInterface::class), Mockery::mock(ServiceAudioWindowExtractor::class),
            new ServiceArtifactStorage, Mockery::mock(StorageAdapterHelper::class));

        return array_values(array_map(static fn (array $window): array => [$window['start'], $window['end']],
            $windowPreparer->windows($run, $spans, $transcript)));
    }
}
