<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Component;

class ShowSong extends Component
{
    use WithAdminAuthorization;

    public Song $song;

    public function mount(Song $song): void
    {
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
        $usageCount = $this->usageBaseQuery()->count();
        $serviceCount = $this->usageBaseQuery()->distinct('church_service_items.church_service_id')->count('church_service_items.church_service_id');
        $lastUsedDate = $this->usageBaseQuery()->max('church_services.date');
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

    /**
     * @return Collection<int, ChurchServiceItem>
     */
    public function usageItems(): Collection
    {
        return $this->usageBaseQuery()->get();
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
