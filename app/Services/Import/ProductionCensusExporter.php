<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\CodeRevision;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Identity evidence only: no models, actions, observers, queue, cache or staging context.
 * Database transaction controls use PDO; every data/metadata query is a builder SELECT
 * pinned to that same PDO (never a read replica). Media observations are not atomic with SQL.
 */
class ProductionCensusExporter
{
    public const TABLES = [
        'sermons', 'church_services', 'church_service_items', 'church_service_source_records',
        'church_service_item_assertions', 'church_service_merge_proposals',
        'church_service_review_sessions', 'church_service_review_decisions',
        'church_service_proposal_decision_rules', 'church_service_proposal_class_reviews',
        'service_sections', 'song_videos', 'speaker_profiles', 'speaker_samples',
        'preachers', 'preacher_aliases', 'songs', 'song_authors', 'song_books',
        'song_author_song', 'song_book_song', 'media_processing_logs',
    ];

    /** @return array<string, int> */
    public function export(string $path, bool $skipHashes): array
    {
        if (str_contains($path, '://') || ! is_dir(dirname($path))) {
            throw new RuntimeException('--output must name a local file in an existing private directory.');
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot exclusively create --output; it may already exist.');
        }
        if (! chmod($path, 0600)) {
            fclose($handle);
            throw new RuntimeException('Cannot restrict census permissions. Incomplete output retained.');
        }

        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $ownsSnapshot = false;
        try {
            $snapshot = $this->startSnapshot($connection, $pdo, $ownsSnapshot);
            $capturedAt = gmdate('Y-m-d\TH:i:s\Z');
            $schema = $this->schema($connection);
            $counts = [];
            $missing = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = isset($schema[$table]) ? $this->query($connection, $table)->count() : 0;
                if (! isset($schema[$table])) {
                    $missing[] = $table;
                }
            }
            if (! isset($schema['sermons'])) {
                throw new RuntimeException('Required sermons table is absent. Incomplete output retained.');
            }
            $migrations = isset($schema['migrations'])
                ? $this->query($connection, 'migrations')->orderBy('migration')->get()->all() : [];
            $digest = hash_init('sha256');
            $this->writeLine($handle, [
                'tag' => 'header', 'format' => 'crockenhill_identity_census', 'version' => 1,
                'captured_at' => $capturedAt, 'code_revision' => CodeRevision::compute(base_path()),
                'schema_migration_sha256' => hash('sha256', $this->json(['schema' => $schema, 'migrations' => $migrations])),
                'schema' => $schema, 'migrations' => $migrations, 'counts' => $counts, 'missing_tables' => $missing,
                'scope' => 'all_rows_including_soft_deleted', 'snapshot' => $snapshot,
                'driver' => $connection->getDriverName(), 'media_hashes' => ! $skipHashes,
                'media_consistency' => 'Observed individually after SQL snapshot; concurrent file changes are possible.',
            ], $digest);
            $written = array_fill_keys(self::TABLES, 0);
            foreach (self::TABLES as $table) {
                if (! isset($schema[$table])) {
                    continue;
                }
                $columns = array_keys($schema[$table]);
                $query = $this->query($connection, $table)->select($columns);
                foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                    $query->orderBy($column);
                }
                foreach ($query->cursor() as $record) {
                    $row = $this->identityRow($table, (array) $record, $skipHashes);
                    $this->writeLine($handle, ['tag' => 'row', 'table' => $table, 'row' => $row], $digest);
                    $written[$table]++;
                }
            }
            if ($counts !== $written) {
                throw new RuntimeException('Snapshot counts changed. Incomplete output retained.');
            }
            $this->writeLine($handle, ['tag' => 'footer', 'counts' => $written, 'rows' => array_sum($written),
                'sha256' => hash_final($digest)], null);
            if (! fflush($handle)) {
                throw new RuntimeException('Cannot flush census output.');
            }

            return $written;
        } finally {
            try {
                if ($ownsSnapshot) {
                    $pdo->exec('ROLLBACK');
                }
            } finally {
                fclose($handle);
            }
        }
    }

    private function startSnapshot(Connection $connection, PDO $pdo, bool &$ownsSnapshot): string
    {
        if ($pdo->inTransaction()) {
            if (app()->runningUnitTests() && $connection->transactionLevel() > 0) {
                return 'test_outer_transaction';
            }
            throw new RuntimeException('An existing transaction prevents a read-only census snapshot.');
        }
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('A read-only consistent census snapshot currently requires MySQL.');
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $ownsSnapshot = true;

        return 'mysql_read_only_consistent_snapshot';
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    protected function schema(Connection $connection): array
    {
        $rows = $this->query($connection, 'information_schema.columns')
            ->where('table_schema', $connection->getDatabaseName())
            ->whereIn('table_name', [...self::TABLES, 'migrations'])
            ->orderBy('table_name')->orderBy('ordinal_position')
            ->get(['TABLE_NAME as table_name', 'COLUMN_NAME as column_name', 'COLUMN_TYPE as column_type', 'IS_NULLABLE as is_nullable', 'COLUMN_DEFAULT as column_default']);
        $schema = [];
        foreach ($rows as $row) {
            $schema[$row->table_name][$row->column_name] = [
                'type' => $row->column_type, 'nullable' => $row->is_nullable, 'default' => $row->column_default,
            ];
        }
        $engines = $this->query($connection, 'information_schema.tables')
            ->where('table_schema', $connection->getDatabaseName())
            ->whereIn('table_name', [...self::TABLES, 'migrations'])->get(['TABLE_NAME as table_name', 'ENGINE as engine']);
        foreach ($engines as $table) {
            if (strtolower((string) $table->engine) !== 'innodb') {
                throw new RuntimeException('Consistent snapshot requires InnoDB: '.$table->table_name);
            }
        }

        return $schema;
    }

    private function query(Connection $connection, string $table): Builder
    {
        return $connection->table($table)->useWritePdo();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function identityRow(string $table, array $row, bool $skipHashes): array
    {
        foreach (['centroid_embedding', 'embedding', 'lyrics', 'lyrics_xml', 'lyrics_plain', 'service_content'] as $field) {
            if (array_key_exists($field, $row)) {
                $value = $row[$field];
                if (in_array($field, ['centroid_embedding', 'embedding'], true) && is_string($value)) {
                    $value = $this->json(json_decode($value, true, flags: JSON_THROW_ON_ERROR));
                }
                $row[$field.'_sha256'] = $value === null ? null : hash('sha256', (string) $value);
                unset($row[$field]);
            }
        }
        foreach (['metadata', 'import_metadata', 'thumbnail_metadata', 'processing_metadata'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = json_decode($row[$field], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        if ($table === 'media_processing_logs') {
            $row = array_intersect_key($row, array_flip([
                'id', 'processing_id', 'file_hash', 'status', 'processing_type', 'sermon_id', 'church_service_id',
                'extracted_date', 'extracted_service', 'superseded_at', 'superseded_by_processing_log_id',
                'created_at', 'updated_at', 'processing_metadata', 'original_filename', 'historic_import_operation_id',
            ]));
            $row['processing_metadata'] = array_intersect_key($row['processing_metadata'] ?? [], array_flip([
                'exclusion', 'sermon_adopted', 'retirement', 'identity_correction', 'manual_review',
            ]));
        }
        $assets = [];
        if (in_array($table, ['sermons', 'service_sections', 'song_videos'], true)) {
            $mediaDisk = $row['asset_disk'] ?? config('media-processing.storage.sermon_disk', 'local');
            foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path',
                'extracted_audio_path', 'extracted_video_path'] as $field) {
                if (! is_string($row[$field] ?? null) || $row[$field] === '') {
                    continue;
                }
                $fallback = match ($field) {
                    'transcript_file_path' => config('media-processing.storage.transcript_disk', 'local'),
                    'thumbnail_file_path' => config('thumbnail-generation.storage.disk', 'public'),
                    default => $mediaDisk,
                };
                $assets[$field] = $this->asset((string) (($row['asset_disk'] ?? null) ?: $fallback), $row[$field], $skipHashes);
            }
            if ($table === 'sermons' && is_array($row['thumbnail_metadata'] ?? null)) {
                $this->thumbnailAssets($row['thumbnail_metadata'], 'thumbnail_metadata',
                    (string) (($row['asset_disk'] ?? null) ?: config('thumbnail-generation.storage.disk', 'public')), $skipHashes, $assets);
            }
        }
        if ($assets !== []) {
            $row['_assets'] = $assets;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function observeSermon(array $row): array
    {
        return $this->identityRow('sermons', $row, false);
    }

    /**
     * @param  array<string|int, mixed>  $metadata
     * @param  array<string, mixed>  $assets
     */
    private function thumbnailAssets(array $metadata, string $prefix, string $disk, bool $skipHashes, array &$assets): void
    {
        foreach ($metadata as $key => $value) {
            $name = $prefix.'.'.$key;
            if (is_array($value)) {
                $this->thumbnailAssets($value, $name, $disk, $skipHashes, $assets);
            } elseif (is_string($value) && $value !== '' && str_ends_with((string) $key, '_path')) {
                $assets[$name] = $this->asset($disk, $value, $skipHashes);
            }
        }
    }

    /** @return array<string, mixed> */
    private function asset(string $disk, string $path, bool $skipHashes): array
    {
        $result = ['disk' => $disk, 'path' => $path, 'exists' => null, 'bytes' => null, 'status' => 'unreadable'];
        if (! $skipHashes) {
            $result['sha256'] = null;
        }
        $stream = null;
        try {
            if (config("filesystems.disks.{$disk}.driver") === 'local'
                && ! is_dir((string) config("filesystems.disks.{$disk}.root"))) {
                return $result;
            }
            $storage = Storage::disk($disk);
            $result['exists'] = $storage->exists($path);
            if (! $result['exists']) {
                $result['status'] = 'missing';

                return $result;
            }
            $result['bytes'] = $storage->size($path);
            if ($skipHashes) {
                $result['status'] = 'hash_skipped';

                return $result;
            }
            $stream = $storage->readStream($path);
            if (! is_resource($stream)) {
                return $result;
            }
            $context = hash_init('sha256');
            $bytes = @hash_update_stream($context, $stream);
            if ($bytes !== $result['bytes'] || ! feof($stream)) {
                return $result;
            }
            $result['sha256'] = hash_final($context);
            $result['status'] = 'hashed';
        } catch (Throwable) {
            $result['status'] = 'unreadable';
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $result;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $value
     */
    private function writeLine($handle, array $value, ?\HashContext $digest): void
    {
        $line = $this->json($value)."\n";
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($handle, substr($line, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write census. Incomplete output retained.');
            }
            $offset += $written;
        }
        if ($digest !== null) {
            hash_update($digest, $line);
        }
    }
}
