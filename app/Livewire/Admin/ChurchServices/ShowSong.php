<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\Song\SongUsageQuery;
use App\Services\Song\SongVideoService;
use App\Traits\SanitizesLogData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

class ShowSong extends Component
{
    use SanitizesLogData, WithAdminAuthorization;

    public Song $song;

    public function mount(Song $song): void
    {
        $this->song = $song->load([
            'authors' => fn ($query) => $query->orderBy('display_name'),
            'books' => fn ($query) => $query->orderBy('name')->orderBy('song_book_song.entry'),
        ]);
    }

    public function render(SongVideoService $songVideoService, SongUsageQuery $songUsageQuery): View
    {
        $importMetadata = is_array($this->song->import_metadata ?? null) ? $this->song->import_metadata : [];
        $parseWarnings = is_array($importMetadata['lyrics_parse_warnings'] ?? null) ? $importMetadata['lyrics_parse_warnings'] : [];
        /** @var array<string, mixed> $stats */
        $stats = (array) ($this->usageBaseQuery($songUsageQuery)
            ->selectRaw('COUNT(*) AS usage_count')
            ->selectRaw('MAX(used_on) AS last_used_date')
            ->first() ?? []);

        $usageCount = is_numeric($stats['usage_count'] ?? null) ? (int) $stats['usage_count'] : 0;
        $lastUsedDate = is_string($stats['last_used_date'] ?? null) ? $stats['last_used_date'] : null;
        $usageByYear = $this->usageBaseQuery($songUsageQuery)
            ->selectRaw('YEAR(used_on) AS year, COUNT(*) AS count')
            ->groupByRaw('YEAR(used_on)')
            ->orderByRaw('YEAR(used_on)')
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
     * @throws ModelNotFoundException
     */
    public function featureVideo(int $videoId): void
    {
        $this->authorizeAdmin();

        $video = SongVideo::query()->where('song_id', $this->song->id)->findOrFail($videoId);
        app(SongVideoService::class)->featureVideo($video);
    }

    /**
     * @throws ModelNotFoundException
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
     * @throws ModelNotFoundException
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

    private function usageBaseQuery(SongUsageQuery $songUsageQuery): Builder
    {
        return $songUsageQuery->occurrences(publicOnly: false)
            ->where('song_id', $this->song->id);
    }
}
