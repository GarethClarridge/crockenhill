<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Video\VideoDeadPictureProbe;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReplayDeadFeedExcisionCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function replay_maps_graded_picture_through_concat_spans_and_names_only_dead_feed_without_writing(): void
    {
        Storage::fake('local');
        $dead = $this->createRun([[561, 634]]);
        $soundOnly = $this->createRun([]);
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $exit = Artisan::call('service:replay-dead-feed', ['--all' => true, '--graded-picture' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame([], $writes);
        $this->assertSame([$dead->id], array_column($report['affected'], 'run'));
        $this->assertSame([$soundOnly->id], $report['unchanged_runs']);
        $this->assertSame('graded_clip_projection', $report['evidence']);
        $this->assertEqualsWithDelta(2511.0, $report['affected'][0]['excisions'][0]['start_time'], 0.001);
        $this->assertEqualsWithDelta(2583.81, $report['affected'][0]['excisions'][0]['end_time'], 0.001);
        $this->assertCount(3, $report['affected'][0]['new_segments']);
        $this->assertSame([], $dead->fresh()->processing_metadata->raw['service_artifacts'] ?? []);
    }

    #[Test]
    public function replay_refuses_an_implicit_population(): void
    {
        $this->artisan('service:replay-dead-feed')->assertFailed();
    }

    #[Test]
    public function source_artifact_replay_reports_missing_evidence_without_probing(): void
    {
        Storage::fake('local');
        $log = $this->createRun([[561, 634]]);
        $this->mock(VideoDeadPictureProbe::class)->shouldNotReceive('probeWindow');
        Artisan::call('service:replay-dead-feed', ['--run' => [$log->id], '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$log->id], $report['unassessable_runs']);
        $this->assertSame([], $report['affected']);
    }

    /** @param list<array{int, int}> $deadIntervals */
    private function createRun(array $deadIntervals): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 4000, 'source_file_path' => 'temp/source.mp4']);
        $rmsPath = 'temp/'.$log->processing_id.'.rms';
        $log->update(['rms_log_path' => $rmsPath]);
        Storage::disk('local')->put($rmsPath, "frame:0 pts:0 pts_time:1970\nlavfi.astats.Overall.RMS_level=-20\n"
            ."frame:1 pts:0 pts_time:2511\nlavfi.astats.Overall.RMS_level=-inf\n"
            ."frame:2 pts:0 pts_time:2584\nlavfi.astats.Overall.RMS_level=-20\n");
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Sermon,
            'start_time' => 1970.63, 'end_time' => 3875.81,
            'metadata' => ['review_flags' => [ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT]],
        ]);
        $log->putVideoQualityMetadata([
            'metrics' => ['dead_intervals' => $deadIntervals],
            'graded_output' => ['provenance' => ['segments' => [
                ['start_time' => 1449.07, 'end_time' => 1469.89],
                ['start_time' => 1970.63, 'end_time' => 3875.81],
            ]]],
        ]);

        return $log->fresh();
    }
}
