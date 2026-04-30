<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\AdminUserScenario;
use Tests\Support\ChurchServiceScenario;
use Tests\Support\MediaUploadScenario;
use Tests\Support\ProcessingLogScenario;
use Tests\Support\ServiceSectionScenario;

trait BuildsTestScenarios
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createVerifiedAdmin(array $attributes = []): User
    {
        return AdminUserScenario::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function actingAsVerifiedAdmin(array $attributes = []): User
    {
        return AdminUserScenario::actingAs($this, $attributes);
    }

    /**
     * @param  list<string>  $disks
     */
    protected function fakeMediaDisks(array $disks = ['local', 'public']): void
    {
        MediaUploadScenario::fakeDisks($disks);
    }

    protected function fakeAudioUpload(
        string $filename = 'sermon.mp3',
        int $kilobytes = 100,
        string $mime = 'audio/mpeg'
    ): UploadedFile {
        return MediaUploadScenario::fakeAudio($filename, $kilobytes, $mime);
    }

    protected function fakeVideoUpload(
        string $filename = 'sermon.mp4',
        int $kilobytes = 100,
        string $mime = 'video/mp4'
    ): UploadedFile {
        return MediaUploadScenario::fakeVideo($filename, $kilobytes, $mime);
    }

    protected function makeLivewireUpload(
        UploadedFile $upload,
        ?string $filename = null,
        ?string $mime = null
    ): UploadedFile {
        return MediaUploadScenario::makeLivewireUpload($upload, $filename, $mime);
    }

    protected function processingLogScenario(): ProcessingLogScenario
    {
        return ProcessingLogScenario::audio();
    }

    protected function churchServiceScenario(): ChurchServiceScenario
    {
        return ChurchServiceScenario::make();
    }

    protected function flaggedReviewServiceScenario(): ChurchService
    {
        return ChurchServiceScenario::make()->needsReview()->create();
    }

    /**
     * @param  list<string>  $types
     */
    protected function serviceWithSectionsScenario(int $count, array $types = []): ChurchService
    {
        return ChurchServiceScenario::make()->withSections($count, $types)->create();
    }

    protected function serviceSectionScenario(): ServiceSectionScenario
    {
        return ServiceSectionScenario::make();
    }

    protected function assertProcessingLogState(
        MediaProcessingLog $processingLog,
        ProcessingStatus|string $status,
        ?string $currentStep = null
    ): void {
        $processingLog->refresh();

        $expectedStatus = $status instanceof ProcessingStatus ? $status->value : $status;

        $this->assertSame($expectedStatus, $processingLog->status->value);

        if ($currentStep !== null) {
            $this->assertSame($currentStep, $processingLog->current_step);
        }
    }

    protected function assertChurchServiceItemCount(ChurchService $churchService, int $count): void
    {
        $churchService->refresh();

        $this->assertCount($count, $churchService->items);
    }

    protected function assertServiceSectionCount(MediaProcessingLog $processingLog, int $count): void
    {
        $processingLog->refresh();

        $this->assertCount($count, $processingLog->serviceSections);
    }

    protected function assertServiceSectionState(
        ServiceSection $serviceSection,
        bool $needsManualReview
    ): void {
        $serviceSection->refresh();

        $this->assertSame($needsManualReview, $serviceSection->needs_manual_review);
    }
}
