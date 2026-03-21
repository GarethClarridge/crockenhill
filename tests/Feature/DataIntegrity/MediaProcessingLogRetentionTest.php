<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deleting_a_sermon_keeps_its_processing_log_and_nulls_the_foreign_key(): void
    {
        $sermon = Sermon::factory()->create();
        $processingLog = MediaProcessingLog::factory()->withSermon($sermon)->create();

        $sermon->delete();

        $processingLog->refresh();

        $this->assertDatabaseHas('media_processing_logs', [
            'id' => $processingLog->id,
            'sermon_id' => null,
        ]);
    }
}
