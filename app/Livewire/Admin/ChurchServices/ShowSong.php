<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\Song\SongVideoService;
use App\Traits\SanitizesLogData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

class ShowSong extends Component
{
    use SanitizesLogData, WithAdminAuthorization;

    public Song $song;

    public function mount(Song $song): void
    {
        $this->abortIfDisabled();

        $this->song = $song->load([
            'authors' => fn ($query) => $query->orderBy('display_name'),
            'books' => fn ($query) => $query->orderBy('name')->orderBy('song_book_song.entry'),
        ]);
    }

    public function render(SongVideoService $songVideoService): View
    {
        $importMetadata = is_array($this->song->import_metadata ?? null) ? $this->song->import_metadata : [];
        $parseWarnings = is_array($importMetadata['lyrics_parse_warnings'] ?? null) ? $importMetadata['lyrics_parse_warnings'] : [];
        /** @var array<string, mixed> $stats */
        $stats = (array) ($this->usageBaseQuery()
            ->selectRaw('COUNT(*) AS usage_count')
            ->selectRaw('MAX(church_services.date) AS last_used_date')
            ->toBase()
            ->first() ?? []);

        $usageCount = is_numeric($stats['usage_count'] ?? null) ? (int) $stats['usage_count'] : 0;
        $lastUsedDate = is_string($stats['last_used_date'] ?? null) ? $stats['last_used_date'] : null;
        $usageByYear = $this->usageBaseQuery()
            ->selectRaw('YEAR(church_services.date) AS year, COUNT(*) AS count')
            ->groupByRaw('YEAR(church_services.date)')
            ->orderByRaw('YEAR(church_services.date)')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['year' => (int) $row->year, 'count' => (int) $row->count])
            ->values()
            ->all();

        $videos = $this->song->videos()->with('churchService')->get()
            ->map(fn (SongVideo $video): array => [
                'id' => $video->id,
                'url' => $songVideoService->getVideoUrl($video),
                'recorded_date' => $video->recorded_date?->format('j M Y'),
                'duration' => $video->duration,
                'is_featured' => $video->is_featured,
            ])
            ->all();

        return view('livewire.admin.church-services.show-song', [
            'importMetadata' => $importMetadata,
            'parseWarnings' => $parseWarnings,
            'usageCount' => $usageCount,
            'lastUsedDate' => $lastUsedDate,
            'usageByYear' => $usageByYear,
            'videos' => $videos,
        ])->layout('layouts.admin', [
            'title' => $this->song->title,
            'heading' => $this->song->title,
        ]);
    }

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function featureVideo(int $videoId): void
    {
        $this->authorizeAdmin();

        $video = SongVideo::query()->where('song_id', $this->song->id)->findOrFail($videoId);
        app(SongVideoService::class)->featureVideo($video);
    }

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function unfeatureVideo(int $videoId): void
    {
        $this->authorizeAdmin();

        $video = SongVideo::query()->where('song_id', $this->song->id)->findOrFail($videoId);
        app(SongVideoService::class)->unfeatureVideo($video);
    }

    /**
     * Delete a video associated with the song.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function deleteVideo(int $videoId): void
    {
        $this->authorizeAdmin();

        $video = SongVideo::query()->where('song_id', $this->song->id)->findOrFail($videoId);

        Log::warning('Song video deleted by admin', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'video_id' => $video->id,
            'song_id' => $this->song->id,
            'song_title' => (string) $this->song->title,
        ]));

        app(SongVideoService::class)->deleteVideo($video);
    }

    /**
     * @return Builder<ChurchServiceItem>
     */
    private function usageBaseQuery(): Builder
    {
        return ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->where('church_service_items.song_id', $this->song->id)
            ->where('church_service_items.type', 'songs')
            ->whereNull('church_service_items.deleted_at');
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
