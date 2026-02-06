<?php

namespace App\Livewire\Admin\Sermons;

use App\Enums\SermonService;
use App\Livewire\Traits\WithNotifications;
use App\Models\Sermon;
use Illuminate\Support\Str;
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

    public ?string $reference = null;

    public ?string $series = null;

    public ?string $summary = null;

    public array $points = [];

    public bool $showSummary = true;

    public bool $showPoints = true;

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:sermons,slug,'.$this->sermon->id,
            'date' => 'required|date',
            'service' => ['required', 'string', 'in:'.implode(',', SermonService::values())],
            'preacher' => 'required|string|max:255',
            'reference' => 'nullable|string|max:255',
            'series' => 'nullable|string|max:255',
            'summary' => 'nullable|string',
            'points' => 'array',
            'showSummary' => 'boolean',
            'showPoints' => 'boolean',
        ];
    }

    public function mount(Sermon $sermon): void
    {
        $this->sermon = $sermon;
        $this->title = $sermon->title;
        $this->slug = $sermon->slug;
        $this->date = $sermon->date->format('Y-m-d');
        $this->service = $sermon->service->value;
        $this->preacher = $sermon->preacher;
        $this->reference = $sermon->reference;
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

        $this->sermon->update([
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'date' => $validated['date'],
            'service' => $validated['service'],
            'preacher' => $validated['preacher'],
            'reference' => $validated['reference'],
            'series' => $validated['series'],
            'summary' => $validated['summary'],
            'points' => array_filter($this->points),
            'show_summary' => $validated['showSummary'],
            'show_points' => $validated['showPoints'],
        ]);

        $this->success('Sermon updated');
    }

    public function render()
    {
        return view('livewire.admin.sermons.edit-sermon', [
            'services' => SermonService::cases(),
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->sermon->title, 'heading' => 'Edit Sermon']);
    }
}
