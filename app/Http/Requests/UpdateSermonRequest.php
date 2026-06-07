<?php

declare(strict_types=1);

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
            'date' => $modelRules['date'],
            'service' => array_merge(['required'], array_filter($modelRules['service'], fn ($r) => $r !== 'nullable')),
            'series' => $modelRules['series'],
            'reference' => $modelRules['reference'],
            'preacher' => $modelRules['preacher'],
            'preacher_id' => $modelRules['preacher_id'],
            'preacher_source' => $modelRules['preacher_source'],
            'preacher_confidence' => $modelRules['preacher_confidence'],
            'download_count' => $modelRules['download_count'],
            'duration' => $modelRules['duration'],
            'segment_start_time' => $modelRules['segment_start_time'],
            'segment_end_time' => $modelRules['segment_end_time'],
            'points' => $modelRules['points'],
            'points.*' => $modelRules['points.*'],
            'points.*.point' => $modelRules['points.*.point'],
            'points.*.sub_points' => $modelRules['points.*.sub_points'],
            'points.*.sub_points.*' => $modelRules['points.*.sub_points.*'],
            'summary' => $modelRules['summary'],
            'show_summary' => $modelRules['show_summary'],
            'show_points' => $modelRules['show_points'],
            'needs_preacher_review' => $modelRules['needs_preacher_review'],
            'meta_description' => $modelRules['meta_description'],
        ];
    }
}
