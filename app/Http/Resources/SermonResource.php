<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use LogicException;

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
        $sermonView = $this->sermonView();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'date' => $this->date->format('Y-m-d'),
            'human_date' => $this->human_date,
            'service' => $this->service,
            'preacher' => $this->displayPreacherName(),
            'preacher_id' => $this->preacher_id,
            'preacher_details' => $this->whenLoaded('preacherProfile', fn () => $this->preacherProfile ? [
                'id' => $this->preacherProfile->id,
                'name' => $this->preacherProfile->name,
                'slug' => $this->preacherProfile->slug,
                'image_url' => $this->preacherProfile->profile_image_url,
            ] : null),
            'series' => $this->series,
            'reference' => $this->displayReference(),
            'points' => $this->when($this->show_points, fn (): ?array => $this->points),
            'audio_url' => $sermonView['audio_url'],
            'video_url' => $sermonView['video_url'],
            'thumbnail_url' => $sermonView['thumbnail_url'],
            'thumbnail_metadata' => $this->publicThumbnailMetadata(),
            'series_url' => $sermonView['series_url'],
            'preacher_url' => $sermonView['preacher_url'],
        ];
    }

    /**
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     series_url: ?string,
     *     thumbnail_url: ?string,
     *     transcript: ?string,
     *     video_url: ?string
     * }
     */
    private function sermonView(): array
    {
        $sermonView = $this->resource->getAttribute('sermon_view');

        if (! is_array($sermonView)) {
            throw new LogicException('SermonResource requires precomputed sermon_view data.');
        }

        /** @var array{
         *     audio_url: ?string,
         *     canonical_url: string,
         *     preacher_url: ?string,
         *     public_url: string,
         *     series_url: ?string,
         *     thumbnail_url: ?string,
         *     transcript: ?string,
         *     video_url: ?string
         * } $sermonView */
        return $sermonView;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicThumbnailMetadata(): ?array
    {
        $thumbnailMetadata = $this->thumbnail_metadata?->toArray();

        if ($thumbnailMetadata === null) {
            return null;
        }

        return Arr::only($thumbnailMetadata, [
            'timestamp',
            'video_duration',
            'video_resolution',
            'thumbnail_sizes',
            'generated_at',
            'width',
            'height',
            'size',
            'generation_info',
            'file_info',
            'formats',
        ]);
    }
}
