<?php

namespace Crockenhill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSermonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // Reuse the same authorization logic as in the controller
        return Gate::allows('edit-sermons');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'title'     => 'required|string|max:255',
            'file'      => 'required|file|mimes:mp3|max:51200', // Max 50MB
            'date'      => 'required|date_format:Y-m-d', // Assuming Y-m-d format from form
            'service'   => 'required|string|in:morning,evening,other', // Added 'other' as a common fallback
            'series'    => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'preacher'  => 'required|string|max:255',
            // Individual points (e.g., 'point-1', 'sub-point-1-1') are not validated here
            // due to their dynamic nature and the recent refactoring to store points as JSON.
            // Validation of the structured 'points' data could be added later if necessary.
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'file.max' => 'The sermon audio file may not be greater than 50MB.',
            'file.mimes' => 'The sermon audio file must be an MP3.',
            'service.in' => 'Please select a valid service (morning, evening, or other).',
        ];
    }
}
