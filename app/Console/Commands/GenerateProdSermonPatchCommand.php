<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateProdSermonPatchCommand extends Command
{
    protected $signature = 'sermons:generate-prod-patch
                            {--dump= : Path to the production SQL dump file}
                            {--output= : Path to write the patch SQL (defaults to storage/app/sermon-patch.sql)}
                            {--dry-run : Print a summary without writing the file}';

    protected $description = 'Generate a SQL patch to merge local legacy sermons into the production database';

    /** @var list<string> */
    private array $updateableFields = ['duration', 'reference', 'series', 'preacher', 'preacher_source', 'preacher_confidence'];

    public function handle(): int
    {
        $dumpPath = $this->resolveDumpPath();

        if ($dumpPath === null) {
            $this->error('Please provide a dump path with --dump= or place the dump at storage/app/backup.sql');

            return self::FAILURE;
        }

        $this->line("Reading prod dump: {$dumpPath}");
        $prodSermons = $this->parseProdDump($dumpPath);

        if (empty($prodSermons)) {
            $this->error('No sermons found in the dump file.');

            return self::FAILURE;
        }

        $this->line('Prod sermons loaded: '.count($prodSermons));

        // Build a lookup keyed by date+service for quick matching
        $prodIndex = [];
        foreach ($prodSermons as $row) {
            $key = $row['date'].'|'.$row['service'];
            $prodIndex[$key] = $row;
        }

        $localSermons = DB::table('sermons')->orderBy('date')->orderBy('service')->get();
        $this->line('Local sermons: '.$localSermons->count());

        $updates = [];
        $inserts = [];
        $skipped = 0;

        foreach ($localSermons as $local) {
            $key = $local->date.'|'.$local->service;

            if (isset($prodIndex[$key])) {
                // Overlap: only update prod fields that are NULL/empty with local values
                $prodRow = $prodIndex[$key];
                $setClauses = $this->buildUpdateClauses($local, $prodRow);

                if (! empty($setClauses)) {
                    $updates[] = $this->buildUpdateStatement((int) $prodRow['id'], $setClauses);
                } else {
                    $skipped++;
                }
            } else {
                // No prod counterpart — insert as new row, dropping preacher_id
                $inserts[] = $this->buildInsertStatement($local);
            }
        }

        $this->table(
            ['Action', 'Count'],
            [
                ['UPDATE (fill blanks)', count($updates)],
                ['INSERT (new legacy)', count($inserts)],
                ['Skipped (no diff)', $skipped],
            ],
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no file written.');

            return self::SUCCESS;
        }

        $outputPath = $this->option('output') ?? storage_path('app/sermon-patch.sql');
        $this->writePatch($outputPath, $updates, $inserts);

        $this->info("Patch written to: {$outputPath}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string|null>  $prodRow
     * @return array<string, string|null>
     */
    private function buildUpdateClauses(object $local, array $prodRow): array
    {
        $clauses = [];

        foreach ($this->updateableFields as $field) {
            $prodValue = $prodRow[$field] ?? null;
            $localValue = $local->$field ?? null;

            // Only fill in where prod has a blank/null
            if (($prodValue === null || $prodValue === '') && $localValue !== null && $localValue !== '') {
                $clauses[$field] = $localValue;
            }
        }

        // audio_file_path: prod has tape IDs (e.g. "538a"), local has real paths — never overwrite
        // slug: prod slugs are real, local slugs may differ — never overwrite

        return $clauses;
    }

    /**
     * @param  array<string, string|null>  $setClauses
     */
    private function buildUpdateStatement(int $prodId, array $setClauses): string
    {
        $parts = [];

        foreach ($setClauses as $field => $value) {
            $parts[] = "`{$field}` = ".$this->sqlValue($value);
        }

        $set = implode(', ', $parts);

        return "UPDATE `sermons` SET {$set} WHERE `id` = {$prodId};";
    }

    private function buildInsertStatement(object $local): string
    {
        $fields = [
            'date', 'service', 'content_type', 'audio_file_path',
            'source_type', 'duration', 'filetype', 'title', 'slug',
            'reference', 'preacher', 'preacher_source', 'preacher_confidence',
            'needs_preacher_review', 'series', 'created_at', 'updated_at',
        ];

        $columns = implode(', ', array_map(fn (string $f) => "`{$f}`", $fields));
        $values = implode(', ', array_map(fn (string $f) => $this->sqlValue($local->$f ?? null), $fields));

        return "INSERT INTO `sermons` ({$columns}) VALUES ({$values});";
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);

        return "'{$escaped}'";
    }

    /**
     * @param  list<string>  $updates
     * @param  list<string>  $inserts
     */
    private function writePatch(string $outputPath, array $updates, array $inserts): void
    {
        $lines = [];
        $lines[] = '-- Sermon patch generated '.now()->toDateTimeString();
        $lines[] = '-- UPDATEs: fill blank fields on existing prod records';
        $lines[] = '-- INSERTs: new legacy sermons with no prod counterpart';
        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $lines[] = '';

        if (! empty($updates)) {
            $lines[] = '-- ── UPDATEs ──────────────────────────────────────────';
            foreach ($updates as $sql) {
                $lines[] = $sql;
            }
            $lines[] = '';
        }

        if (! empty($inserts)) {
            $lines[] = '-- ── INSERTs ──────────────────────────────────────────';
            foreach ($inserts as $sql) {
                $lines[] = $sql;
            }
            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        file_put_contents($outputPath, implode("\n", $lines));
    }

    /**
     * Parse sermons from a MySQL dump file.
     *
     * @return list<array<string, string|null>>
     */
    private function parseProdDump(string $path): array
    {
        $sql = file_get_contents($path);

        if ($sql === false) {
            return [];
        }

        $match = preg_match('/INSERT INTO `sermons` VALUES (.*?);/s', $sql, $matches);

        if (! $match) {
            return [];
        }

        $cols = [
            'id', 'livestream_processing_id', 'date', 'service', 'content_type',
            'audio_file_path', 'video_file_path', 'video_quality_status', 'video_quality_reason',
            'video_visibility_override', 'video_quality_assessed_at', 'source_type',
            'segment_start_time', 'segment_end_time', 'duration', 'filetype', 'title',
            'slug', 'reference', 'scripture_passage_id', 'download_count', 'preacher',
            'preacher_id', 'preacher_source', 'preacher_confidence', 'needs_preacher_review',
            'series', 'created_at', 'updated_at', 'points', 'show_points',
            'transcript_file_path', 'thumbnail_file_path', 'thumbnail_generated_at',
            'thumbnail_metadata', 'summary', 'meta_description', 'show_summary',
        ];

        $rowsStr = $matches[1];
        $rawRows = $this->extractRows($rowsStr);
        $sermons = [];

        foreach ($rawRows as $raw) {
            $fields = str_getcsv($raw);

            if (count($fields) < count($cols)) {
                continue;
            }

            $row = [];

            foreach ($cols as $i => $col) {
                $v = trim($fields[$i] ?? '');
                $row[$col] = ($v === 'NULL') ? null : trim($v, "'");
            }

            $sermons[] = $row;
        }

        return $sermons;
    }

    /** @return list<string> */
    private function extractRows(string $rowsStr): array
    {
        $rows = [];
        $depth = 0;
        $start = null;

        for ($i = 0; $i < strlen($rowsStr); $i++) {
            $char = $rowsStr[$i];

            if ($char === '(' && $depth === 0) {
                $depth = 1;
                $start = $i + 1;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0 && $start !== null) {
                    $rows[] = substr($rowsStr, $start, $i - $start);
                    $start = null;
                }
            }
        }

        return $rows;
    }

    private function resolveDumpPath(): ?string
    {
        $opt = $this->option('dump');

        if (is_string($opt) && trim($opt) !== '') {
            return $opt;
        }

        $default = storage_path('app/backup.sql');

        return file_exists($default) ? $default : null;
    }
}
