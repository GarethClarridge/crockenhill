<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Jobs\AssessSermonVideoQuality;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobUniquenessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function assess_video_quality_job_is_configured_to_be_unique(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();

        $job = new AssessSermonVideoQuality(sermonId: $sermon->id);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertEquals((string) $sermon->id, $job->uniqueId());
        $this->assertEquals(3600, $job->uniqueFor);
    }

    #[Test]
    public function it_can_resolve_unique_id_from_processing_log(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();

        /** @var MediaProcessingLog $processingLog */
        $processingLog = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'processing_id' => Str::uuid()->toString(),
        ]);

        $job = new AssessSermonVideoQuality(processingLog: $processingLog);

        $this->assertEquals((string) $sermon->id, $job->uniqueId());
    }
}
