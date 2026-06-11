<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Livewire\MediaUploadStatus as Status;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_cancel_processing_request_event(): void
    {
        Livewire::test(Status::class)
            ->call('requestCancelProcessing')
            ->assertDispatched('media-upload:cancel-processing');
    }

    #[Test]
    public function it_dispatches_retry_upload_request_event(): void
    {
        Livewire::test(Status::class)
            ->call('requestRetryUpload')
            ->assertDispatched('media-upload:retry-upload');
    }

    #[Test]
    public function it_includes_form_component_id_when_dispatching_cancel_processing_event(): void
    {
        Livewire::test(Status::class, ['formComponentId' => 'test-form-456'])
            ->call('requestCancelProcessing')
            ->assertDispatched('media-upload:cancel-processing', function (string $name, array $params) {
                return isset($params['id']) && $params['id'] === 'test-form-456';
            });
    }

    #[Test]
    public function it_includes_form_component_id_when_dispatching_retry_upload_event(): void
    {
        Livewire::test(Status::class, ['formComponentId' => 'test-form-456'])
            ->call('requestRetryUpload')
            ->assertDispatched('media-upload:retry-upload', function (string $name, array $params) {
                return isset($params['id']) && $params['id'] === 'test-form-456';
            });
    }

    #[Test]
    public function completed_state_links_to_the_matched_service(): void
    {
        config(['service-tracking.enabled' => true]);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        Livewire::test(Status::class, [
            'processingId' => $log->processing_id,
            'status' => 'completed',
            'successMessage' => 'Done.',
        ])
            ->assertSee('Open service')
            ->assertSeeHtml(route('admin.services.show', $service));
    }

    #[Test]
    public function completed_state_omits_the_service_link_when_no_service_matched(): void
    {
        config(['service-tracking.enabled' => true]);

        $log = MediaProcessingLog::factory()->audio()->completed()->create([
            'extracted_date' => null,
            'extracted_service' => null,
        ]);

        Livewire::test(Status::class, [
            'processingId' => $log->processing_id,
            'status' => 'completed',
            'successMessage' => 'Done.',
        ])
            ->assertDontSee('Open service');
    }

    #[Test]
    public function completed_state_omits_the_service_link_when_service_tracking_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        Livewire::test(Status::class, [
            'processingId' => $log->processing_id,
            'status' => 'completed',
            'successMessage' => 'Done.',
        ])
            ->assertDontSee('Open service');
    }
}
