<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChurchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChurchService
 */
class ChurchServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->format('Y-m-d'),
            'service' => $this->service->value,
            'source' => $this->source,
            'original_filename' => $this->original_filename,
            'needs_review' => $this->needs_review,
            'summary' => $this->summary,
            'notices' => $this->notices,
            'chapter_markers' => $this->chapter_markers,
            'import_metadata' => $this->import_metadata,
            'items' => ChurchServiceItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
