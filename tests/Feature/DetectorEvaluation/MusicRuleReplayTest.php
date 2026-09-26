<?php

declare(strict_types=1);

namespace Tests\Feature\DetectorEvaluation;

use App\Data\ChurchServiceTranscript;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\DetectorEvaluation\MusicRuleReplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * The §8 rule replay: each music and silence rule's actions over banked structure, attributed to
 * the rule that took them, and nothing written.
 */
class MusicRuleReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('media-processing.storage.temp_disk', 'local');
        config()->set('media-processing.storage.transcript_disk', 'local');
    }

    /** 1028: the song widens into the `other` across music, and the `other` keeps its speech. */
    #[Test]
    public function it_reports_a_widening_into_an_interior_other_and_the_neighbour_it_shrank(): void
    {
        $run = $this->bankedRun(
            [['prayer', 800.0, 975.0], ['song', 977.9, 1089.0], ['other', 1089.0, 1181.0], ['sermon', 1181.0, 2000.0]],
            [[0, 975, 0.05, 0.9], [975, 1120, 0.9, 0.3], [1120, 2000, 0.05, 0.9]],
            [[0, 2000, 'speech']],
        );

        $actions = $this->replay()->forRun($run)['actions'];

        $this->assertSame(['r2_widening', 'r2_neighbour'], array_column($actions, 'rule'));
        $this->assertSame([[977.9, 1089.0], [977.9, 1120.0]], [$actions[0]['before'], $actions[0]['after']]);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_WIDENED_INTO_MUSIC, $actions[0]['flags']);
        $this->assertSame([[1089.0, 1181.0], [1120.0, 1181.0]], [$actions[1]['before'], $actions[1]['after']]);
    }

    /** 1262: the whole `other` is music, so the song takes it and the `other` goes. */
    #[Test]
    public function it_reports_a_neighbour_the_widening_removed(): void
    {
        $run = $this->bankedRun(
            [['prayer', 900.0, 1088.0], ['other', 1090.0, 1121.0], ['song', 1121.0, 1400.0], ['bible_reading', 1405.0, 1700.0]],
            [[0, 1090, 0.05, 0.9], [1090, 1400, 0.9, 0.1], [1400, 1700, 0.05, 0.9]],
            [[0, 1700, 'speech']],
        );

        $actions = $this->replay()->forRun($run)['actions'];

        $this->assertSame(['r2_widening', 'r2_neighbour'], array_column($actions, 'rule'));
        $this->assertSame([1090.0, 1400.0], $actions[0]['after']);
        $this->assertSame([[1090.0, 1121.0], null], [$actions[1]['before'], $actions[1]['after']]);
    }

    /** 1304, canary 4: an interior `other` heard as music throughout. */
    #[Test]
    public function it_reports_a_song_proposed_in_place_of_an_other(): void
    {
        $run = $this->bankedRun(
            [['bible_reading', 100.0, 261.0], ['other', 261.0, 360.0], ['prayer', 360.0, 500.0], ['sermon', 500.0, 1500.0]],
            [[0, 261, 0.05, 0.9], [261, 360, 0.9, 0.2], [360, 1500, 0.05, 0.9]],
            [[0, 1500, 'speech']],
        );

        $actions = $this->replay()->forRun($run)['actions'];

        $this->assertSame(['r3_proposal'], array_column($actions, 'rule'));
        $this->assertSame('song', $actions[0]['type']);
        $this->assertSame([[261.0, 360.0], [261.0, 360.0]], [$actions[0]['before'], $actions[0]['after']]);
        $this->assertContains(ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING, $actions[0]['flags']);
    }

    /**
     * 1346 §4377's end moves out of digital zero with no hold; 1050 §1584 lies wholly over a
     * dead feed and is held.
     */
    #[Test]
    public function it_reports_dead_feed_edge_moves_and_flags_on_songs(): void
    {
        $run = $this->bankedRun(
            [['sermon', 0.0, 600.0], ['song', 610.0, 800.0], ['prayer', 800.0, 880.0], ['song', 884.0, 916.0]],
            [],
            [[0, 700, 'speech'], [700, 870, 'dead'], [870, 920, 'dead']],
            audioSeconds: 920.0,
        );

        $actions = $this->replay()->forRun($run)['actions'];
        $byRule = array_column($actions, null, 'rule');

        $this->assertSame([610.0, 800.0], $byRule['r1_edge']['before']);
        $this->assertEqualsWithDelta(700.0, $byRule['r1_edge']['after'][1], 0.05);
        $this->assertSame([884.0, 916.0], $byRule['r1_flag']['before']);
        $this->assertStringContainsString('The whole song lies over a dead feed', implode(' ', $byRule['r1_flag']['notes']));
    }

    /** A run the rules cannot be replayed over reads as unknown, never as clean. */
    #[Test]
    public function it_reports_a_run_without_a_timeline_as_unassessable(): void
    {
        $run = $this->bankedRun([['sermon', 0.0, 600.0]], [], [[0, 600, 'speech']], audioSeconds: 600.0);
        $run->forceFill(['audio_timeline_path' => null])->save();

        $report = $this->replay()->over([$run]);

        $this->assertSame(0, $report['runs_assessed']);
        $this->assertSame([['run' => (int) $run->id, 'missing' => ['audio_timeline']]], $report['unassessable']);
    }

    #[Test]
    public function it_counts_actions_by_rule_and_by_service_year(): void
    {
        $run = $this->bankedRun(
            [['bible_reading', 100.0, 261.0], ['other', 261.0, 360.0], ['prayer', 360.0, 500.0], ['sermon', 500.0, 1500.0]],
            [[261, 360, 0.9, 0.2]],
            [[0, 1500, 'speech']],
        );
        $run->forceFill(['church_service_id' => ChurchService::factory()->create(['date' => '2024-03-10'])->id])->save();

        $report = $this->replay()->over([$run->fresh()]);

        $this->assertSame(1, $report['runs_assessed']);
        $this->assertSame(['r3_proposal' => 1], $report['actions_by_rule']);
        $this->assertSame(['r3_proposal' => 1], $report['runs_by_rule']);
        $this->assertSame(['2024' => ['r3_proposal' => 1]], $report['actions_by_year']);
    }

    /**
     * @param  list<array{0: string, 1: float, 2: float}>  $sections
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>  $timeline
     * @param  list<array{0: int, 1: int, 2: 'speech'|'dead'}>  $sound
     */
    private function bankedRun(array $sections, array $timeline, array $sound, float $audioSeconds = 2000.0): MediaProcessingLog
    {
        Storage::disk('local')->put('rms/run.log', $this->rmsLog($sound));
        Storage::disk('local')->put('temp/run.classes.json', AudioTimelineFixture::json($timeline, $audioSeconds));
        Storage::disk('local')->put('service-transcripts/run.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 10.0, 'end' => 20.0, 'text' => 'Good morning.'],
        ], $audioSeconds, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER)->toArray(), JSON_THROW_ON_ERROR));

        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'rms_log_path' => 'rms/run.log',
            'audio_timeline_path' => 'temp/run.classes.json',
            'processing_metadata' => [
                'service_transcript_path' => 'service-transcripts/run.json',
                'service_structure' => ['sections' => array_map(static fn (array $section): array => [
                    'type' => $section[0],
                    'start_time' => $section[1],
                    'end_time' => $section[2],
                    'confidence' => 0.9,
                ], $sections)],
            ],
        ]);
    }

    /**
     * Speech pausing every three seconds, so no sustained sound reads as singing, or a dead feed
     * of digital zero.
     *
     * @param  list<array{0: int, 1: int, 2: 'speech'|'dead'}>  $spans
     */
    private function rmsLog(array $spans): string
    {
        $end = max(array_map(static fn (array $span): int => $span[1], $spans));
        $lines = [];

        for ($tenth = 0; $tenth < $end * 10; $tenth++) {
            $time = $tenth / 10;
            $level = 'lavfi.astats.Overall.RMS_level=-60.0';

            foreach ($spans as [$from, $to, $kind]) {
                if ($time >= $from && $time < $to) {
                    $level = $kind === 'dead'
                        ? 'lavfi.astats.Overall.RMS_level=-inf'
                        : sprintf('lavfi.astats.Overall.RMS_level=%.1f', fmod($time, 3.0) < 2.5 ? -25.0 : -60.0);
                }
            }

            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = $level;
        }

        return implode("\n", $lines)."\n";
    }

    private function replay(): MusicRuleReplay
    {
        return app(MusicRuleReplay::class);
    }
}
