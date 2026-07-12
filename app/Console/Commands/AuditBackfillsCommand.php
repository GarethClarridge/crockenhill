<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\BackfillAudit;
use Illuminate\Console\Command;

class AuditBackfillsCommand extends Command
{
    protected $signature = 'backfill:audit {--json : Emit the full audit report as JSON}';

    protected $description = 'Audit one-off backfill postconditions to detect commands that were never run in this environment';

    public function handle(BackfillAudit $audit): int
    {
        $report = array_replace($this->defaultReport(), $audit->report());
        $hasFailures = $audit->hasFailures($report);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $hasFailures ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Backfill check', 'Result', 'Remedy when failing'],
            [
                [
                    'Sermons with parseable reference but no scripture filters',
                    (string) $report['sermons_missing_scripture_filters'],
                    'sermons:sync-scripture-filters --only-missing',
                ],
                [
                    'Songs with a "#NNN" title but a missing or stale praise number',
                    (string) $report['songs_missing_praise_numbers'],
                    'songs:backfill-praise-numbers',
                ],
                [
                    'Preachers table empty despite sermons',
                    $report['preachers_table_empty'] ? 'yes' : 'no',
                    'preachers:cutover',
                ],
                [
                    'Song items the linker would relink or clear (drift)',
                    (string) $report['song_link_drift'],
                    'service-tracking:link-songs',
                ],
                [
                    'Media logs with parseable but unextracted identity metadata',
                    (string) $report['media_logs_missing_extracted_identity'],
                    'media-processing:backfill-extracted-identity',
                ],
                [
                    'Songs catalogue empty despite song service items',
                    $report['songs_catalogue_missing'] ? 'yes' : 'no',
                    'service-tracking:sync-songs',
                ],
                [
                    'Speaker identification enabled but no speaker profiles',
                    $report['speaker_profiles_missing'] ? 'yes' : 'no',
                    'speaker-profiles:bootstrap',
                ],
            ]
        );

        $this->newLine();
        $this->line('Advisory drift (does not fail the audit — a residue can be legitimate):');
        $this->table(
            ['Advisory check', 'Result', 'Related command'],
            [
                [
                    'Sermons without a linked preacher',
                    (string) $report['sermons_missing_preacher'],
                    'preachers:cutover',
                ],
                [
                    'Sermons with a reference but no cached scripture passage',
                    (string) $report['sermons_missing_scripture_passage'],
                    'sermons:enrich-scripture',
                ],
            ]
        );
        $this->comment('Judge advisory counts against the sermon total: a large number suggests the related command never ran; a small residue is expected (unparseable references, api.bible misses, sermons saved without a preacher).');

        if ($hasFailures) {
            $this->error('Backfill audit found missed runs. Execute the remedy command for each failing check.');

            return self::FAILURE;
        }

        $this->info('Backfill audit is clean.');

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     sermons_missing_scripture_filters: int,
     *     songs_missing_praise_numbers: int,
     *     preachers_table_empty: bool,
     *     song_link_drift: int,
     *     media_logs_missing_extracted_identity: int,
     *     songs_catalogue_missing: bool,
     *     speaker_profiles_missing: bool,
     *     sermons_missing_preacher: int,
     *     sermons_missing_scripture_passage: int
     * }
     */
    private function defaultReport(): array
    {
        return [
            'sermons_missing_scripture_filters' => 0,
            'songs_missing_praise_numbers' => 0,
            'preachers_table_empty' => false,
            'song_link_drift' => 0,
            'media_logs_missing_extracted_identity' => 0,
            'songs_catalogue_missing' => false,
            'speaker_profiles_missing' => false,
            'sermons_missing_preacher' => 0,
            'sermons_missing_scripture_passage' => 0,
        ];
    }
}
