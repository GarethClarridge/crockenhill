<?php

namespace Crockenhill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSermonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('edit-sermons');
    }

    public function rules(): array
    {
        return [
            'title'     => 'required|string|max:255',
            // 'file' is not included here; file updates are typically handled separately or not at all in this form.
            // If file updates were allowed, it would be 'nullable|file|mimes:mp3|max:51200'.
            'date'      => 'required|date_format:Y-m-d',
            'service'   => 'required|string|in:morning,evening,other',
            'series'    => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'preacher'  => 'required|string|max:255',
            'points'    => 'nullable|json', // Expects a JSON string or null
        ];
    }

    public function messages(): array
    {
        return [
            'points.json' => 'The sermon outline points must be a valid JSON structure.',
        ];
    }
}
