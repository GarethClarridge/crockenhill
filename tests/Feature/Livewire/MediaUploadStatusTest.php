<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\MediaUpload\Status;
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
}
