<?php

namespace App\Http\Requests;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use Illuminate\Foundation\Http\FormRequest; // Renamed to avoid conflict with class Enum
use Illuminate\Validation\Rules\Enum as EnumRule; // Added Enum import

class StoreMeetingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Meeting::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => 'required|string|max:75|unique:meetings,slug',
            'type' => ['required', new EnumRule(MeetingType::class)],
            'day' => 'required|string|max:75',
            'location' => 'nullable|string|max:75',
            'who' => 'required|string|max:75',
            'pictures' => 'required|boolean',
            'StartTime' => 'nullable|date_format:H:i:s,H:i',
            'EndTime' => 'nullable|date_format:H:i:s,H:i',
            'LeadersPhone' => 'nullable|string|max:10',
            'LeadersEmail' => 'nullable|email|max:50',
            'meeting_date' => 'nullable|date',
            'is_recurring' => 'nullable|boolean',
            'frequency' => ['nullable', new EnumRule(MeetingFrequency::class)],
        ];
    }
}
