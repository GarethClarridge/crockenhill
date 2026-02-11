<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategorizeEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => 'required|integer|exists:calendar_events,id',
            'meeting_slug' => 'required|string|exists:meetings,slug',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_id.required' => 'Please select a calendar event.',
            'event_id.integer' => 'The calendar event must be a valid ID.',
            'event_id.exists' => 'The selected calendar event does not exist.',
            'meeting_slug.required' => 'Please select a meeting category.',
            'meeting_slug.string' => 'The meeting must be a valid value.',
            'meeting_slug.exists' => 'The selected meeting does not exist.',
        ];
    }
}
