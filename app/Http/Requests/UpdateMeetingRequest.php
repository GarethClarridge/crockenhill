<?php

namespace App\Http\Requests;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $meeting = $this->route('meeting');
        $modelRules = Meeting::validationRules($meeting instanceof Meeting ? $meeting : null);

        return [
            'slug' => $modelRules['slug'],
            'type' => ['required', Rule::enum(MeetingType::class)],
            'day' => $modelRules['day'],
            'location' => $modelRules['location'],
            'who' => $modelRules['who'],
            'pictures' => 'required|boolean',
            'start_time' => 'nullable|date_format:H:i:s,H:i',
            'end_time' => 'nullable|date_format:H:i:s,H:i|after_or_equal:start_time',
            'leaders_phone' => $modelRules['leaders_phone'],
            'leaders_email' => $modelRules['leaders_email'],
            'meeting_date' => 'nullable|date_format:Y-m-d',
            'is_recurring' => 'nullable|boolean',
            'frequency' => ['nullable', 'required_if:is_recurring,true', Rule::enum(MeetingFrequency::class)],
            'page_id' => $modelRules['page_id'],
        ];
    }
}
