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
        $user = $this->user();
        $meeting = $this->route('meeting');

        if ($user === null || $meeting === null) {
            return false;
        }

        return $user->can('update', $meeting);
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
                'max:255',
                Rule::unique('meetings', 'slug')->ignore($meetingId),
            ],
            'type' => ['required', new EnumRule(MeetingType::class)],
            'day' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'who' => 'required|string|max:255',
            'pictures' => 'required|boolean',
            'start_time' => 'nullable|date_format:H:i:s,H:i',
            'end_time' => 'nullable|date_format:H:i:s,H:i',
            'leaders_phone' => 'nullable|string|max:255',
            'leaders_email' => 'nullable|email|max:255',
            'meeting_date' => 'nullable|date',
            'is_recurring' => 'nullable|boolean',
            'frequency' => ['nullable', new EnumRule(MeetingFrequency::class)],
        ];
    }
}
