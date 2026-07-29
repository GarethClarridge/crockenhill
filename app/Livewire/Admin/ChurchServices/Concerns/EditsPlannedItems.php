<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices\Concerns;

use App\Actions\SaveChurchServiceFromAdmin;
use App\Models\ChurchService;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

trait EditsPlannedItems
{
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

        $existingChurchService = $this->churchServiceForPlannedItems();
        $wasCreated = ! $existingChurchService instanceof ChurchService;

        try {
            $churchService = $saveAction->execute(
                validated: $this->form->servicePayload(),
                syncPayload: $this->form->syncPayload(),
                churchService: $existingChurchService,
                userId: Auth::user()->id ?? abort(403),
                inboundEmailId: $this->inboundEmailIdForPlannedItems(),
                planKey: $this->planKeyForPlannedItems(),
                expectedCanonicalRevision: $existingChurchService?->canonical_revision,
            );
        } catch (RuntimeException $exception) {
            if (
                str_contains($exception->getMessage(), 'ordering conflict')
                || str_contains($exception->getMessage(), 'changed since you opened')
            ) {
                $this->addError('form.items', $exception->getMessage());

                return null;
            }

            throw $exception;
        }

        return $this->afterPlannedItemsSaved($churchService, $wasCreated);
    }

    abstract protected function churchServiceForPlannedItems(): ?ChurchService;

    abstract protected function inboundEmailIdForPlannedItems(): ?int;

    abstract protected function planKeyForPlannedItems(): ?string;

    abstract protected function afterPlannedItemsSaved(ChurchService $churchService, bool $wasCreated): mixed;
}
