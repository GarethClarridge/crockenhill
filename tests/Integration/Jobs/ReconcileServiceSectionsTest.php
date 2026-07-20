<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Enums\SermonService;
use App\Jobs\DetectServiceStructure;
use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReconcileServiceSectionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_redetects_structure_from_the_stored_transcript_for_a_matching_completed_run(): void
    {
        Storage::fake('local');
        Bus::fake([DetectServiceStructure::class]);

        [$churchService, $processingLog] = $this->reconcilableRun('2026-06-07');
        $transcriptPath = 'temp/service_transcript_'.$processingLog->processing_id.'.json';
        Storage::disk('local')->put($transcriptPath, (string) json_encode(['cues' => []]));
        $processingLog->putServiceTranscriptPath($transcriptPath);

        (new ReconcileServiceSections($processingLog, $churchService))
            ->handle(new MediaProcessingIdentityResolver);

        Bus::assertDispatched(
            DetectServiceStructure::class,
            fn (DetectServiceStructure $job): bool => $job->reconcile
        );
        $this->assertSame($churchService->id, $processingLog->fresh()->church_service_id);
        $this->assertSame('completed', $processingLog->fresh()->status->value);
    }

    #[Test]
    public function it_does_not_dispatch_without_a_stored_transcript(): void
    {
        Storage::fake('local');
        Bus::fake([DetectServiceStructure::class]);

        [$churchService, $processingLog] = $this->reconcilableRun('2026-06-14');

        (new ReconcileServiceSections($processingLog, $churchService))
            ->handle(new MediaProcessingIdentityResolver);

        Bus::assertNotDispatched(DetectServiceStructure::class);
        $this->assertSame($churchService->id, $processingLog->fresh()->church_service_id);
    }

    #[Test]
    public function it_ignores_runs_that_do_not_match_the_service_identity(): void
    {
        Storage::fake('local');
        Bus::fake([DetectServiceStructure::class]);

        [$churchService, $processingLog] = $this->reconcilableRun('2026-06-21');
        $processingLog->forceFill(['extracted_date' => '2026-06-22'])->save();

        (new ReconcileServiceSections($processingLog, $churchService))
            ->handle(new MediaProcessingIdentityResolver);

        Bus::assertNotDispatched(DetectServiceStructure::class);
        $this->assertNull($processingLog->fresh()->church_service_id);
    }

    /**
     * @return array{0: ChurchService, 1: MediaProcessingLog}
     */
    private function reconcilableRun(string $date): array
    {
        $churchService = ChurchService::factory()->create([
            'date' => $date,
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => null,
            'extracted_date' => $date,
            'extracted_service' => SermonService::Morning,
            'current_step' => 'completed',
        ]);

        return [$churchService, $processingLog];
    }
}
