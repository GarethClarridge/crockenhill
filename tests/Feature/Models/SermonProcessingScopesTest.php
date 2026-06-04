<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingScopesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function scope_automated_finds_sermons_with_transcript_or_processing_logs(): void
    {
        /** @var Sermon $automatedByTranscript */
        $automatedByTranscript = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/sermon.txt',
        ]);

        /** @var Sermon $automatedByLog */
        $automatedByLog = Sermon::factory()->create([
            'transcript_file_path' => null,
        ]);
        MediaProcessingLog::factory()->create([
            'sermon_id' => $automatedByLog->id,
        ]);

        /** @var Sermon $manual */
        $manual = Sermon::factory()->create([
            'transcript_file_path' => null,
        ]);

        $results = Sermon::automated()->get();

        $this->assertTrue($results->contains($automatedByTranscript));
        $this->assertTrue($results->contains($automatedByLog));
        $this->assertFalse($results->contains($manual));
    }

    #[Test]
    public function scope_manual_finds_sermons_without_transcript_and_without_processing_logs(): void
    {
        /** @var Sermon $automatedByTranscript */
        $automatedByTranscript = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/sermon.txt',
        ]);

        /** @var Sermon $automatedByLog */
        $automatedByLog = Sermon::factory()->create([
            'transcript_file_path' => null,
        ]);
        MediaProcessingLog::factory()->create([
            'sermon_id' => $automatedByLog->id,
        ]);

        /** @var Sermon $manual */
        $manual = Sermon::factory()->create([
            'transcript_file_path' => null,
        ]);

        $results = Sermon::manual()->get();

        $this->assertFalse($results->contains($automatedByTranscript));
        $this->assertFalse($results->contains($automatedByLog));
        $this->assertTrue($results->contains($manual));
    }

    #[Test]
    public function scope_processing_completed_filters_correctly(): void
    {
        /** @var Sermon $completed */
        $completed = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $completed->id,
            'status' => ProcessingStatus::Completed,
        ]);

        /** @var Sermon $processing */
        $processing = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $processing->id,
            'status' => ProcessingStatus::Processing,
        ]);

        /** @var Sermon $failed */
        $failed = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $failed->id,
            'status' => ProcessingStatus::Failed,
        ]);

        $results = Sermon::processingCompleted()->get();

        $this->assertTrue($results->contains($completed));
        $this->assertFalse($results->contains($processing));
        $this->assertFalse($results->contains($failed));
    }

    #[Test]
    public function scope_processing_failed_filters_correctly(): void
    {
        /** @var Sermon $completed */
        $completed = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $completed->id,
            'status' => ProcessingStatus::Completed,
        ]);

        /** @var Sermon $processing */
        $processing = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $processing->id,
            'status' => ProcessingStatus::Processing,
        ]);

        /** @var Sermon $failed */
        $failed = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $failed->id,
            'status' => ProcessingStatus::Failed,
        ]);

        $results = Sermon::processingFailed()->get();

        $this->assertFalse($results->contains($completed));
        $this->assertFalse($results->contains($processing));
        $this->assertTrue($results->contains($failed));
    }

    #[Test]
    public function scope_processing_in_progress_filters_correctly(): void
    {
        /** @var Sermon $completed */
        $completed = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $completed->id,
            'status' => ProcessingStatus::Completed,
        ]);

        /** @var Sermon $processing */
        $processing = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $processing->id,
            'status' => ProcessingStatus::Processing,
        ]);

        /** @var Sermon $failed */
        $failed = Sermon::factory()->create();
        MediaProcessingLog::factory()->create([
            'sermon_id' => $failed->id,
            'status' => ProcessingStatus::Failed,
        ]);

        $results = Sermon::processingInProgress()->get();

        $this->assertFalse($results->contains($completed));
        $this->assertTrue($results->contains($processing));
        $this->assertFalse($results->contains($failed));
    }
}
