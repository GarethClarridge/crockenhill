<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sermons;

use App\Actions\QueueScriptureEnrichment;
use App\Enums\PreacherSource;
use App\Enums\SermonService;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\PreacherResolutionService;
use App\Services\SermonIdentitySyncService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class EditSermon extends Component
{
    use WithNotifications;

    public Sermon $sermon;

    public string $title = '';

    public string $slug = '';

    public string $date = '';

    public string $service = '';

    public string $preacher = '';

    public ?int $preacherId = null;

    public ?string $reference = null;

    public ?string $series = null;

    public ?string $summary = null;

    /** @var array<int, string> */
    public array $points = [];

    public bool $showSummary = true;

    public bool $showPoints = true;

    public bool $isChildrensTalk = false;

    public string $contentTypeLabel = 'Sermon';

    /** @var \Illuminate\Support\Collection<int, string> */
    public \Illuminate\Support\Collection $preacherOptions;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:sermons,slug,'.$this->sermon->id,
            'date' => 'required|date',
            'service' => ['required', 'string', 'in:'.implode(',', SermonService::values())],
            'preacher' => 'required|string|max:255',
            'preacherId' => 'nullable|integer|exists:preachers,id',
            'reference' => 'nullable|string|max:255',
            'series' => 'nullable|string|max:255',
            'summary' => 'nullable|string|max:1000',
            'points' => 'array',
            'showSummary' => 'boolean',
            'showPoints' => 'boolean',
        ];
    }

    public function mount(Sermon $sermon): void
    {
        $sermon->loadMissing('preacherProfile', 'scripturePassage');

        $service = $sermon->service;
        if (! $service instanceof SermonService) {
            throw new \UnexpectedValueException('Sermon service is required.');
        }

        $this->sermon = $sermon;
        $this->isChildrensTalk = $sermon->content_type === \App\Enums\SermonContentType::ChildrensTalk;
        $this->contentTypeLabel = $sermon->content_type->label();
        $this->preacherOptions = Preacher::active()->orderBy('name')->pluck('name', 'id');
        $this->title = $sermon->title;
        $this->slug = $sermon->slug;
        $this->date = $sermon->date->format('Y-m-d');
        $this->service = $service->value;
        $this->preacher = $sermon->displayPreacherName() ?? '';
        $this->preacherId = $sermon->preacher_id;
        $this->reference = $sermon->displayReference();
        $this->series = $sermon->series;
        $this->summary = $sermon->summary;
        $this->points = $sermon->points ?? [];
        $this->showSummary = $sermon->show_summary;
        $this->showPoints = $sermon->show_points;
    }

    public function updatedTitle(): void
    {
        $this->slug = Str::slug($this->title);
    }

    public function addPoint(): void
    {
        $this->points[] = '';
    }

    public function removePoint(int $index): void
    {
        unset($this->points[$index]);
        $this->points = array_values($this->points);
    }

    public function save(): void
    {
        $validated = $this->validate();

        if ($validated['preacherId']) {
            $preacher = Preacher::find($validated['preacherId']);
        } else {
            $preacher = app(PreacherResolutionService::class)->resolve($validated['preacher']);
        }

        if (! ($preacher instanceof Preacher)) {
            $preacher = null;
        }

        $referenceChanged = $this->sermon->reference !== $validated['reference'];
        $newReference = $validated['reference'];
        $scripturePassage = app(SermonIdentitySyncService::class)->findExistingScripturePassage($newReference);
        $scripturePassageId = ($referenceChanged || $this->sermon->scripture_passage_id === null)
            ? $scripturePassage?->id
            : $this->sermon->scripture_passage_id;

        $updateData = [
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'date' => $validated['date'],
            'service' => $validated['service'],
            'preacher' => $preacher ? $preacher->name : $validated['preacher'],
            'preacher_id' => $preacher?->id,
            'preacher_source' => $preacher ? PreacherSource::MANUAL->value : null,
            'needs_preacher_review' => false,
            'reference' => $newReference,
            'scripture_passage_id' => $scripturePassageId,
            'series' => $validated['series'],
            'summary' => $validated['summary'],
            'points' => array_filter($this->points),
            'show_summary' => $validated['showSummary'],
            'show_points' => $validated['showPoints'],
        ];

        $this->sermon->update($updateData);

        // Dispatch enrichment after saving if reference was set or changed
        if ($referenceChanged && ! empty($newReference)) {
            app(QueueScriptureEnrichment::class)->dispatch($this->sermon->fresh() ?? $this->sermon);
        }

        $this->success('Sermon updated');
    }

    public function render(): View
    {
        return view('livewire.admin.sermons.edit-sermon', [
            'services' => SermonService::cases(),
            'preachers' => $this->preacherOptions,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->sermon->title, 'heading' => 'Edit '.$this->contentTypeLabel]);
    }
}
