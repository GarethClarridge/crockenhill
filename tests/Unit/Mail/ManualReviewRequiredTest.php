<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Enums\SermonService;
use App\Mail\ManualReviewRequired;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualReviewRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_constructor_stores_all_properties(): void
    {
        $segments = [['start' => 0, 'end' => 300], ['start' => 300, 'end' => 600]];
        $mail = new ManualReviewRequired('proc-123', 'Too many segments', $segments);

        $this->assertEquals('proc-123', $mail->processingId);
        $this->assertEquals('Too many segments', $mail->reason);
        $this->assertEquals($segments, $mail->segments);
    }

    public function test_constructor_defaults_segments_to_empty_array(): void
    {
        $mail = new ManualReviewRequired('proc-456', 'Some reason');

        $this->assertEquals([], $mail->segments);
    }

    public function test_envelope_subject_includes_processing_id(): void
    {
        $mail = new ManualReviewRequired('proc-789', 'Test reason');
        $envelope = $mail->envelope();

        $this->assertStringContainsString('proc-789', $envelope->subject);
        $this->assertStringContainsString('Manual Review Required', $envelope->subject);
    }

    public function test_content_uses_correct_markdown_view(): void
    {
        $mail = new ManualReviewRequired('proc-test', 'Test reason');
        $content = $mail->content();

        $this->assertEquals('emails.manual-review-required', $content->markdown);
    }

    public function test_content_passes_all_data_to_view(): void
    {
        $segments = [['start' => 0, 'end' => 300]];
        $mail = new ManualReviewRequired('proc-test', 'Reason text', $segments);
        $content = $mail->content();

        $this->assertEquals('proc-test', $content->with['processingId']);
        $this->assertEquals('Reason text', $content->with['reason']);
        $this->assertEquals($segments, $content->with['segments']);
    }

    public function test_content_computes_segment_count(): void
    {
        $segments = [
            ['start' => 0, 'end' => 300],
            ['start' => 300, 'end' => 600],
            ['start' => 600, 'end' => 900],
        ];
        $mail = new ManualReviewRequired('proc-test', 'Test', $segments);
        $content = $mail->content();

        $this->assertEquals(3, $content->with['segmentCount']);
    }

    public function test_content_segment_count_is_zero_for_empty_segments(): void
    {
        $mail = new ManualReviewRequired('proc-test', 'No segments');
        $content = $mail->content();

        $this->assertEquals(0, $content->with['segmentCount']);
    }

    public function test_content_links_matched_runs_to_the_dedicated_segment_review(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'processing_id' => 'proc-matched',
            'extracted_date' => $service->date,
            'extracted_service' => $service->service,
        ]);

        $content = (new ManualReviewRequired('proc-matched', 'Review'))->content();

        $this->assertSame(route('admin.recordings.sermon-segment', $log->processing_id), $content->with['reviewUrl']);
    }

    public function test_content_links_orphan_runs_to_the_dedicated_segment_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'processing_id' => 'proc-orphan',
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning,
        ]);

        $content = (new ManualReviewRequired('proc-orphan', 'Review'))->content();

        $this->assertSame(route('admin.recordings.sermon-segment', $log->processing_id), $content->with['reviewUrl']);
    }
}
