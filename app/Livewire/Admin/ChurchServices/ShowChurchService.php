<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\MediaType;
use App\Jobs\ClassifyServiceSections;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\View\View;
use Livewire\Component;

class ShowChurchService extends Component
{
    use WithAdminAuthorization;
    use WithNotifications;

    public ChurchService $churchService;

    public function mount(ChurchService $churchService): void
    {
        $this->authorizeAdmin();
        $this->abortIfDisabled();

        $this->churchService = $churchService->load([
            'items' => fn ($query) => $query->orderBy('position')->orderBy('id'),
        ]);
    }

    public function render(): View
    {
        $importMetadata = $this->churchService->import_metadata ?? [];
        $warnings = is_array($importMetadata['warnings'] ?? null) ? $importMetadata['warnings'] : [];
        $confidenceScore = $importMetadata['confidence_score'] ?? null;
        $processingRuns = $this->relatedProcessingRuns();

        return view('livewire.admin.church-services.show-church-service', [
            'importMetadata' => $importMetadata,
            'warnings' => $warnings,
            'confidenceScore' => is_numeric($confidenceScore) ? (float) $confidenceScore : null,
            'processingRuns' => $processingRuns,
        ])->layout('layouts.admin', [
            'title' => 'Service: '.$this->churchService->date->format('j M Y'),
            'heading' => 'Service: '.$this->churchService->date->format('j M Y').' '.$this->churchService->service->label(),
        ]);
    }

    public function reclassify(int $processingLogId): void
    {
        $this->authorizeAdmin();

        $processingLog = MediaProcessingLog::query()->find($processingLogId);
        if (! $processingLog instanceof MediaProcessingLog) {
            $this->error('Processing run not found.');

            return;
        }

        if ($processingLog->processing_type !== MediaType::Livestream) {
            $this->error('Only livestream runs can be reclassified.');

            return;
        }

        if (! $this->processingLogMatchesService($processingLog)) {
            $this->error('Selected run does not belong to this service.');

            return;
        }

        ClassifyServiceSections::dispatch($processingLog)
            ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));

        $this->success('Section reclassification queued');
    }

    /**
     * @return EloquentCollection<int, MediaProcessingLog>
     */
    private function relatedProcessingRuns(): EloquentCollection
    {
        $serviceDate = $this->churchService->date->toDateString();
        $serviceType = $this->churchService->service->value;

        return MediaProcessingLog::query()
            ->livestream()
            ->where(function (Builder $query) use ($serviceDate, $serviceType): void {
                $query->where(function (Builder $query) use ($serviceDate, $serviceType): void {
                    $query->whereDate('extracted_date', $serviceDate)
                        ->where('extracted_service', $serviceType);
                })->orWhere(function (Builder $query) use ($serviceDate, $serviceType): void {
                    $query->where('processing_metadata->extracted_date', $serviceDate)
                        ->where('processing_metadata->extracted_service', $serviceType);
                });
            })
            ->with([
                'serviceSections' => fn ($query) => $query->orderBy('section_order')->orderBy('id'),
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    private function processingLogMatchesService(MediaProcessingLog $processingLog): bool
    {
        $serviceDate = $this->churchService->date->toDateString();
        $serviceType = $this->churchService->service->value;

        $columnDate = $processingLog->extracted_date?->toDateString();
        $columnService = $processingLog->extracted_service?->value;

        if (is_string($columnDate) && is_string($columnService)) {
            return $columnDate === $serviceDate && $columnService === $serviceType;
        }

        $metadata = $processingLog->processing_metadata ?? [];
        $metadataDate = $metadata['extracted_date'] ?? null;
        $metadataService = $metadata['extracted_service'] ?? null;

        return is_string($metadataDate)
            && is_string($metadataService)
            && $metadataDate === $serviceDate
            && $metadataService === $serviceType;
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
