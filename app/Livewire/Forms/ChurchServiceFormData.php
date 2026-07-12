<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ChurchServiceFormData extends Form
{
    use EscapesLikeWildcards;

    public ?ChurchService $churchService = null;

    public string $date = '';

    public string $service = '';

    /**
     * @var array<int, array{key:string,section_type:string,title:string,song_id:int|null}>
     */
    public array $items = [];

    public function setChurchService(ChurchService $churchService): void
    {
        $churchService->load([
            'items' => fn ($query) => $query->orderBy('position')->orderBy('id'),
        ]);

        $this->churchService = $churchService;

        $this->fill([
            'date' => $churchService->date->format('Y-m-d'),
            'service' => $churchService->service->value,
            'items' => $churchService->items
                ->map(fn (ChurchServiceItem $item): array => $this->itemPayloadFromModel($item))
                ->values()
                ->all(),
        ]);

        $this->ensureHasItems();
    }

    /**
     * @param  array{date?:string,service?:string,items?:array<int,array{key:string,section_type:string,title:string,song_id:int|null}>}  $prefillData
     */
    public function applyPrefillData(array $prefillData): void
    {
        if (isset($prefillData['date'])) {
            $this->date = $prefillData['date'];
        }

        if (isset($prefillData['service'])) {
            $this->service = $prefillData['service'];
        }

        if (isset($prefillData['items']) && $prefillData['items'] !== []) {
            $this->items = $prefillData['items'];
        }

        $this->ensureHasItems();
    }

    public function ensureHasItems(): void
    {
        if ($this->items !== []) {
            return;
        }

        $this->items = [$this->blankItem()];
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

        $this->ensureHasItems();
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

        $this->items[$index]['section_type'] = ServiceSectionType::Song->value;
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

        if ($field === 'section_type' && $this->items[$index]['section_type'] !== ServiceSectionType::Song->value) {
            $this->items[$index]['song_id'] = null;
        }

        if ($field === 'title') {
            $this->items[$index]['song_id'] = null;
        }
    }

    /**
     * @return array{date:string,service:string}
     */
    public function servicePayload(): array
    {
        return [
            'date' => $this->date,
            'service' => $this->service,
        ];
    }

    /**
     * @return array<int, array{position:int,type:string,section_type:string,title:string,source_title:string,openlp_search_title:null,song_id:int|null,metadata:array<string,mixed>|null}>
     */
    public function syncPayload(): array
    {
        $payload = [];
        $selectedSongCanonicalKeys = Song::query()
            ->whereIn('id', collect($this->items)
                ->pluck('song_id')
                ->filter(fn (mixed $songId): bool => is_numeric($songId))
                ->map(fn (mixed $songId): int => (int) $songId)
                ->all())
            ->pluck('canonical_key', 'id')
            ->mapWithKeys(static fn (string $canonicalKey, int|string $songId): array => [(int) $songId => $canonicalKey])
            ->all();

        foreach (array_values($this->items) as $index => $item) {
            $sectionType = ServiceSectionType::from((string) $item['section_type']);
            $storageType = match ($sectionType) {
                ServiceSectionType::Song => 'songs',
                ServiceSectionType::BibleReading => 'bibles',
                default => 'custom',
            };
            $songId = is_numeric($item['song_id'] ?? null) ? (int) $item['song_id'] : null;
            $metadata = [];

            if ($songId !== null && array_key_exists($songId, $selectedSongCanonicalKeys)) {
                $metadata['linked_song_canonical_key'] = $selectedSongCanonicalKeys[$songId];
            }

            $payload[] = [
                'position' => $index + 1,
                'type' => $storageType,
                'section_type' => $sectionType->value,
                'title' => trim((string) $item['title']),
                'source_title' => trim((string) $item['title']),
                'openlp_search_title' => null,
                'song_id' => $songId,
                'metadata' => $metadata !== [] ? $metadata : null,
            ];
        }

        return $payload;
    }

    /**
     * @return array<int, array<int, array{id:int,title:string}>>
     */
    public function songSuggestions(): array
    {
        $suggestions = [];

        foreach ($this->items as $index => $item) {
            $title = trim($item['title']);
            $escapedTitle = $this->escapeLike($title);

            if ($item['section_type'] !== ServiceSectionType::Song->value || mb_strlen($title) < 2) {
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
            'items.*.song_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807', 'exists:songs,id'],
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

    /**
     * @return array<int, array{id:string,name:string}>
     */
    public function serviceOptions(): array
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
    public function sectionTypeOptions(): array
    {
        return collect(ServiceSectionType::cases())
            ->map(fn (ServiceSectionType $type): array => [
                'id' => $type->value,
                'name' => $type->label(),
            ])
            ->all();
    }

    /**
     * @return array{key:string,section_type:string,title:string,song_id:int|null}
     */
    private function blankItem(): array
    {
        return [
            'key' => (string) Str::uuid(),
            'section_type' => ServiceSectionType::Song->value,
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
            'section_type' => $item->semanticSectionType()->value,
            'title' => $item->title,
            'song_id' => $item->song_id,
        ];
    }
}
