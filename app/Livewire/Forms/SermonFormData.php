<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\SermonService;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Support\Str;
use Livewire\Form;

/**
 * @phpstan-import-type ThumbnailCandidate from \App\Models\Sermon
 */
class SermonFormData extends Form
{
    public ?Sermon $sermon = null;

    public string $title = '';

    public string $slug = '';

    public string $date = '';

    public string $service = '';

    public string $preacher = '';

    public ?int $preacherId = null;

    public ?string $preacherSource = null;

    public ?float $preacherConfidence = null;

    public ?float $duration = null;

    public ?float $segmentStartTime = null;

    public ?float $segmentEndTime = null;

    public ?int $downloadCount = null;

    public ?string $reference = null;

    public ?string $series = null;

    public ?string $summary = null;

    /** @var array<int, string> */
    public array $points = [];

    public bool $showSummary = true;

    public bool $showPoints = true;

    public string $lastGeneratedSlug = '';

    public function setSermon(Sermon $sermon, SermonViewPresenter $presenter): void
    {
        $sermon->loadMissing('preacherProfile', 'scripturePassage');

        $this->sermon = $sermon;

        $service = $sermon->service;
        if (! $service instanceof SermonService) {
            throw new \UnexpectedValueException('Sermon service is required.');
        }

        $this->fill([
            'title' => $sermon->title,
            'slug' => $sermon->slug,
            'date' => $sermon->date->format('Y-m-d'),
            'service' => $service->value,
            'preacher' => $presenter->displayPreacherName($sermon) ?? '',
            'preacherId' => $sermon->preacher_id,
            'preacherSource' => $sermon->preacher_source?->value,
            'preacherConfidence' => $sermon->preacher_confidence,
            'duration' => $sermon->duration,
            'segmentStartTime' => $sermon->segment_start_time,
            'segmentEndTime' => $sermon->segment_end_time,
            'downloadCount' => $sermon->download_count,
            'reference' => $presenter->displayReference($sermon),
            'series' => $sermon->series,
            'summary' => $sermon->summary,
            'points' => $sermon->points ?? [],
            'showSummary' => $sermon->show_summary,
            'showPoints' => $sermon->show_points,
        ]);

        $this->lastGeneratedSlug = (string) Str::slug($this->title);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $modelRules = Sermon::validationRules($this->sermon);

        return [
            'title' => $modelRules['title'],
            'slug' => $modelRules['slug'],
            'date' => $modelRules['date'],
            'service' => $modelRules['service'],
            'preacher' => $modelRules['preacher'],
            'preacherId' => $modelRules['preacher_id'],
            'preacherSource' => $modelRules['preacher_source'],
            'preacherConfidence' => $modelRules['preacher_confidence'],
            'duration' => $modelRules['duration'],
            'segmentStartTime' => $modelRules['segment_start_time'],
            'segmentEndTime' => $modelRules['segment_end_time'],
            'downloadCount' => $modelRules['download_count'],
            'reference' => $modelRules['reference'],
            'series' => $modelRules['series'],
            'summary' => $modelRules['summary'],
            'points' => $modelRules['points'],
            'showSummary' => $modelRules['show_summary'],
            'showPoints' => $modelRules['show_points'],
        ];
    }

    public function updatedTitle(): void
    {
        $generatedSlug = (string) Str::slug($this->title);

        if ($this->slug === '' || $this->slug === $this->lastGeneratedSlug) {
            $this->slug = $generatedSlug;
        }

        $this->lastGeneratedSlug = $generatedSlug;
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
}
