<?php

namespace App\Http\Requests;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest; // Required for policy check
use Illuminate\Validation\Rule; // Renamed to avoid conflict
use Illuminate\Validation\Rules\Enum as EnumRule; // Added Enum import

class UpdateMeetingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('meeting'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $meetingId = $this->route('meeting') instanceof Meeting ? $this->route('meeting')->id : $this->route('meeting');

        return [
            'slug' => [
                'required',
                'string',
                'max:75',
                Rule::unique('meetings', 'slug')->ignore($meetingId),
            ],
            'type' => ['required', new EnumRule(MeetingType::class)],
            'day' => 'required|string|max:75',
            'location' => 'nullable|string|max:75',
            'who' => 'required|string|max:75',
            'pictures' => 'required|boolean',
            'start_time' => 'nullable|date_format:H:i:s,H:i',
            'end_time' => 'nullable|date_format:H:i:s,H:i',
            'leaders_phone' => 'nullable|string|max:10',
            'leaders_email' => 'nullable|email|max:50',
            'meeting_date' => 'nullable|date',
            'is_recurring' => 'nullable|boolean',
            'frequency' => ['nullable', new EnumRule(MeetingFrequency::class)],
        ];
    }
}
