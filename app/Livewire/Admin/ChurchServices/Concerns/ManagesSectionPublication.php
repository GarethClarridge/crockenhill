<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices\Concerns;

use App\Actions\Publication\ApproveSectionForPublication;
use App\Actions\Publication\RejectSectionPublication;
use App\Actions\Publication\RequeueSectionPublication;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\ServiceSection;

trait ManagesSectionPublication
{
    use WithAdminAuthorization;

    public function approve(int $sectionId): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        $error = app(ApproveSectionForPublication::class)->execute($section);
        if ($error !== null) {
            $this->error($error);

            return;
        }

        $this->success('Section approved and publish job queued.');
    }

    public function reject(int $sectionId): void
    {

        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        if (! app(RejectSectionPublication::class)->execute($section)) {
            $this->error('This section cannot be rejected in its current state.');

            return;
        }

        $this->success('Section rejected.');
    }

    public function requeue(int $sectionId): void
    {

        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        if (! app(RequeueSectionPublication::class)->execute($section)) {
            $this->error('This section cannot be requeued in its current state.');

            return;
        }

        $this->success('Section moved back to pending approval.');
    }
}
