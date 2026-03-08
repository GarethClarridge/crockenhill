<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\ChurchServiceItemSyncService;
use App\Services\ChurchServiceSongLinker;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class ManageChurchService extends Component
{
    use WithAdminAuthorization;
    use WithNotifications;

    public ?ChurchService $churchService = null;

    public string $date = '';

    public string $service = '';

    /**
     * @var array<int, array{key:string,section_type:string,title:string,song_id:int|null}>
     */
    public array $items = [];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->abortIfDisabled();

        if ($this->churchService instanceof ChurchService && $this->churchService->exists) {
            /** @var ChurchService $churchService */
            $churchService = ChurchService::query()
                ->with([
                    'items' => fn ($query) => $query->orderBy('position')->orderBy('id'),
                ])
                ->findOrFail($this->churchService->getKey());
            $this->churchService = $churchService;

            $this->date = $this->churchService->date->format('Y-m-d');
            $this->service = $this->churchService->service->value;
            $this->items = $this->churchService->items
                ->map(fn (ChurchServiceItem $item): array => $this->itemPayloadFromModel($item))
                ->values()
                ->all();
        }

        if ($this->items === []) {
            $this->items = [$this->blankItem()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $serviceId = $this->churchService?->id;

        return [
            'date' => [
                'required',
                'date',
                Rule::unique('church_services', 'date')
                    ->ignore($serviceId)
                    ->where(fn ($query) => $query->where('service', $this->service)),
            ],
            'service' => ['required', Rule::in(SermonService::values())],
            'items' => ['required', 'array', 'min:1'],
            'items.*.section_type' => ['required', Rule::in(array_map(
                static fn (ServiceSectionType $type): string => $type->value,
                ServiceSectionType::cases()
            ))],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.song_id' => ['nullable', 'integer', 'exists:songs,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'date.unique' => 'A service for this date and service type already exists. Edit the existing service instead.',
            'items.min' => 'Add at least one service item.',
            'items.*.section_type.required' => 'Choose a type for each item.',
            'items.*.title.required' => 'Enter a title for each item.',
        ];
    }

    public function addItem(): void
    {
        $this->items[] = $this->blankItem();
    }

    public function removeItem(int $index): void
    {
        if (! array_key_exists($index, $this->items)) {
            return;
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if ($this->items === []) {
            $this->items[] = $this->blankItem();
        }
    }

    public function moveItemUp(int $index): void
    {
        if ($index < 1 || ! array_key_exists($index, $this->items)) {
            return;
        }

        [$this->items[$index - 1], $this->items[$index]] = [$this->items[$index], $this->items[$index - 1]];
    }

    public function moveItemDown(int $index): void
    {
        if (! array_key_exists($index, $this->items) || ! array_key_exists($index + 1, $this->items)) {
            return;
        }

        [$this->items[$index + 1], $this->items[$index]] = [$this->items[$index], $this->items[$index + 1]];
    }

    public function selectSong(int $index, int $songId): void
    {
        if (! array_key_exists($index, $this->items)) {
            return;
        }

        $song = Song::query()->find($songId);
        if (! $song instanceof Song) {
            return;
        }

        $this->items[$index]['section_type'] = ServiceSectionType::SONG->value;
        $this->items[$index]['title'] = $song->title;
        $this->items[$index]['song_id'] = $song->id;
    }

    public function updatedItems(mixed $value, string $key): void
    {
        $segments = explode('.', $key);
        $index = (int) $segments[0];
        $field = $segments[1] ?? null;

        if (! array_key_exists($index, $this->items) || ! is_string($field)) {
            return;
        }

        if ($field === 'section_type' && $this->items[$index]['section_type'] !== ServiceSectionType::SONG->value) {
            $this->items[$index]['song_id'] = null;
        }

        if ($field === 'title') {
            $this->items[$index]['song_id'] = null;
        }
    }

    public function save(
        ChurchServiceItemSyncService $itemSyncService,
        ChurchServiceSongLinker $songLinker,
    ): mixed {
        $this->authorizeAdmin();
        $this->abortIfDisabled();

        $validated = $this->validate();
        $payload = $this->buildSyncPayload();
        $wasCreated = ! ($this->churchService instanceof ChurchService && $this->churchService->exists);

        $churchService = DB::transaction(function () use ($validated, $payload, $itemSyncService, $songLinker): ChurchService {
            $churchService = $this->churchService ?? new ChurchService;
            $existingMetadata = is_array($churchService->import_metadata) ? $churchService->import_metadata : [];

            $churchService->fill([
                'date' => $validated['date'],
                'service' => $validated['service'],
                'source' => ChurchServiceItemSource::MANUAL->value,
                'needs_review' => false,
                'import_metadata' => array_replace_recursive($existingMetadata, [
                    'manual_edit' => [
                        'saved_at' => now()->toIso8601String(),
                        'saved_by_user_id' => Auth::id(),
                        'item_count' => count($payload),
                    ],
                ]),
            ]);
            $churchService->save();

            $itemSyncService->sync($churchService, $payload, ChurchServiceItemSource::MANUAL);
            $songLinker->linkForService($churchService);
            $churchService->touchForSectionReconciliation();

            return $churchService->fresh(['items']) ?? $churchService;
        });

        $this->churchService = $churchService;

        return $this->success(
            $wasCreated ? 'Service created' : 'Service updated',
            redirectTo: route('admin.services.show', $churchService)
        );
    }

    public function render(): View
    {
        return view('livewire.admin.church-services.manage-church-service', [
            'serviceOptions' => $this->serviceOptions(),
            'sectionTypeOptions' => $this->sectionTypeOptions(),
            'songSuggestions' => $this->songSuggestions(),
            'isEditing' => $this->churchService instanceof ChurchService,
        ])->layout('layouts.admin', [
            'title' => $this->churchService instanceof ChurchService ? 'Edit Service' : 'Create Service',
            'heading' => $this->churchService instanceof ChurchService ? 'Edit Service' : 'Create Service',
        ]);
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    private function serviceOptions(): array
    {
        return collect(SermonService::cases())
            ->map(fn (SermonService $service): array => [
                'id' => $service->value,
                'name' => $service->label(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    private function sectionTypeOptions(): array
    {
        return collect(ServiceSectionType::cases())
            ->map(fn (ServiceSectionType $type): array => [
                'id' => $type->value,
                'name' => $type->label(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<int, array{id:int,title:string}>>
     */
    private function songSuggestions(): array
    {
        $suggestions = [];

        foreach ($this->items as $index => $item) {
            $title = trim($item['title']);
            $escapedTitle = $this->escapeLikeValue($title);

            if ($item['section_type'] !== ServiceSectionType::SONG->value || mb_strlen($title) < 2) {
                $suggestions[$index] = [];

                continue;
            }

            $suggestions[$index] = Song::query()
                ->select(['id', 'title'])
                ->where(function ($query) use ($escapedTitle): void {
                    $query->where('title', 'like', "%{$escapedTitle}%")
                        ->orWhere('alternate_title', 'like', "%{$escapedTitle}%")
                        ->orWhere('canonical_key', 'like', "%{$escapedTitle}%");
                })
                ->orderBy('title')
                ->limit(5)
                ->get()
                ->map(fn (Song $song): array => [
                    'id' => $song->id,
                    'title' => $song->title,
                ])
                ->all();
        }

        return $suggestions;
    }

    /**
     * @return array<int, array{position:int,type:string,title:string,source_title:string,openlp_search_title:null,song_id:int|null,metadata:array<string,mixed>}>
     */
    private function buildSyncPayload(): array
    {
        $payload = [];
        $selectedSongCanonicalKeys = Song::query()
            ->whereIn('id', collect($this->items)->pluck('song_id')->filter()->all())
            ->pluck('canonical_key', 'id')
            ->mapWithKeys(static fn (string $canonicalKey, int|string $songId): array => [(int) $songId => $canonicalKey])
            ->all();

        foreach (array_values($this->items) as $index => $item) {
            $sectionType = ServiceSectionType::from((string) $item['section_type']);
            $storageType = match ($sectionType) {
                ServiceSectionType::SONG => 'songs',
                ServiceSectionType::BIBLE_READING => 'bibles',
                default => 'custom',
            };
            $songId = is_int($item['song_id']) ? $item['song_id'] : null;
            $metadata = [
                'section_type' => $sectionType->value,
            ];

            if ($songId !== null && array_key_exists($songId, $selectedSongCanonicalKeys)) {
                $metadata['linked_song_canonical_key'] = $selectedSongCanonicalKeys[$songId];
            }

            $payload[] = [
                'position' => $index + 1,
                'type' => $storageType,
                'title' => trim((string) $item['title']),
                'source_title' => trim((string) $item['title']),
                'openlp_search_title' => null,
                'song_id' => $songId,
                'metadata' => $metadata,
            ];
        }

        return $payload;
    }

    /**
     * @return array{key:string,section_type:string,title:string,song_id:int|null}
     */
    private function blankItem(): array
    {
        return [
            'key' => (string) Str::uuid(),
            'section_type' => ServiceSectionType::SONG->value,
            'title' => '',
            'song_id' => null,
        ];
    }

    /**
     * @return array{key:string,section_type:string,title:string,song_id:int|null}
     */
    private function itemPayloadFromModel(ChurchServiceItem $item): array
    {
        return [
            'key' => (string) Str::uuid(),
            'section_type' => $this->resolveSectionType($item)->value,
            'title' => $item->title,
            'song_id' => $item->song_id,
        ];
    }

    private function resolveSectionType(ChurchServiceItem $item): ServiceSectionType
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $metadataType = Arr::get($metadata, 'section_type');

        if (is_string($metadataType)) {
            $resolved = ServiceSectionType::tryFrom($metadataType);

            if ($resolved instanceof ServiceSectionType) {
                return $resolved;
            }
        }

        return match ($item->type) {
            'songs' => ServiceSectionType::SONG,
            'bibles' => ServiceSectionType::BIBLE_READING,
            default => $this->inferSectionTypeFromTitle($item->title),
        };
    }

    private function inferSectionTypeFromTitle(string $title): ServiceSectionType
    {
        $title = Str::lower($title);

        return match (true) {
            str_contains($title, 'children') => ServiceSectionType::CHILDRENS_TALK,
            str_contains($title, 'prayer') => ServiceSectionType::PRAYER,
            str_contains($title, 'notice'), str_contains($title, 'announcement') => ServiceSectionType::NOTICES,
            str_contains($title, 'welcome') => ServiceSectionType::WELCOME,
            str_contains($title, 'sermon'), str_contains($title, 'message') => ServiceSectionType::SERMON,
            default => ServiceSectionType::OTHER,
        };
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
