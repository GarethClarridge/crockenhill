<?php

namespace App\Http\Requests;

use App\Models\Sermon;
use Illuminate\Foundation\Http\FormRequest;

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
        $modelRules = Sermon::validationRules($sermon instanceof Sermon ? $sermon : null);

        return [
            'title' => $modelRules['title'],
            'slug' => $modelRules['slug'],
            'date' => 'required|date_format:Y-m-d',
            'service' => array_merge(['required'], array_filter($modelRules['service'], fn ($r) => $r !== 'nullable')),
            'series' => $modelRules['series'],
            'reference' => $modelRules['reference'],
            'preacher' => $modelRules['preacher'],
            'preacher_source' => $modelRules['preacher_source'],
            'preacher_confidence' => $modelRules['preacher_confidence'],
            'download_count' => $modelRules['download_count'],
            'duration' => $modelRules['duration'],
            'segment_start_time' => $modelRules['segment_start_time'],
            'segment_end_time' => $modelRules['segment_end_time'],
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
