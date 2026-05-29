<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAutomationTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_is_automated_if_it_has_a_transcript(): void
    {
        $sermon = Sermon::factory()->make([
            'transcript_file_path' => 'transcripts/test.txt',
        ]);

        $this->assertTrue($sermon->isAutomated());
        $this->assertFalse($sermon->isManual());
    }

    #[Test]
    public function it_is_automated_if_latest_processing_log_is_eager_loaded(): void
    {
        $sermon = Sermon::factory()->create();
        MediaProcessingLog::factory()->for($sermon)->create();

        // Load the relationship
        $sermon->load('latestProcessingLog');

        $this->assertTrue($sermon->relationLoaded('latestProcessingLog'));
        $this->assertTrue($sermon->isAutomated());
        $this->assertFalse($sermon->isManual());
    }

    #[Test]
    public function it_is_not_automated_if_latest_processing_log_is_eager_loaded_but_null(): void
    {
        $sermon = Sermon::factory()->create();
        // No logs created

        $sermon->load('latestProcessingLog');

        $this->assertTrue($sermon->relationLoaded('latestProcessingLog'));
        $this->assertNull($sermon->latestProcessingLog);
        $this->assertFalse($sermon->isAutomated());
        $this->assertTrue($sermon->isManual());
    }

    #[Test]
    public function it_is_automated_if_processing_logs_collection_is_eager_loaded(): void
    {
        $sermon = Sermon::factory()->create();
        MediaProcessingLog::factory()->for($sermon)->create();

        $sermon->load('processingLogs');

        $this->assertTrue($sermon->relationLoaded('processingLogs'));
        $this->assertTrue($sermon->isAutomated());
        $this->assertFalse($sermon->isManual());
    }

    #[Test]
    public function it_is_not_automated_if_processing_logs_collection_is_eager_loaded_but_empty(): void
    {
        $sermon = Sermon::factory()->create();
        // No logs created

        $sermon->load('processingLogs');

        $this->assertTrue($sermon->relationLoaded('processingLogs'));
        $this->assertTrue($sermon->processingLogs->isEmpty());
        $this->assertFalse($sermon->isAutomated());
        $this->assertTrue($sermon->isManual());
    }

    #[Test]
    public function it_is_automated_if_no_relationship_is_loaded_but_logs_exist_in_database(): void
    {
        $sermon = Sermon::factory()->create();
        MediaProcessingLog::factory()->for($sermon)->create();

        $this->assertFalse($sermon->relationLoaded('latestProcessingLog'));
        $this->assertFalse($sermon->relationLoaded('processingLogs'));

        // Should trigger a query fallback
        $this->assertTrue($sermon->isAutomated());
        $this->assertFalse($sermon->isManual());
    }

    #[Test]
    public function it_is_manual_if_no_transcript_and_no_logs_exist(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => null,
        ]);

        $this->assertFalse($sermon->isAutomated());
        $this->assertTrue($sermon->isManual());
    }

    #[Test]
    public function it_is_manual_if_unsaved_and_no_transcript(): void
    {
        $sermon = Sermon::factory()->make([
            'transcript_file_path' => null,
        ]);

        $this->assertFalse($sermon->isAutomated());
        $this->assertTrue($sermon->isManual());
    }
}
