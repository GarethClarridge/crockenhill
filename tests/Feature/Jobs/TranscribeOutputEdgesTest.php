<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Jobs\TranscribeOutputEdges;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscribeOutputEdgesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A song ends where the next speech starts ({@see CueSafeExtractionPlan::forSection()}),
     * and the cut there needs that speech's word timings, though it is no section's edge.
     */
    #[Test]
    public function it_decodes_the_speech_that_ends_each_song(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.rms_threshold', -45.0);
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000, 'rms_log_path' => 'service-transcripts/edges.rms.log']);
        $log->putServiceTranscriptPath('temp/edges.json');
        Storage::disk('local')->put('temp/edges.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 1317.84, 'end' => 1322.40, 'text' => 'Do you please sit down?'],
            ['start' => 1324.58, 'end' => 1326.48, 'text' => "Well, it wasn't that long ago"],
        ], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        Storage::disk('local')->put('service-transcripts/edges.rms.log', implode("\n", array_map(
            static fn (int $tenth): string => sprintf("frame:%d pts:%d pts_time:%.1f\nlavfi.astats.Overall.RMS_level=-20.0", $tenth, $tenth * 800, $tenth / 10),
            range(11900, 13400),
        ))."\n");
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song, 'start_time' => 1200.0, 'end_time' => 1322.40]);

        $this->mock(PrepareOutputEdgeWordTimings::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->once()->withArgs(static fn (MediaProcessingLog $run, array $spans): bool => in_array(['start_time' => 1324.58, 'end_time' => 1324.58], $spans, true))
                ->andReturn(['windows' => 2, 'decoded' => 0, 'compute_seconds' => 0.0]);
        });

        app()->call([new TranscribeOutputEdges($log), 'handle']);
    }
}
