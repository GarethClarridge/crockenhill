<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LegacyPlayDateSongUsageImporter
{
    public const DEFAULT_DUMP_PATH = 'prod-20260326.sql';

    private const LEGACY_SERVICE_SOURCE = 'legacy_play_date';

    private const PLAY_DATE_INSERT_PREFIX = 'INSERT INTO `play_date` VALUES ';

    private const PLAY_DATE_ROW_PATTERN = "/\((\d+),'(\d+)','(\d{4}-\d{2}-\d{2})','([ap])',/";

    /**
     * @return array{
     *     path:string,
     *     dry_run:bool,
     *     rows_parsed:int,
     *     distinct_song_ids:int,
     *     services_seen:int,
     *     services_created:int,
     *     services_reused:int,
     *     items_imported:int,
     *     items_skipped_existing_row:int,
     *     items_skipped_existing_song:int
     * }
     */
    public function import(?string $path = null, bool $dryRun = false): array
    {
        $resolvedPath = $this->resolvePath($path);
        $records = $this->parsePlayDateRows($resolvedPath);
        $songIds = $this->distinctSongIds($records);

        $this->guardSongsExist($songIds);

        if ($dryRun) {
            DB::beginTransaction();

            try {
                $metrics = $this->persist($resolvedPath, $records, $songIds, true);
                DB::rollBack();

                return $metrics;
            } catch (Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }
        }

        /** @var array{
         *     path:string,
         *     dry_run:bool,
         *     rows_parsed:int,
         *     distinct_song_ids:int,
         *     services_seen:int,
         *     services_created:int,
         *     services_reused:int,
         *     items_imported:int,
         *     items_skipped_existing_row:int,
         *     items_skipped_existing_song:int
         * } $metrics
         */
        $metrics = DB::transaction(
            fn (): array => $this->persist($resolvedPath, $records, $songIds, false)
        );

        return $metrics;
    }

    private function resolvePath(?string $path): string
    {
        $candidate = $path;

        if (! is_string($candidate) || trim($candidate) === '') {
            $candidate = base_path(self::DEFAULT_DUMP_PATH);
        } elseif (! str_starts_with($candidate, DIRECTORY_SEPARATOR)) {
            $candidate = base_path($candidate);
        }

        if (! is_file($candidate) || ! is_readable($candidate)) {
            throw new RuntimeException('Legacy SQL dump path does not exist or is not readable: '.$candidate);
        }

        return $candidate;
    }

    /**
     * @return list<array{
     *     legacy_id:int,
     *     song_id:int,
     *     date:string,
     *     service:SermonService,
     *     slot:'a'|'p'
     * }>
     */
    private function parsePlayDateRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open legacy SQL dump: '.$path);
        }

        $records = [];

        while (($line = fgets($handle)) !== false) {
            if (! str_starts_with($line, self::PLAY_DATE_INSERT_PREFIX)) {
                continue;
            }

            preg_match_all(self::PLAY_DATE_ROW_PATTERN, $line, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $slot = $match[4];

                $records[] = [
                    'legacy_id' => (int) $match[1],
                    'song_id' => (int) $match[2],
                    'date' => $match[3],
                    'service' => $this->serviceFromLegacySlot($slot),
                    'slot' => $slot,
                ];
            }
        }

        fclose($handle);

        if ($records === []) {
            throw new RuntimeException('No legacy play_date rows were found in '.$path.'.');
        }

        return $records;
    }

    private function serviceFromLegacySlot(string $slot): SermonService
    {
        return match ($slot) {
            'a' => SermonService::Morning,
            'p' => SermonService::Evening,
            default => throw new RuntimeException('Unsupported legacy play_date slot: '.$slot),
        };
    }

    /**
     * @param  list<array{
     *     legacy_id:int,
     *     song_id:int,
     *     date:string,
     *     service:SermonService,
     *     slot:'a'|'p'
     * }>  $records
     * @return list<int>
     */
    private function distinctSongIds(array $records): array
    {
        $songIds = [];

        foreach ($records as $record) {
            $songIds[$record['song_id']] = true;
        }

        $ids = array_map('intval', array_keys($songIds));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $songIds
     */
    private function guardSongsExist(array $songIds): void
    {
        $existingSongIds = Song::query()
            ->whereIn('id', $songIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $existingLookup = array_fill_keys($existingSongIds, true);
        $missingSongIds = [];

        foreach ($songIds as $songId) {
            if (! isset($existingLookup[$songId])) {
                $missingSongIds[] = $songId;
            }
        }

        if ($missingSongIds === []) {
            return;
        }

        $sample = array_slice($missingSongIds, 0, 10);
        $suffix = count($missingSongIds) > count($sample) ? '…' : '';

        throw new RuntimeException(
            'Legacy play_date rows reference songs that do not exist in the local songs table: '
            .implode(', ', $sample)
            .$suffix
            .'. Sync songs before importing legacy usage.'
        );
    }

    /**
     * @param  list<array{
     *     legacy_id:int,
     *     song_id:int,
     *     date:string,
     *     service:SermonService,
     *     slot:'a'|'p'
     * }>  $records
     * @param  list<int>  $songIds
     * @return array{
     *     path:string,
     *     dry_run:bool,
     *     rows_parsed:int,
     *     distinct_song_ids:int,
     *     services_seen:int,
     *     services_created:int,
     *     services_reused:int,
     *     items_imported:int,
     *     items_skipped_existing_row:int,
     *     items_skipped_existing_song:int
     * }
     */
    private function persist(string $path, array $records, array $songIds, bool $dryRun): array
    {
        /** @var array<int, Song> $songs */
        $songs = Song::query()
            ->whereIn('id', $songIds)
            ->get(['id', 'title'])
            ->keyBy('id')
            ->all();

        /** @var array<string, list<array{
         *     legacy_id:int,
         *     song_id:int,
         *     date:string,
         *     service:SermonService,
         *     slot:'a'|'p'
         * }>> $groupedRecords */
        $groupedRecords = [];

        foreach ($records as $record) {
            $key = $record['date'].'|'.$record['service']->value;
            $groupedRecords[$key][] = $record;
        }

        ksort($groupedRecords);

        $metrics = [
            'path' => $path,
            'dry_run' => $dryRun,
            'rows_parsed' => count($records),
            'distinct_song_ids' => count($songIds),
            'services_seen' => count($groupedRecords),
            'services_created' => 0,
            'services_reused' => 0,
            'items_imported' => 0,
            'items_skipped_existing_row' => 0,
            'items_skipped_existing_song' => 0,
        ];

        foreach ($groupedRecords as $serviceRecords) {
            usort(
                $serviceRecords,
                static fn (array $left, array $right): int => $left['legacy_id'] <=> $right['legacy_id']
            );

            $firstRecord = $serviceRecords[0];
            $churchService = $this->findOrCreateService($firstRecord, $path, $metrics);

            $existingItems = $churchService->items()
                ->whereNull('deleted_at')
                ->get(['id', 'position', 'song_id', 'metadata']);

            $existingLegacyRowIds = [];
            $existingSongIds = [];
            $nextPosition = 0;

            foreach ($existingItems as $item) {
                $nextPosition = max($nextPosition, (int) $item->position);

                if (is_int($item->song_id)) {
                    $existingSongIds[$item->song_id] = true;
                }

                $legacyPlayDateId = $this->legacyPlayDateId($item->metadata);
                if ($legacyPlayDateId !== null) {
                    $existingLegacyRowIds[$legacyPlayDateId] = true;
                }
            }

            foreach ($serviceRecords as $record) {
                if (isset($existingLegacyRowIds[$record['legacy_id']])) {
                    $metrics['items_skipped_existing_row']++;

                    continue;
                }

                if (isset($existingSongIds[$record['song_id']])) {
                    $metrics['items_skipped_existing_song']++;

                    continue;
                }

                $nextPosition++;

                $song = $songs[$record['song_id']] ?? null;
                if (! $song instanceof Song) {
                    throw new RuntimeException('Unable to resolve song '.$record['song_id'].' during legacy import.');
                }

                ChurchServiceItem::query()->create([
                    'church_service_id' => $churchService->id,
                    'position' => $nextPosition,
                    'type' => 'songs',
                    'section_type' => ServiceSectionType::SONG->value,
                    'source' => ChurchServiceItemSource::Manual->value,
                    'title' => $song->title,
                    'source_title' => $song->title,
                    'openlp_search_title' => null,
                    'song_id' => $song->id,
                    'metadata' => [
                        'section_type' => ServiceSectionType::SONG->value,
                        'legacy_source' => 'play_date',
                        'legacy_play_date_id' => $record['legacy_id'],
                        'legacy_song_id' => $record['song_id'],
                        'legacy_date' => $record['date'],
                        'legacy_slot' => $record['slot'],
                    ],
                ]);

                $metrics['items_imported']++;
                $existingLegacyRowIds[$record['legacy_id']] = true;
                $existingSongIds[$record['song_id']] = true;
            }
        }

        return $metrics;
    }

    /**
     * @param  array{
     *     legacy_id:int,
     *     song_id:int,
     *     date:string,
     *     service:SermonService,
     *     slot:'a'|'p'
     * }  $record
     * @param  array{
     *     path:string,
     *     dry_run:bool,
     *     rows_parsed:int,
     *     distinct_song_ids:int,
     *     services_seen:int,
     *     services_created:int,
     *     services_reused:int,
     *     items_imported:int,
     *     items_skipped_existing_row:int,
     *     items_skipped_existing_song:int
     * }  $metrics
     */
    private function findOrCreateService(array $record, string $path, array &$metrics): ChurchService
    {
        $churchService = ChurchService::query()
            ->where('date', $record['date'])
            ->where('service', $record['service']->value)
            ->first();

        if ($churchService instanceof ChurchService) {
            $metrics['services_reused']++;

            return $churchService;
        }

        $churchService = ChurchService::query()->create([
            'date' => $record['date'],
            'service' => $record['service']->value,
            'source' => self::LEGACY_SERVICE_SOURCE,
            'original_filename' => basename($path),
            'needs_review' => false,
            'review_state' => ChurchServiceReviewState::REVIEWED->value,
            'manual_reviewed_at' => now(),
            'manual_reviewed_by_user_id' => null,
            'manual_review_reopened_at' => null,
            'manual_review_reopened_by_source' => null,
            'import_metadata' => [
                'confidence_score' => 1.0,
                'parse_method' => 'legacy_play_date',
                'legacy_play_date' => [
                    'source_path' => basename($path),
                ],
            ],
        ]);

        $metrics['services_created']++;

        return $churchService;
    }

    private function legacyPlayDateId(mixed $metadata): ?int
    {
        if (! is_array($metadata)) {
            return null;
        }

        $legacyPlayDateId = $metadata['legacy_play_date_id'] ?? null;

        return is_numeric($legacyPlayDateId) ? (int) $legacyPlayDateId : null;
    }
}
