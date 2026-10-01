<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Actions\RetranscribeForCorpusRerun;
use App\Jobs\RecordCorpusRerunTranscription;
use App\Models\MediaProcessingLog;
use App\Support\WorkerCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordCorpusRerunTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'local',
        ]);
    }

    /**
     * The stamp names the text the round wrote and the code that wrote it, so the detection
     * round that follows can tell the round's own change from any other.
     */
    #[Test]
    public function it_records_the_text_the_round_wrote_and_the_code_that_wrote_it(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $path = 'temp/service_transcript_'.$run->processing_id.'.json';
        Storage::disk('local')->put($path, '{"cues":[{"start":0,"end":30,"text":"The Lord is my shepherd."}]}');
        $run->putServiceTranscriptPath($path);
        $run->putCorpusRerunStamp([
            'grounds' => 'corpus_rerun',
            'tier' => RetranscribeForCorpusRerun::TIER,
            'detection' => RetranscribeForCorpusRerun::DETECTION_NONE,
            'git_commit' => 'abc123',
            'dispatched_at' => '2026-10-01T09:00:00+00:00',
        ]);

        dispatch_sync(new RecordCorpusRerunTranscription($run->refresh()));

        $stamp = $run->refresh()->corpusRerunStamps()[0];

        self::assertSame($run->serviceTranscriptSha256(), $stamp['transcript_sha256']);
        self::assertNotNull($stamp['transcribed_at']);
        self::assertSame(WorkerCode::bootCommit(), $stamp['worker_commit']);
    }
}
