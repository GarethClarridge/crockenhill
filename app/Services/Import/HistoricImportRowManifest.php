<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\CanonicalJson;
use Illuminate\Database\DatabaseManager;
use JsonException;
use RuntimeException;

/**
 * The one read-only implementation both disposable restores are read through.
 *
 * HIR5 item 6. Version 1 recovery evidence carried a
 * `table_row_manifest_sha256` per restore and checked that the two strings were
 * equal — which two copies of the same typed placeholder satisfy, and which says
 * nothing about what either restore contains. A manifest produced here is read
 * from the restored database itself, and the recovery gate compares the two
 * manifests' *membership*: the exact table set, each table's row count and each
 * table's column shape.
 *
 * Every query is a read. There is no write path in this class, deliberately: it
 * runs against a restore whose whole purpose is to be discarded, and an
 * implementation that could write to it could write to the connection it was
 * pointed at by mistake.
 *
 * Row *content* digests are not computed, and that is a decision rather than an
 * omission. Hashing every row of a full restore costs a table scan per table,
 * twice per rehearsal, while the bytes of the backup the restore came from are
 * already bound by the artifact digest the recovery gate recomputes. Membership
 * is what a restore comparison can establish cheaply and exactly: that both
 * copies came back with the same tables holding the same number of rows under
 * the same schema.
 *
 * Delete alongside the recovery verifier once the acceptance and rollback
 * retention windows have closed (G9/WP10).
 */
final class HistoricImportRowManifest
{
    public const string Format = 'crockenhill-historic-import-row-manifest';

    public const int Version = 1;

    public function __construct(
        private readonly DatabaseManager $connections,
        private readonly HistoricImportResourceIdentity $resources,
    ) {}

    /**
     * Read one restored database's table/row membership.
     *
     * @param  string|null  $connection  a named connection, or null for the default one
     * @return array<string, mixed>
     */
    public function forConnection(?string $connection = null): array
    {
        $resolved = $this->connections->connection($connection);
        $schema = $resolved->getSchemaBuilder();
        /**
         * Scoped to this connection's own database, explicitly. Called without
         * a schema, `getTableListing()` enumerates every schema the connection's
         * user can reach — on this host, 825 tables across every database on the
         * server rather than the 62 in the one being manifested — and the
         * unqualified `COUNT(*)` that followed would then be read against the
         * wrong database or fail outright.
         */
        $tables = $schema->getTableListing($resolved->getDatabaseName(), schemaQualified: false);
        sort($tables, SORT_STRING);
        $membership = [];

        foreach ($tables as $table) {
            $columns = array_map(static fn (array $column): array => [
                'name' => $column['name'],
                'type' => $column['type'],
                'nullable' => $column['nullable'],
            ], $schema->getColumns($table));
            usort($columns, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

            $membership[$table] = [
                'row_count' => $resolved->table($table)->count(),
                'columns_sha256' => CanonicalJson::hash($columns),
            ];
        }

        return [
            'format' => self::Format,
            'version' => self::Version,
            'connection_anchor' => $this->resources->databaseAnchor($connection),
            'generated_at' => now()->utc()->toIso8601String(),
            'table_count' => count($membership),
            'tables' => $membership,
            'membership_sha256' => CanonicalJson::hash($membership),
        ];
    }

    /**
     * Parse a manifest artifact and prove it is internally consistent.
     *
     * The manifest carries no signature of its own. Its bytes are named by the
     * recovery evidence's declared digest, which the resolver recomputes, and
     * that evidence is signed — so a manifest cannot be swapped without breaking
     * a digest the gate reproduces. What it can be is *edited before* the
     * evidence was signed, so `membership_sha256` is recomputed here: a hand-set
     * row count no longer matches the digest the producer wrote.
     *
     * @return array<string, mixed>
     */
    public function parse(string $contents, string $label): array
    {
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$label} row manifest is not valid JSON.", previous: $exception);
        }

        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== self::Format
            || ($manifest['version'] ?? null) !== self::Version) {
            throw new RuntimeException("The {$label} row manifest was not produced by this implementation.");
        }

        $tables = $manifest['tables'] ?? null;
        $anchor = $manifest['connection_anchor'] ?? null;

        if (! is_array($tables) || $tables === []
            || ! is_string($anchor)
            || preg_match('/\A[a-f0-9]{64}\z/', $anchor) !== 1) {
            throw new RuntimeException("The {$label} row manifest has no table membership or connection identity.");
        }

        foreach ($tables as $table => $entry) {
            if (! is_string($table)
                || ! is_array($entry)
                || ! is_int($entry['row_count'] ?? null)
                || $entry['row_count'] < 0
                || ! is_string($entry['columns_sha256'] ?? null)) {
                throw new RuntimeException("The {$label} row manifest entry for {$table} is incomplete.");
            }
        }

        if (($manifest['table_count'] ?? null) !== count($tables)
            || ! is_string($manifest['membership_sha256'] ?? null)
            || ! hash_equals(CanonicalJson::hash($tables), $manifest['membership_sha256'])) {
            throw new RuntimeException("The {$label} row manifest does not match its own membership digest.");
        }

        return $manifest;
    }

    /**
     * Exact membership, table by table.
     *
     * The comparison is deliberately verbose about *which* table disagrees: a
     * restore that came back short is an incident, and "the digests differ" is
     * not a starting point for one.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    public function assertEqualMembership(array $left, array $right): void
    {
        /** @var array<string, mixed> $leftTables */
        $leftTables = $left['tables'];
        /** @var array<string, mixed> $rightTables */
        $rightTables = $right['tables'];
        $onlyOnHost = array_keys(array_diff_key($leftTables, $rightTables));
        $onlyOffHost = array_keys(array_diff_key($rightTables, $leftTables));

        if ($onlyOnHost !== [] || $onlyOffHost !== []) {
            throw new RuntimeException(
                'The on-host and off-host restores hold different tables. '
                .'On-host only: '.($onlyOnHost === [] ? 'none' : implode(', ', $onlyOnHost)).'. '
                .'Off-host only: '.($onlyOffHost === [] ? 'none' : implode(', ', $onlyOffHost)).'.',
            );
        }

        foreach ($leftTables as $table => $entry) {
            if ($entry !== $rightTables[$table]) {
                throw new RuntimeException(
                    "The on-host and off-host restores disagree about table {$table}: "
                    ."{$entry['row_count']} rows on-host against {$rightTables[$table]['row_count']} off-host.",
                );
            }
        }
    }
}
