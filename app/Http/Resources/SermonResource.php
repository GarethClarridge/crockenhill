<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
