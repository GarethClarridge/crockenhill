<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;

class ShowSong extends Component
{
    public Song $song;

    public function mount(Song $song): void
    {
        $this->abortIfDisabled();

        $this->song = $song->load([
            'authors' => fn ($query) => $query->orderBy('display_name'),
            'books' => fn ($query) => $query->orderBy('name')->orderBy('song_book_song.entry'),
        ]);
    }

    public function render(): View
    {
        $importMetadata = is_array($this->song->import_metadata ?? null) ? $this->song->import_metadata : [];
        $parseWarnings = is_array($importMetadata['lyrics_parse_warnings'] ?? null) ? $importMetadata['lyrics_parse_warnings'] : [];
        /** @var array<string, mixed> $stats */
        $stats = (array) ($this->usageBaseQuery()
            ->selectRaw('COUNT(*) AS usage_count')
            ->selectRaw('COUNT(DISTINCT church_service_items.church_service_id) AS services_count')
            ->selectRaw('MAX(church_services.date) AS last_used_date')
            ->toBase()
            ->first() ?? []);

        $usageCount = is_numeric($stats['usage_count'] ?? null) ? (int) $stats['usage_count'] : 0;
        $serviceCount = is_numeric($stats['services_count'] ?? null) ? (int) $stats['services_count'] : 0;
        $lastUsedDate = is_string($stats['last_used_date'] ?? null) ? $stats['last_used_date'] : null;
        $usageHistory = $this->usageBaseQuery()
            ->select('church_service_items.*')
            ->with([
                'churchService' => fn ($query) => $query->select(['id', 'date', 'service']),
            ])
            ->orderByDesc('church_services.date')
            ->orderByDesc('church_service_items.position')
            ->limit(40)
            ->get();

        return view('livewire.admin.church-services.show-song', [
            'importMetadata' => $importMetadata,
            'parseWarnings' => $parseWarnings,
            'usageCount' => $usageCount,
            'serviceCount' => $serviceCount,
            'lastUsedDate' => $lastUsedDate,
            'usageHistory' => $usageHistory,
        ])->layout('layouts.admin', [
            'title' => 'Song: '.$this->song->title,
            'heading' => 'Song: '.$this->song->title,
        ]);
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
