<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @mixin \App\Models\Sermon
 */
class SermonResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'date' => $this->date->format('Y-m-d'),
            'human_date' => $this->human_date,
            'service' => $this->service,
            'preacher' => $this->preacher,
            'preacher_id' => $this->preacher_id,
            'preacher_details' => $this->whenLoaded('preacherProfile', fn () => $this->preacherProfile ? [
                'id' => $this->preacherProfile->id,
                'name' => $this->preacherProfile->name,
                'slug' => $this->preacherProfile->slug,
                'image_url' => $this->preacherProfile->image_path
                    ? (Str::startsWith($this->preacherProfile->image_path, ['http://', 'https://', '/'])
                        ? $this->preacherProfile->image_path
                        : Storage::disk('public')->url($this->preacherProfile->image_path))
                    : null,
            ] : null),
            'preacher_source' => $this->preacher_source,
            'preacher_confidence' => $this->preacher_confidence,
            'needs_preacher_review' => $this->needs_preacher_review,
            'series' => $this->series,
            'reference' => $this->reference,
            'points' => $this->points,
            'audio_url' => $this->audio_url,
            'thumbnail_url' => $this->thumbnail_url,
            'thumbnail_metadata' => $this->thumbnail_metadata,
            'series_url' => $this->series_url,
            'preacher_url' => $this->preacher_url,
        ];
    }
}
