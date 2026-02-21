<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;

trait MeetingForm
{
    public string $slug = '';

    public string $type = 'SundayAndBibleStudies';

    public string $startTime = '';

    public string $endTime = '';

    public string $day = '';

    public string $location = '';

    public string $who = '';

    public bool $pictures = false;

    public string $leadersPhone = '';

    public string $leadersEmail = '';

    public string $meetingDate = '';

    public bool $isRecurring = false;

    public ?string $frequency = null;

    public ?int $pageId = null;

    protected function rules(): array
    {
        $meetingId = isset($this->meeting) && $this->meeting->exists ? $this->meeting->id : '';

        return [
            'slug' => 'required|string|max:255|alpha_dash|unique:meetings,slug,'.$meetingId,
            'type' => ['required', 'string', 'in:'.implode(',', MeetingType::values())],
            'startTime' => 'nullable|date_format:H:i',
            'endTime' => 'nullable|date_format:H:i',
            'day' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'who' => 'required|string|max:255',
            'pictures' => 'boolean',
            'leadersPhone' => 'nullable|string|max:255',
            'leadersEmail' => 'nullable|email|max:255',
            'meetingDate' => 'nullable|date',
            'isRecurring' => 'boolean',
            'frequency' => ['nullable', 'required_if:isRecurring,true', 'in:'.implode(',', MeetingFrequency::values())],
            'pageId' => 'nullable|exists:pages,id',
        ];
    }

    public function updatedIsRecurring(): void
    {
        if (! $this->isRecurring) {
            $this->frequency = null;
        }
    }

    protected function getTypeOptions(): array
    {
        return collect(MeetingType::cases())
            ->map(fn ($type) => ['id' => $type->value, 'name' => $type->label()])
            ->toArray();
    }

    protected function getFrequencyOptions(): array
    {
        return collect(MeetingFrequency::cases())
            ->map(fn ($freq) => ['id' => $freq->value, 'name' => $freq->label()])
            ->toArray();
    }
}
