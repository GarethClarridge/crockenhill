<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\DetectorEvaluation\BankedRunInputs;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Decision evidence, not adoption: apply the existing spoken dead-feed algorithm to song plans.
 * Reflection deliberately keeps the candidate out of the live pipeline pending a ruling.
 */
class SongDeadFeedCandidateTest extends TestCase
{
    /** @return array<string, array{list<array{float, float, float}>, ?float, ?float}> */
    public static function edges(): array
    {
        return [
            'two seconds at start' => [[[0.0, 2.0, -100.0], [2.0, 20.0, -25.0]], 1.7, null],
            'under two seconds' => [[[0.0, 1.9, -100.0], [1.9, 20.0, -25.0]], null, null],
            'two seconds at end' => [[[0.0, 18.0, -25.0], [18.0, 20.0, -100.0]], null, 18.0],
            'both edges' => [[[0.0, 4.0, -100.0], [4.0, 16.0, -25.0], [16.0, 20.0, -100.0]], 3.7, 16.0],
            'quiet room retained' => [[[0.0, 8.0, -62.0], [8.0, 20.0, -25.0]], null, null],
            'continuous musical decay retained' => [[[0.0, 15.0, -25.0], [15.0, 18.0, -70.0], [18.0, 20.0, -100.0]], null, 18.0],
            'interior gap retained' => [[[0.0, 8.0, -25.0], [8.0, 12.0, -100.0], [12.0, 20.0, -25.0]], null, null],
            'wholly silent needs separate containment' => [[[0.0, 20.0, -100.0]], null, null],
            'finite low level is also trimmed' => [[[0.0, 5.0, -85.0], [5.0, 20.0, -25.0]], 4.7, null],
        ];
    }

    #[Test]
    #[DataProvider('edges')]
    public function it_measures_the_candidate_without_changing_song_policy(array $ranges, ?float $start, ?float $end): void
    {
        $levels = ['samples' => $this->samples($ranges), 'threshold' => -45.0];

        $this->assertEqualsWithDelta($start ?? -1.0, $this->edge('start', $levels, 0.0, 20.0) ?? -1.0, 0.001);
        $this->assertEqualsWithDelta($end ?? -1.0, $this->edge('end', $levels, 0.0, 20.0) ?? -1.0, 0.001);
    }

    #[Test]
    public function the_candidate_can_discard_quiet_music_after_a_dead_gap(): void
    {
        $levels = ['samples' => $this->samples([
            [0.0, 10.0, -25.0], [10.0, 13.0, -100.0], [13.0, 16.0, -60.0], [16.0, 20.0, -100.0],
        ]), 'threshold' => -45.0];

        $end = $this->edge('end', $levels, 0.0, 20.0);

        $this->assertEqualsWithDelta(10.0, $end, 0.001);
        $this->assertLessThan(16.0, $end, 'The spoken algorithm ignores the later quiet musical run: unsafe to adopt indiscriminately.');
    }

    /**
     * Opt-in local measurement. Every corpus query uses a separate READ ONLY transaction;
     * the default testing connection is never repointed and no factory or migration runs here.
     */
    #[Test]
    public function it_replays_local_song_plans_read_only_when_requested(): void
    {
        if (getenv('SONG_DEAD_FEED_CORPUS') !== '1') {
            $this->markTestSkipped('Set SONG_DEAD_FEED_CORPUS=1 for the local corpus evidence report.');
        }

        $this->assertSame('testing', config('database.connections.mysql.database'));
        config(['database.connections.song_edge_corpus' => [
            ...config('database.connections.mysql'), 'database' => 'crockenhill',
        ]]);
        $connection = DB::connection('song_edge_corpus');
        $connection->statement('SET TRANSACTION READ ONLY');
        $connection->beginTransaction();
        $report = ['candidate' => 'spoken 2-second algorithm after current song plan; not adopted',
            'assessed' => 0, 'unchanged' => 0, 'moves' => [], 'unassessable' => []];
        $selected = array_values(array_filter(array_map('intval', explode(',', (string) getenv('SONG_DEAD_FEED_SECTION_IDS')))));
        $report['requested_section_ids'] = $selected;
        $path = storage_path('scratch/song-dead-feed-candidate-20261016.json');
        $encoder = config('media-processing.video_extraction.encoder');

        try {
            $runs = MediaProcessingLog::on('song_edge_corpus')->whereNotNull('historic_import_operation_id')
                ->whereNull('superseded_at')->orderBy('id')->get();

            foreach ($runs as $run) {
                if ($run->isExcluded()) {
                    continue;
                }

                $version = (int) data_get($run->processing_metadata?->toArray(), 'sermon_extraction_plan.media_processing.version', 8);
                config(['media-processing.media_processing_version' => $version,
                    'media-processing.video_extraction.reencode_preset' => data_get($run->processing_metadata?->toArray(), 'sermon_extraction_plan.media_processing.video.preset', 'veryfast'),
                    'media-processing.video_extraction.encoder' => $version >= 9 ? $encoder : 'libx264']);

                $sections = ServiceSection::on('song_edge_corpus')->where('media_processing_log_id', $run->id)
                    ->where('section_type', 'song')->when($selected !== [], static fn ($query) => $query->whereIn('id', $selected))
                    ->orderBy('id')->get();

                if ($sections->isEmpty()) {
                    continue;
                }

                app(BankedRunInputs::class)->within($run, function () use ($run, $sections, &$report): void {
                    $planner = app(CueSafeExtractionPlan::class);
                    $levels = (new ReflectionMethod($planner, 'levels'))->invoke($planner, $run);

                    foreach ($sections as $section) {
                        try {
                            if ($levels === null) {
                                $report['unassessable'][] = ['run' => $run->id, 'section' => $section->id, 'reason' => 'missing RMS'];

                                continue;
                            }

                            $plan = $planner->forSpans($run, [['start_time' => (float) $section->start_time,
                                'end_time' => (float) $section->end_time]], songEnds: true);
                            $report['assessed']++;
                            $changed = false;

                            foreach ($plan['segments'] as $span) {
                                foreach (['start', 'end'] as $edge) {
                                    $time = $this->edge($edge, $levels, $span['start_time'], $span['end_time']);
                                    $before = $span[$edge.'_time'];

                                    if ($time === null || abs($time - $before) < 0.001) {
                                        continue;
                                    }

                                    $removed = array_values(array_filter($levels['samples'], static fn (array $sample): bool => $sample['time'] >= min($before, $time) && $sample['time'] < max($before, $time)));
                                    $nonDead = array_values(array_filter($removed, static fn (array $sample): bool => $sample['rms'] > -80.0));
                                    $changed = true;
                                    $report['moves'][] = ['run' => $run->id, 'section' => $section->id, 'edge' => $edge,
                                        'from' => $before, 'to' => $time, 'seconds' => abs($time - $before),
                                        'threshold' => $levels['threshold'], 'removed_samples' => count($removed),
                                        'non_dead_samples_removed' => count($nonDead),
                                        'removed_peak_db' => $removed === [] ? null : max(array_column($removed, 'rms')),
                                        'audio_fade_out' => $plan['audio_fade_out'],
                                        'source_span' => [(float) $section->start_time, (float) $section->end_time],
                                        'clip' => $section->extracted_video_path];
                                }
                            }

                            if (! $changed) {
                                $report['unchanged']++;
                            }
                        } catch (Throwable $exception) {
                            $report['unassessable'][] = ['run' => $run->id, 'section' => $section->id,
                                'reason' => $exception->getMessage()];
                        }
                    }
                });
                file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
                fwrite(STDOUT, sprintf("\nRun %d: %d assessed so far", $run->id, $report['assessed']));
            }
        } finally {
            $connection->rollBack();
            DB::disconnect('song_edge_corpus');
        }

        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $this->assertGreaterThan(0, $report['assessed'], 'No corpus evidence was assessable.');
        fwrite(STDOUT, sprintf("\nSong candidate: %d assessed, %d unchanged, %d moved edges, %d unassessable. Report: %s\n",
            $report['assessed'], $report['unchanged'], count($report['moves']), count($report['unassessable']), $path));
    }

    /** @param array{samples: list<array{time: float, rms: float}>, threshold: float} $levels */
    private function edge(string $edge, array $levels, float $start, float $end): ?float
    {
        $planner = app(CueSafeExtractionPlan::class);

        return (new ReflectionMethod($planner, $edge === 'start' ? 'firstSoundAfterDeadFeed' : 'deadFeedAfterLastSound'))
            ->invoke($planner, $levels, $start, $end);
    }

    /**
     * @param  list<array{float, float, float}>  $ranges
     * @return list<array{time: float, rms: float}>
     */
    private function samples(array $ranges): array
    {
        $samples = [];

        foreach ($ranges as [$start, $end, $level]) {
            for ($tenth = (int) round($start * 10); $tenth < (int) round($end * 10); $tenth++) {
                $samples[] = ['time' => $tenth / 10, 'rms' => $level];
            }
        }

        return $samples;
    }
}
