<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Models\Meeting;
use Livewire\Form;

class MeetingFormData extends Form
{
    public ?Meeting $meeting = null;

    public string $slug = '';

    public string $type = 'SundayAndBibleStudies';

    public string $startTime = '';

    public string $endTime = '';

    public ?string $day = null;

    public ?string $location = null;

    public string $who = '';

    public bool $pictures = false;

    public ?string $leadersPhone = null;

    public ?string $leadersEmail = null;

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
    protected function validationAttributes(): array
    {
        return [
            'leadersPhone' => 'leaders phone',
            'leadersEmail' => 'leaders email',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $modelRules = Meeting::validationRules($this->meeting);

        return [
            'slug' => $modelRules['slug'],
            'type' => $modelRules['type'],
            'startTime' => $modelRules['start_time'],
            'endTime' => array_map(
                fn ($rule) => $rule === 'after_or_equal:start_time' ? 'after_or_equal:startTime' : $rule,
                $modelRules['end_time']
            ),
            'day' => $modelRules['day'],
            'location' => $modelRules['location'],
            'who' => $modelRules['who'],
            'pictures' => $modelRules['pictures'],
            'leadersPhone' => $modelRules['leaders_phone'],
            'leadersEmail' => $modelRules['leaders_email'],
            'meetingDate' => $modelRules['meeting_date'],
            'isRecurring' => $modelRules['is_recurring'],
            'frequency' => array_map(
                fn ($rule) => $rule === 'required_if:is_recurring,true' ? 'required_if:isRecurring,true' : $rule,
                $modelRules['frequency']
            ),
            'pageId' => $modelRules['page_id'],
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

        $validated = $this->validate();

        return Meeting::query()->create($this->meetingPayload($validated));
    }

    public function update(): void
    {
        $this->normalizeForSave();

        $validated = $this->validate();

        $this->meeting?->update($this->meetingPayload($validated));
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

        $this->slug = trim($this->slug);
        $this->day = trim((string) $this->day) ?: null;
        $this->who = trim($this->who);

        $this->location = trim((string) $this->location) ?: null;
        $this->leadersPhone = trim((string) $this->leadersPhone) ?: null;
        $this->leadersEmail = strtolower(trim((string) $this->leadersEmail)) ?: null;
    }
}
