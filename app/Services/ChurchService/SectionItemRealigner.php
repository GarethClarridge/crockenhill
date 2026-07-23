<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\SongTitleNormalizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class SectionItemRealigner
{
    /**
     * @return list<array{section: ServiceSection, from: ChurchServiceItem|null, to: ChurchServiceItem|null, from_item_id: int|null, to_item_id: int|null, match: string}>
     */
    public function preview(MediaProcessingLog $processingLog): array
    {
        if ($processingLog->church_service_id === null) {
            return [];
        }

        /** @var EloquentCollection<int, ChurchServiceItem> $items */
        $items = ChurchServiceItem::query()
            ->with('song')
            ->where('church_service_id', $processingLog->church_service_id)
            ->where('type', 'songs')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        /** @var EloquentCollection<int, ServiceSection> $sections */
        $sections = $processingLog->serviceSections()
            ->with('churchServiceItem')
            ->where('section_type', ServiceSectionType::Song->value)
            ->whereIn('song_match_type', [
                ServiceSectionSongMatchType::Confirmed->value,
                ServiceSectionSongMatchType::Inferred->value,
            ])
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        $targets = [];
        $matchTypes = [];
        $consumed = [];

        foreach ($sections as $section) {
            $songId = $section->metadata?->songId;
            if ($songId === null) {
                continue;
            }

            $item = $items->first(
                fn (ChurchServiceItem $item): bool => ! isset($consumed[$item->id]) && $item->song_id === $songId
            );

            if ($item instanceof ChurchServiceItem) {
                $targets[$section->id] = $item;
                $matchTypes[$section->id] = 'song_id';
                $consumed[$item->id] = true;
            }
        }

        foreach ($sections as $section) {
            if (isset($targets[$section->id])) {
                continue;
            }

            $title = SongTitleNormalizer::normalize($section->title);
            if ($title === '') {
                continue;
            }

            $item = $items->first(function (ChurchServiceItem $item) use ($consumed, $title): bool {
                if (isset($consumed[$item->id])) {
                    return false;
                }

                return SongTitleNormalizer::normalize($item->title) === $title
                    || SongTitleNormalizer::normalize($item->song?->title) === $title;
            });

            if ($item instanceof ChurchServiceItem) {
                $targets[$section->id] = $item;
                $matchTypes[$section->id] = 'normalized_title';
                $consumed[$item->id] = true;
            }
        }

        $changes = [];

        foreach ($sections as $section) {
            $target = $targets[$section->id] ?? null;

            if ($section->church_service_item_id === $target?->id) {
                continue;
            }

            $changes[] = [
                'section' => $section,
                'from' => $section->churchServiceItem,
                'to' => $target,
                'from_item_id' => $section->church_service_item_id,
                'to_item_id' => $target?->id,
                'match' => $matchTypes[$section->id] ?? 'unmatched',
            ];
        }

        return $changes;
    }

    /**
     * @param  list<array{section: ServiceSection, from: ChurchServiceItem|null, to: ChurchServiceItem|null, from_item_id: int|null, to_item_id: int|null, match: string}>  $changes
     */
    public function apply(array $changes): void
    {
        DB::transaction(function () use ($changes): void {
            foreach ($changes as $change) {
                ServiceSection::query()
                    ->whereKey($change['section']->id)
                    ->lockForUpdate()
                    ->update(['church_service_item_id' => $change['to_item_id']]);
            }
        });
    }
}
