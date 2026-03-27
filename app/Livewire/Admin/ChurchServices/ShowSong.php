<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;

class ShowSong extends Component
{
    use WithAdminAuthorization;

    public Song $song;

    public function mount(Song $song): void
    {
        // Defense-in-depth: enforce admin authorization internally
        $this->authorizeAdmin();
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

        return view('livewire.admin.church-services.show-song', [
            'importMetadata' => $importMetadata,
            'parseWarnings' => $parseWarnings,
            'usageCount' => $usageCount,
            'lastUsedDate' => $lastUsedDate,
            'usageByYear' => $usageByYear,
        ])->layout('layouts.admin', [
            'title' => $this->song->title,
            'heading' => $this->song->title,
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
