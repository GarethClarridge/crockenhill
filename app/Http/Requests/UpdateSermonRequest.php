<?php

namespace App\Http\Requests;

use App\Enums\SermonService;
use App\Models\Sermon; // Added for type hinting and fetching model
use Illuminate\Foundation\Http\FormRequest; // Added for Enum validation
use Illuminate\Validation\Rules\Enum; // Added Enum import

class UpdateSermonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $year = $this->route('year');
        $month = $this->route('month');
        $slug = $this->route('slug');

        // Attempt to find the sermon model instance
        // This replicates the logic from SermonController::findSermonOrFail without aborting directly
        $sermon = Sermon::where('slug', $slug)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->first();

        if (! $sermon) {
            return false; // If sermon not found, deny access
        }

        return $this->user()->can('update', $sermon);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            // 'file' is not included here; file updates are typically handled separately or not at all in this form.
            // If file updates were allowed, it would be 'nullable|file|mimes:mp3|max:51200'.
            'date' => 'required|date_format:Y-m-d',
            'service' => ['required', new Enum(SermonService::class)],
            'series' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'preacher' => 'required|string|max:255',
            'points' => 'nullable|json', // Expects a JSON string or null
        ];
    }

    public function messages(): array
    {
        return [
            'points.json' => 'The sermon outline points must be a valid JSON structure.',
        ];
    }
}
