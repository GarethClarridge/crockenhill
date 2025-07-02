<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'date' => $this->date?->format('Y-m-d'),
            'human_date' => $this->human_date,
            'service' => $this->service,
            'preacher' => $this->preacher,
            'series' => $this->series,
            'reference' => $this->reference,
            'points' => $this->points,
            'audio_url' => $this->audio_url,
            'series_url' => $this->series_url,
            'preacher_url' => $this->preacher_url,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
