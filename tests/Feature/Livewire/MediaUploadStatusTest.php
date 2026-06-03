<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\MediaUploadStatus as Status;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadStatusTest extends TestCase
{
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
}
