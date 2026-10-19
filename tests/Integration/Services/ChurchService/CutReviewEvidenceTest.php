<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CutReviewEvidence;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class CutReviewEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_review_uses_the_recorded_multipart_output_rather_than_section_edges(): void
    {
        $log = $this->processingRun();
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => 'sermon', 'start_time' => 100, 'end_time' => 400]);

        $snapshot = app(CutReviewEvidence::class)->recorded($log);

        $this->assertSame([['start_time' => 101.2, 'end_time' => 210.5], ['start_time' => 300.1, 'end_time' => 398.7]], $snapshot['segments']);
        $this->assertSame($log->processing_id, $snapshot['processing_id']);
        $this->assertSame(str_repeat('a', 64), $snapshot['media']['sha256']);
        $this->assertSame($snapshot, app(CutReviewEvidence::class)->recorded($log));
        $log->source_file_path = 'a-different-recording.mp4';
        $this->assertNotSame($snapshot['evidence_sha256'], app(CutReviewEvidence::class)->recorded($log)['evidence_sha256']);
    }

    #[Test]
    public function a_section_from_another_run_cannot_supply_a_review_title(): void
    {
        $log = $this->processingRun();
        $section = ServiceSection::factory()->create();
        $this->expectException(ModelNotFoundException::class);

        app(CutReviewEvidence::class)->recorded($log, $section->id);
    }

    #[Test]
    public function a_section_review_keeps_its_recorded_cut_and_scoped_title(): void
    {
        $log = $this->processingRun();
        $section = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => 'song', 'title' => 'The correct song', 'start_time' => 100, 'end_time' => 200]);
        $payload = $section->mediaSignaturePayload();
        $payload['media_processing']['version']--;
        $section->forceFill(['metadata' => ['publication_candidate_extraction' => [
            'processing_id' => $log->processing_id, 'media_signature' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'media_processing' => $payload['media_processing'],
            'segments' => [['start_time' => 101.2, 'end_time' => 198.7]],
        ]]])->save();
        $metadata = $log->processing_metadata->toArray();
        $metadata['media_outputs']['section_'.$section->id] = $metadata['media_outputs']['sermon'];
        $log->forceFill(['processing_metadata' => $metadata])->save();

        $snapshot = app(CutReviewEvidence::class)->recorded($log, $section->id);

        $this->assertSame('The correct song', $snapshot['section']['title']);
        $this->assertSame([['start_time' => 101.2, 'end_time' => 198.7]], $snapshot['segments']);
        $section->update(['end_time' => 205, 'duration' => 105]);
        $this->expectException(RuntimeException::class);
        app(CutReviewEvidence::class)->recorded($log, $section->id);
    }

    #[Test]
    public function missing_recorded_media_never_falls_back_to_raw_section_bounds(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id]);
        $this->expectException(RuntimeException::class);

        app(CutReviewEvidence::class)->recorded($log);
    }

    private function processingRun(): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['source_file_path' => 'service.mp4']);
        $log->forceFill(['processing_metadata' => ['media_outputs' => ['sermon' => [
            'disk' => 'local', 'path' => 'sermon.mp4', 'sha256' => str_repeat('a', 64), 'size' => 1234,
            'provenance' => ['processing_id' => $log->processing_id, 'segments' => [
                ['start_time' => 101.2, 'end_time' => 210.5], ['start_time' => 300.1, 'end_time' => 398.7],
            ]],
        ]]]])->save();

        return $log;
    }
}
