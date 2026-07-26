<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\PrefillChurchServiceFromInboundEmail;
use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\Concerns\EditsPlannedItems;
use App\Livewire\Forms\ChurchServiceFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Services\Processing\MediaProcessingIdentityResolver;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class ManageChurchService extends Component
{
    use EditsPlannedItems;
    use WithAdminAuthorization;
    use WithNotifications;

    public ChurchServiceFormData $form;

    #[Url(except: null)]
    public ?int $inboundEmailId = null;

    #[Url(except: null)]
    public ?string $planKey = null;

    #[Url(except: null)]
    public ?string $date = null;

    #[Url(except: null)]
    public ?string $service = null;

    public function mount(
        PrefillChurchServiceFromInboundEmail $prefillAction,
        MediaProcessingIdentityResolver $identityResolver,
    ): void {
        if (is_int($this->inboundEmailId)) {
            $this->form->applyPrefillData($prefillAction->execute($this->inboundEmailId, $this->planKey));
        } else {
            // Orphan inbox groups link here with their resolved date/slot so
            // the missing Sunday can be created and the workbench takes over.
            $prefillDate = $identityResolver->parseDate($this->date);
            if ($prefillDate !== null) {
                $this->form->date = $prefillDate;
            }

            $prefillService = $identityResolver->parseService($this->service);
            if ($prefillService instanceof SermonService) {
                $this->form->service = $prefillService->value;
            }
        }

        $this->form->ensureHasItems();
    }

    public function render(): View
    {
        return view('livewire.admin.church-services.manage-church-service', [
            'serviceOptions' => $this->form->serviceOptions(),
            'sectionTypeOptions' => $this->form->sectionTypeOptions(),
            'items' => $this->form->items,
            'songSuggestions' => $this->form->songSuggestions(),
            'linkedSongTitles' => $this->form->linkedSongTitles(),
        ])->layout('layouts.admin', [
            'title' => 'Create Service',
            'heading' => 'Create Service',
        ]);
    }

    protected function churchServiceForPlannedItems(): ?ChurchService
    {
        return null;
    }

    protected function inboundEmailIdForPlannedItems(): ?int
    {
        return $this->inboundEmailId;
    }

    protected function planKeyForPlannedItems(): ?string
    {
        return $this->planKey;
    }

    protected function afterPlannedItemsSaved(ChurchService $churchService, bool $wasCreated): mixed
    {
        return $this->success('Service created', route('admin.services.show', $churchService));
    }
}
