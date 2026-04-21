<?php

namespace App\Http\Requests;

use App\Enums\PreacherSource;
use App\Enums\SermonService;
use App\Models\Sermon; // Added for type hinting and fetching model
use Illuminate\Foundation\Http\FormRequest; // Added for Enum validation
use Illuminate\Validation\Rule; // Added Rule import

class UpdateSermonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sermon = $this->route('sermon'); // Get the bound Sermon model
        $user = $this->user();

        if (! $sermon instanceof Sermon || $user === null) {
            return false;
        }

        return $user->can('update', $sermon);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sermon = $this->route('sermon');
        $sermonId = $sermon instanceof Sermon ? $sermon->id : $sermon;

        return [
            'title' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('sermons', 'slug')->ignore($sermonId)],
            'audio_file_path' => 'required|string|max:255',
            'date' => 'required|date_format:Y-m-d',
            'service' => ['required', Rule::enum(SermonService::class)],
            'series' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'preacher' => 'required|string|max:255',
            'preacher_source' => ['nullable', Rule::enum(PreacherSource::class)],
            'preacher_confidence' => 'nullable|numeric|min:0|max:1',
            'download_count' => 'nullable|integer|min:0',
            'duration' => 'nullable|numeric|min:0',
            'segment_start_time' => 'nullable|numeric|min:0',
            'segment_end_time' => 'nullable|numeric|min:0|gte:segment_start_time',
            'points' => 'nullable|json', // Expects a JSON string or null
            'summary' => 'nullable|string|max:1000',
            'show_summary' => 'nullable|boolean',
            'show_points' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.json' => 'The sermon outline points must be a valid JSON structure.',
        ];
    }
}
