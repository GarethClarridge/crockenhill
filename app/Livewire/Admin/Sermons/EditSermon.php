<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sermons;

use App\Actions\SaveSermonDetails;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonVideoVisibilityOverride;
use App\Jobs\AssessSermonVideoQuality;
use App\Livewire\Forms\SermonFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

class EditSermon extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public SermonFormData $form;

    public Sermon $sermon;

    public bool $isChildrensTalk = false;

    public string $contentTypeLabel = 'Sermon';

    /** @var Collection<int, string> */
    public Collection $preacherOptions;

    public function mount(Sermon $sermon, SermonViewPresenter $sermonViewPresenter): void
    {
        $this->sermon = $sermon;
        $this->form->setSermon($sermon, $sermonViewPresenter);

        $this->isChildrensTalk = $sermon->content_type === SermonContentType::ChildrensTalk;
        $this->contentTypeLabel = $sermon->content_type->label();
        $this->preacherOptions = Preacher::query()->active()->orderBy('name')->pluck('name', 'id');
    }

    public function addPoint(): void
    {
        $this->form->addPoint();
    }

    public function removePoint(int $index): void
    {
        $this->form->removePoint($index);
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $validated = $this->form->validate();

        app(SaveSermonDetails::class)->execute($this->sermon, $validated);

        $this->success($this->contentTypeLabel.' updated');
    }

    public function setVideoVisibilityOverride(string $override): void
    {
        $this->authorizeAdmin();

        $visibilityOverride = SermonVideoVisibilityOverride::tryFrom($override);
        if (! $visibilityOverride instanceof SermonVideoVisibilityOverride) {
            $this->error('Invalid video visibility override.');

            return;
        }

        $this->sermon->update([
            'video_visibility_override' => $visibilityOverride,
        ]);

        $this->sermon->refresh();

        $this->success('Video visibility override updated');
    }

    public function rerunVideoQualityAssessment(): void
    {
        $this->authorizeAdmin();
        $this->sermon->refresh();

        if (! $this->sermon->hasVideo()) {
            $this->error('No video file is available for assessment.');

            return;
        }

        dispatch((new AssessSermonVideoQuality(sermonId: $this->sermon->id))
            ->onQueue((string) config('media-processing.queues.video', 'video-processing')));

        $this->success('Video quality assessment queued');
    }

    public function refreshVideoQualityAssessment(): void
    {
        $this->authorizeAdmin();
        $this->sermon->refresh();
    }

    public function render(): View
    {
        return view('livewire.admin.sermons.edit-sermon', [
            'services' => SermonService::cases(),
            'preachers' => $this->preacherOptions,
            'points' => $this->form->points,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->sermon->title, 'heading' => 'Edit '.$this->contentTypeLabel]);
    }
}
