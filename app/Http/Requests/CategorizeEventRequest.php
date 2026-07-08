<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CategorizeEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Security: integer bounding guards against malformed input and overflow before the exists lookup runs.
            'event_id' => ['required', 'integer', 'min:1', 'max:2147483647', 'exists:calendar_events,id'],
            'meeting_slug' => ['required', 'string', 'max:255', 'exists:meetings,slug'],
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
            'meeting_slug.required' => 'Please select a meeting type.',
            'meeting_slug.string' => 'The selected meeting is invalid.',
            'meeting_slug.exists' => 'The selected meeting does not exist.',
        ];
    }
}
