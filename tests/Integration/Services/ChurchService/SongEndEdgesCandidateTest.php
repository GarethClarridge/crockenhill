<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\CueSafeExtractionPlan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** Run the approved song-start rule through the real planner and all existing song-end fixtures. */
class SongEndEdgesCandidateTest extends SongEndEdgesTest
{
    #[Test]
    public function verified_zero_at_a_song_start_is_trimmed_but_finite_quiet_audio_is_preserved(): void
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = new MediaProcessingLog(['duration' => 20, 'rms_log_path' => 'service-transcripts/candidate.rms.log']);

        foreach (['-inf' => 3.7, '-85' => 0.0] as $level => $expectedStart) {
            $lines = [];

            for ($tenth = 0; $tenth < 200; $tenth++) {
                $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $tenth / 10);
                $lines[] = 'lavfi.astats.Overall.RMS_level='.($tenth < 40 ? $level : '-25');
            }

            Storage::disk('local')->put($log->rms_log_path, implode("\n", $lines));
            $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 0.0, 'end_time' => 20.0]], songEnds: true);

            $this->assertEqualsWithDelta($expectedStart, $plan['segments'][0]['start_time'], 0.001);
            $this->assertSame(20.0, $plan['segments'][0]['end_time']);
        }
    }

    /** @return array<string, array{list<array{float, float, string}>, float}> */
    public static function openings(): array
    {
        return [
            'exactly two seconds' => [[[0.0, 2.0, '-inf'], [2.0, 20.0, '-25']], 1.7],
            'under two seconds' => [[[0.0, 1.9, '-inf'], [1.9, 20.0, '-25']], 0.0],
            'finite minus 100 is audio' => [[[0.0, 4.0, '-100'], [4.0, 20.0, '-25']], 0.0],
            'quiet first note' => [[[0.0, 4.0, '-inf'], [4.0, 8.0, '-70'], [8.0, 20.0, '-25']], 3.7],
            'isolated quiet note before later music' => [[[0.0, 4.0, '-inf'], [4.0, 4.2, '-70'], [4.2, 8.0, '-inf'], [8.0, 20.0, '-25']], 3.7],
            'short live blip must survive' => [[[0.0, 4.0, '-inf'], [4.0, 4.1, '-25'], [4.1, 8.0, '-inf'], [8.0, 20.0, '-25']], 3.7],
            'interior zeros' => [[[0.0, 4.0, '-25'], [4.0, 8.0, '-inf'], [8.0, 20.0, '-25']], 0.0],
            'end zeros' => [[[0.0, 16.0, '-25'], [16.0, 20.0, '-inf']], 0.0],
            'whole song zero' => [[[0.0, 20.0, '-inf']], 0.0],
            'missing opening samples' => [[[5.0, 8.0, '-inf'], [8.0, 20.0, '-25']], 0.0],
            'gap inside the zero prefix' => [[[0.0, 2.0, '-inf'], [5.0, 8.0, '-inf'], [8.0, 20.0, '-25']], 0.0],
        ];
    }

    /** @param list<array{float, float, string}> $ranges */
    #[Test]
    #[DataProvider('openings')]
    public function only_a_verified_zero_prefix_moves_the_song_start(array $ranges, float $expected): void
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = new MediaProcessingLog(['duration' => 20, 'rms_log_path' => 'service-transcripts/song-zero.rms.log']);
        $lines = [];

        for ($tenth = 0; $tenth < 200; $tenth++) {
            foreach ($ranges as [$from, $until, $level]) {
                if ($tenth / 10 >= $from && $tenth / 10 < $until) {
                    $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $tenth / 10);
                    $lines[] = 'lavfi.astats.Overall.RMS_level='.$level;
                }
            }
        }

        Storage::disk('local')->put($log->rms_log_path, implode("\n", $lines));
        $planner = app(CueSafeExtractionPlan::class);
        $plan = $planner->forSpans($log, [['start_time' => 0.0, 'end_time' => 20.0]], songEnds: true);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['start_time'], 0.001);
        $this->assertSame(20.0, $plan['segments'][0]['end_time'], 'Song ends must not change.');
        $this->assertNull($plan['audio_fade_out']);
        $again = $planner->forSpans($log, $plan['segments'], songEnds: true);
        $this->assertSame($plan['segments'], $again['segments'], 'Repeated planning must not drift.');

        if ($expected > 0.0) {
            $this->assertCount(1, $plan['cue_edge_widening']);
            $edge = $plan['cue_edge_widening'][0];
            $this->assertSame('start', $edge['edge']);
            $this->assertSame(0.0, $edge['original_time']);
            $this->assertEqualsWithDelta($expected, $edge['time'], 0.001);
            $this->assertEqualsWithDelta($expected, $edge['seconds_moved'], 0.001);
        }
    }

    #[Test]
    public function missing_rms_leaves_the_song_start_unchanged(): void
    {
        $plan = app(CueSafeExtractionPlan::class)->forSpans(new MediaProcessingLog(['duration' => 20]),
            [['start_time' => 0.0, 'end_time' => 20.0]], songEnds: true);

        $this->assertSame([['start_time' => 0.0, 'end_time' => 20.0]], $plan['segments']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function acceptedStarts(): array
    {
        $fixture = json_decode(gzdecode(file_get_contents(dirname(__DIR__, 3).'/Fixtures/SongStartDigitalSilence.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];

        foreach ($fixture['starts'] as $start) {
            $cases[$start['run'].'/'.$start['section']] = [$start];
        }

        return $cases;
    }

    /** @param array<string, mixed> $fixture */
    #[Test]
    #[DataProvider('acceptedStarts')]
    public function accepted_recordings_keep_the_first_finite_sound_and_their_lead(array $fixture): void
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local',
            'media-processing.segmentation.adaptive_thresholds.enabled' => false,
            'media-processing.segmentation.rms_threshold' => $fixture['threshold']]);
        $path = 'service-transcripts/accepted-song-start.rms.log';
        $lines = [];
        $firstFinite = null;

        foreach ($fixture['samples'] as $index => $sample) {
            $zero = $sample['rms'] === -999 || $sample['rms'] === -999.0;
            if (! $zero && $firstFinite === null) {
                $firstFinite = $sample['time'];
            }
            $lines[] = sprintf('frame:%d pts:%d pts_time:%.6f', $index, $index * 800, $sample['time']);
            $lines[] = 'lavfi.astats.Overall.RMS_level='.($zero ? '-inf' : (string) $sample['rms']);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $log = new MediaProcessingLog(['duration' => $fixture['end'] + 1, 'rms_log_path' => $path]);
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log,
            [['start_time' => $fixture['start'], 'end_time' => $fixture['end']]], songEnds: true);
        $start = $plan['segments'][0]['start_time'];

        $this->assertNotNull($firstFinite);
        $this->assertEqualsWithDelta($firstFinite - 0.3, $start, 0.00001);
        $this->assertLessThanOrEqual($fixture['accepted_start'] + 0.00001, $start, 'Never remove more than the accepted preview did.');
        $this->assertLessThanOrEqual(0.12, $fixture['accepted_start'] - $start, 'Only the extra zero lead before the first quiet sample is retained.');
        $this->assertSame($fixture['end'], $plan['segments'][0]['end_time']);
    }
}
