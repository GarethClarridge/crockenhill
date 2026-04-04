<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Mail\LivestreamProcessingFailed;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AlwaysFailingJob;
use Tests\TestCase;

class ShowChurchServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['service-tracking.enabled' => true]);
        Storage::fake('local');

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    // -------------------------------------------------------------------------
    // Failure parity
    // -------------------------------------------------------------------------

    #[Test]
    public function it_marks_the_run_as_failed_and_sends_notification_when_reclassification_chain_fails(): void
    {
        Mail::fake();
        config(['queue.default' => 'sync']);

        $serviceDate = '2026-01-15';
        $serviceType = SermonService::MORNING;

        $churchService = ChurchService::factory()->create([
            'date' => $serviceDate,
            'service' => $serviceType->value,
        ]);

        Storage::disk('local')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/2026/service.mp4',
            'extracted_date' => $serviceDate,
            'extracted_service' => $serviceType,
        ]);

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildSectionReclassificationChainJobs')->andReturn([new AlwaysFailingJob]);

        try {
            Livewire::actingAs($this->admin)
                ->test(ShowChurchService::class, ['churchService' => $churchService])
                ->call('reclassify', $log->id);
        } catch (\RuntimeException) {
            // Sync queue re-throws after firing the catch callback — expected.
        }

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertNotNull($log->error_message);
        $this->assertNotNull($log->completed_at);
        Mail::assertQueued(LivestreamProcessingFailed::class, fn ($mail) => $mail->processingId === $log->processing_id);
    }
}
