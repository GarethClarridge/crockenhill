<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Data\ChurchServiceTranscript;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Audio\TranscriptStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateSermonTranscriptFromServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
    }

    #[Test]
    public function it_stores_the_sermon_slice_of_the_full_service_transcript(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id,
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);
        $serviceTranscriptPath = 'temp/service_transcript_'.$log->processing_id.'.json';
        Storage::disk('local')->put($serviceTranscriptPath, json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
            ['start' => 110.0, 'end' => 190.0, 'text' => 'The sermon text.'],
            ['start' => 190.0, 'end' => 210.0, 'text' => 'Closing prayer.'],
        ], 300.0, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($serviceTranscriptPath);

        (new CreateSermonTranscriptFromService($log))->handle(app(TranscriptStorageService::class));

        $log->refresh();
        $sermon->refresh();

        $this->assertSame('transcripts/sermon_'.$sermon->id.'.md', $log->transcript_file_path);
        $this->assertSame($log->transcript_file_path, $sermon->transcript_file_path);
        Storage::disk('local')->assertExists((string) $sermon->transcript_file_path);
        $this->assertSame(
            'Welcome. The sermon text. Closing prayer.',
            Storage::disk('local')->get((string) $sermon->transcript_file_path),
        );
    }

    #[Test]
    public function it_omits_the_material_between_the_extracted_spans(): void
    {
        [$sermon, $log] = $this->runWithServiceTranscript(
            cues: [
                ['start' => 1990.0, 'end' => 2005.0, 'text' => 'Notices before the reading.'],
                ['start' => 2010.0, 'end' => 2095.0, 'text' => 'The preached reading.'],
                ['start' => 2110.0, 'end' => 2330.0, 'text' => 'An intervening hymn, sung twice.'],
                ['start' => 2340.0, 'end' => 3990.0, 'text' => 'The sermon itself.'],
            ],
            sermonStartTime: 2005.65,
            sermonEndTime: 3999.99,
            segments: [
                ['start_time' => 2005.65, 'end_time' => 2099.0],
                ['start_time' => 2337.0, 'end_time' => 3999.99],
            ],
        );

        $this->assertSame(
            'The preached reading. The sermon itself.',
            Storage::disk('local')->get((string) $sermon->refresh()->transcript_file_path),
        );
    }

    #[Test]
    public function it_uses_the_recorded_span_rather_than_the_outer_bounds_for_a_single_span_plan(): void
    {
        [$sermon] = $this->runWithServiceTranscript(
            cues: [
                ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
                ['start' => 130.0, 'end' => 190.0, 'text' => 'The sermon text.'],
                ['start' => 190.0, 'end' => 210.0, 'text' => 'Closing prayer.'],
            ],
            sermonStartTime: 100.0,
            sermonEndTime: 200.0,
            segments: [['start_time' => 120.0, 'end_time' => 195.0]],
        );

        $this->assertSame(
            'The sermon text. Closing prayer.',
            Storage::disk('local')->get((string) $sermon->refresh()->transcript_file_path),
        );
    }

    #[Test]
    public function it_falls_back_to_the_recorded_bounds_when_the_plan_holds_no_usable_spans(): void
    {
        [$sermon] = $this->runWithServiceTranscript(
            cues: [
                ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
                ['start' => 110.0, 'end' => 190.0, 'text' => 'The sermon text.'],
            ],
            sermonStartTime: 100.0,
            sermonEndTime: 200.0,
            segments: [['start_time' => 300.0, 'end_time' => 300.0]],
        );

        $this->assertSame(
            'Welcome. The sermon text.',
            Storage::disk('local')->get((string) $sermon->refresh()->transcript_file_path),
        );
    }

    #[Test]
    public function it_fails_when_the_extracted_spans_contain_no_speech(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains no sermon text');

        $this->runWithServiceTranscript(
            cues: [
                ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
            ],
            sermonStartTime: 100.0,
            sermonEndTime: 200.0,
            segments: [['start_time' => 2000.0, 'end_time' => 2100.0]],
        );
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start_time: float, end_time: float}>|null  $segments
     * @return array{0: Sermon, 1: MediaProcessingLog}
     */
    private function runWithServiceTranscript(
        array $cues,
        float $sermonStartTime,
        float $sermonEndTime,
        ?array $segments = null,
    ): array {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id,
            'sermon_start_time' => $sermonStartTime,
            'sermon_end_time' => $sermonEndTime,
        ]);

        if ($segments !== null) {
            $metadata = $log->processing_metadata?->toArray() ?? [];
            $metadata['sermon_extraction_plan'] = [
                'source' => 'service_sections',
                'mode' => count($segments) > 1 ? 'concat_spans' : 'single_span',
                'segments' => $segments,
            ];
            $log->forceFill(['processing_metadata' => $metadata])->save();
            $log->refresh();
        }

        $serviceTranscriptPath = 'temp/service_transcript_'.$log->processing_id.'.json';
        Storage::disk('local')->put($serviceTranscriptPath, json_encode(ChurchServiceTranscript::fromCues(
            $cues,
            4200.0,
            ChurchServiceTranscript::SOURCE_MOCK,
        )->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($serviceTranscriptPath);

        (new CreateSermonTranscriptFromService($log))->handle(app(TranscriptStorageService::class));

        return [$sermon, $log];
    }
}
