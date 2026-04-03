<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Models\Meeting;
use Illuminate\Validation\Rule;
use Livewire\Form;

class MeetingFormData extends Form
{
    public ?Meeting $meeting = null;

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

    public function setMeeting(Meeting $meeting): void
    {
        $this->meeting = $meeting;

        $this->fill([
            'slug' => $meeting->slug,
            'type' => $meeting->type->value,
            'startTime' => $meeting->start_time?->format('H:i') ?? '',
            'endTime' => $meeting->end_time?->format('H:i') ?? '',
            'day' => $meeting->day,
            'location' => $meeting->location ?? '',
            'who' => $meeting->who,
            'pictures' => $meeting->pictures,
            'leadersPhone' => $meeting->leaders_phone ?? '',
            'leadersEmail' => $meeting->leaders_email ?? '',
            'meetingDate' => $meeting->meeting_date?->format('Y-m-d') ?? '',
            'isRecurring' => $meeting->is_recurring,
            'frequency' => $meeting->frequency?->value,
            'pageId' => $meeting->page_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('meetings', 'slug')->ignore($this->meeting),
            ],
            'type' => ['required', 'string', 'in:'.implode(',', MeetingType::values())],
            'startTime' => 'nullable|date_format:H:i:s,H:i',
            'endTime' => 'nullable|date_format:H:i:s,H:i|after_or_equal:startTime',
            'day' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'who' => 'required|string|max:255',
            'pictures' => 'boolean',
            'leadersPhone' => 'nullable|string|max:255',
            'leadersEmail' => 'nullable|email|max:255',
            'meetingDate' => 'nullable|date_format:Y-m-d',
            'isRecurring' => 'boolean',
            'frequency' => ['nullable', 'required_if:isRecurring,true', 'in:'.implode(',', MeetingFrequency::values())],
            'pageId' => [
                'nullable',
                'exists:pages,id',
                Rule::unique('meetings', 'page_id')->ignore($this->meeting),
            ],
        ];
    }

    public function updatedIsRecurring(bool $value): void
    {
        if (! $value) {
            $this->frequency = null;
        }
    }

    public function store(): Meeting
    {
        $this->normalizeForSave();

        return Meeting::create($this->meetingPayload($this->validate()));
    }

    public function update(): void
    {
        $this->normalizeForSave();

        $this->meeting?->update($this->meetingPayload($this->validate()));
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function typeOptions(): array
    {
        return collect(MeetingType::cases())
            ->map(fn (MeetingType $type): array => ['id' => $type->value, 'name' => $type->label()])
            ->toArray();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function frequencyOptions(): array
    {
        return collect(MeetingFrequency::cases())
            ->map(fn (MeetingFrequency $frequency): array => ['id' => $frequency->value, 'name' => $frequency->label()])
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function meetingPayload(array $validated): array
    {
        $today = now()->toDateString();

        return [
            'slug' => $validated['slug'],
            'type' => $validated['type'],
            'start_time' => $validated['startTime'] !== '' ? $today.' '.$validated['startTime'] : null,
            'end_time' => $validated['endTime'] !== '' ? $today.' '.$validated['endTime'] : null,
            'day' => $validated['day'],
            'location' => $validated['location'],
            'who' => $validated['who'],
            'pictures' => $validated['pictures'],
            'leaders_phone' => $validated['leadersPhone'],
            'leaders_email' => $validated['leadersEmail'],
            'meeting_date' => $validated['meetingDate'] !== '' ? $validated['meetingDate'] : null,
            'is_recurring' => $validated['isRecurring'],
            'frequency' => $validated['isRecurring'] ? $validated['frequency'] : null,
            'page_id' => $validated['pageId'],
        ];
    }

    protected function normalizeForSave(): void
    {
        if (! $this->isRecurring) {
            $this->frequency = null;
        }
    }
}
