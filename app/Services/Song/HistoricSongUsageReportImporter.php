<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Models\SongUsageReport;
use Illuminate\Support\Facades\DB;

class HistoricSongUsageReportImporter
{
    public function __construct(
        private HistoricSongUsageWorkbookReader $reader,
    ) {}

    /**
     * @return array{rows_read:int,resolved:int,unresolved:int,created:int,existing:int,dry_run:bool}
     */
    public function import(string $path, bool $persist): array
    {
        $records = $this->reader->read($path);
        $resolver = SongTitleResolver::fromDatabase();
        $resolved = 0;
        $unresolved = 0;
        $created = 0;
        $existing = 0;

        $operation = function () use ($records, $resolver, $persist, &$resolved, &$unresolved, &$created, &$existing): void {
            foreach ($records as $record) {
                $match = $record['catalog_title'] === null
                    ? null
                    : $resolver->resolve($record['catalog_title']);
                $match === null ? $unresolved++ : $resolved++;

                $fingerprint = hash('sha256', implode('|', [
                    $record['source_workbook'],
                    $record['source_sheet'],
                    (string) $record['source_row'],
                    $record['used_on'],
                    $record['reported_title'],
                ]));

                if (! $persist) {
                    continue;
                }

                $report = SongUsageReport::query()->firstOrCreate(
                    ['source_fingerprint' => $fingerprint],
                    [
                        'song_id' => $match?->songId,
                        'used_on' => $record['used_on'],
                        'reported_service' => null,
                        'reported_title' => $record['reported_title'],
                        'reported_number' => $record['reported_number'],
                        'catalog_title' => $record['catalog_title'],
                        'match_method' => $match?->matchType,
                        'source_workbook' => $record['source_workbook'],
                        'source_sheet' => $record['source_sheet'],
                        'source_row' => $record['source_row'],
                        'metadata' => [
                            'workbook_match_method' => $record['workbook_match_method'],
                        ],
                    ],
                );

                $report->wasRecentlyCreated ? $created++ : $existing++;
            }
        };

        $persist ? DB::transaction($operation) : $operation();

        return [
            'rows_read' => count($records),
            'resolved' => $resolved,
            'unresolved' => $unresolved,
            'created' => $created,
            'existing' => $existing,
            'dry_run' => ! $persist,
        ];
    }
}
