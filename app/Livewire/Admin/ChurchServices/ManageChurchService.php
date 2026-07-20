<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\PrefillChurchServiceFromInboundEmail;
use App\Actions\SaveChurchServiceFromAdmin;
use App\Enums\SermonService;
use App\Livewire\Forms\ChurchServiceFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Services\Processing\MediaProcessingIdentityResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class ManageChurchService extends Component
{
    use WithAdminAuthorization;
    use WithNotifications;

    public ChurchServiceFormData $form;

    public ?ChurchService $churchService = null;

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
        if ($this->churchService instanceof ChurchService && $this->churchService->exists) {
            /** @var ChurchService $churchService */
            $churchService = ChurchService::query()
                ->findOrFail($this->churchService->getKey());
            $this->churchService = $churchService;

            $this->form->setChurchService($churchService);
        } elseif (is_int($this->inboundEmailId)) {
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

    public function addItem(): void
    {
        $this->authorizeAdmin();

        $this->form->addItem();
    }

    public function removeItem(int $index): void
    {
        $this->authorizeAdmin();

        $this->form->removeItem($index);
    }

    public function moveItemUp(int $index): void
    {
        $this->authorizeAdmin();

        $this->form->moveItemUp($index);
    }

    public function moveItemDown(int $index): void
    {
        $this->authorizeAdmin();

        $this->form->moveItemDown($index);
    }

    public function selectSong(int $index, int $songId): void
    {
        $this->authorizeAdmin();

        $this->form->selectSong($index, $songId);
    }

    public function updatedFormItems(mixed $value, string $key): void
    {
        $this->form->updatedItems($value, $key);
    }

    public function save(SaveChurchServiceFromAdmin $saveAction): mixed
    {
        $this->authorizeAdmin();
        $this->form->validate();
        $wasCreated = ! ($this->churchService instanceof ChurchService && $this->churchService->exists);

        try {
            $churchService = $saveAction->execute(
                validated: $this->form->servicePayload(),
                syncPayload: $this->form->syncPayload(),
                churchService: $this->churchService,
                userId: Auth::user()->id ?? abort(403),
                inboundEmailId: $this->inboundEmailId,
                planKey: $this->planKey,
            );
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'ordering conflict')) {
                $this->addError('form.items', $exception->getMessage());

                return null;
            }

            throw $exception;
        }

        $this->churchService = $churchService;

        return $this->success(
            $wasCreated ? 'Service created' : 'Service updated',
            redirectTo: route('admin.services.show', $churchService)
        );
    }

    public function render(): View
    {
        return view('livewire.admin.church-services.manage-church-service', [
            'serviceOptions' => $this->form->serviceOptions(),
            'sectionTypeOptions' => $this->form->sectionTypeOptions(),
            'items' => $this->form->items,
            'songSuggestions' => $this->form->songSuggestions(),
            'isEditing' => $this->churchService instanceof ChurchService,
        ])->layout('layouts.admin', [
            'title' => $this->churchService instanceof ChurchService ? 'Edit Service' : 'Create Service',
            'heading' => $this->churchService instanceof ChurchService ? 'Edit Service' : 'Create Service',
        ]);
    }
}
