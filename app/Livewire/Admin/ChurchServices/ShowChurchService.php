<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\MediaType;
use App\Jobs\ClassifyServiceSections;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingIdentityResolver;
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
            'items' => fn ($query) => $query
                ->with('song:id,title')
                ->orderBy('position')
                ->orderBy('id'),
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

        ClassifyServiceSections::dispatch($processingLog, preserveRunStatus: true)
            ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));

        $this->success('Section reclassification queued');
    }

    /**
     * @return EloquentCollection<int, MediaProcessingLog>
     */
    private function relatedProcessingRuns(): EloquentCollection
    {
        $serviceDate = $this->churchService->date->toDateString();
        $serviceType = $this->churchService->service;
        $resolver = $this->identityResolver();

        $query = MediaProcessingLog::query()
            ->livestream()
            ->with([
                'serviceSections' => fn ($query) => $query
                    ->with('publishedSermon:id,title,slug')
                    ->orderBy('section_order')
                    ->orderBy('id'),
            ])
            ->orderByDesc('created_at');

        return $resolver->scopeMatchesIdentity($query, $serviceDate, $serviceType)->get();
    }

    private function processingLogMatchesService(MediaProcessingLog $processingLog): bool
    {
        $serviceDate = $this->churchService->date->toDateString();
        $serviceType = $this->churchService->service;

        return $this->identityResolver()->matchesService($processingLog, $serviceDate, $serviceType);
    }

    private function identityResolver(): MediaProcessingIdentityResolver
    {
        return app(MediaProcessingIdentityResolver::class);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
