<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\CanonicalJson;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The *stable* half of a historic import target: which database and which
 * object store, and nothing about which release or configuration is pointed at
 * them.
 *
 * HIR1 exists because {@see HistoricImportTargetFingerprint} conflated the two.
 * Its hash mixes database and storage identity with the release identifier,
 * migration batch/count and three pipeline settings, and
 * {@see HistoricImportProductionGuard} compared that whole hash to decide
 * whether a target was production. A local shell still pointed at the
 * production database therefore lost its protection the moment any of those
 * drifted — a newer release, one extra migration, a changed transcription
 * setting — which is the misconfiguration the fallback exists to catch.
 *
 * Safety invariant 1 in the plan: stable anchors decide whether a target is
 * production; release, schema and feature configuration decide whether an
 * operation is still the approved operation. Drift in the latter never proves
 * the former is non-production. This class is the former, and the fingerprint
 * keeps the whole binding by consuming it.
 *
 * **Only the database anchor triggers production controls.** HIR-D2 decided
 * that against the recommendation, and the reason is load-bearing: `.env` sets
 * `SERMON_STORAGE_DISK=do_spaces` with `DO_SPACES_BUCKET=crockenhill`, so local
 * dev's public sermon disk *is* the production bucket. An OR-ed storage anchor
 * would classify a developer machine as production and refuse the §13.5
 * rehearsal. {@see storageAnchor()} is therefore computed and recorded so the
 * fingerprint and the operator diagnostic can show it, but the guard does not
 * consult it. The compensating refusal — outside production, never write to a
 * destination matching the recorded production storage anchor — belongs to
 * HIR7's object-store boundary, per plan §4.2.1.
 *
 * Nothing here may carry credentials, a connection URL containing a secret, or
 * a raw production path: these values reach artifacts, journals and operator
 * output. Local roots are reduced to a fingerprint for exactly that reason.
 *
 * Delete alongside the rest of the historic import tooling once the acceptance
 * and rollback-retention windows have expired.
 */
final class HistoricImportResourceIdentity
{
    /**
     * The server identity query per driver.
     *
     * A driver absent from this list fails closed rather than falling back to
     * something unstable such as the host name. An anchor that can be spoofed
     * by an environment variable would be worse than no anchor, because it
     * would look like protection.
     *
     * @var array<string, array{query: string, column: string}>
     */
    private const ServerIdentityQueries = [
        'mysql' => ['query' => 'SELECT @@server_uuid AS identity', 'column' => 'identity'],
        'mariadb' => ['query' => 'SELECT @@server_id AS identity', 'column' => 'identity'],
        'pgsql' => ['query' => 'SELECT system_identifier AS identity FROM pg_control_system()', 'column' => 'identity'],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $databases = [];

    public function __construct(
        private readonly DatabaseManager $connections,
    ) {}

    /**
     * The stable identity of the database this process would mutate.
     *
     * Memoised because {@see HistoricImportTargetFingerprint::hash()} is called
     * on several paths per command and the server identity cannot change inside
     * one process without the connection being rebuilt.
     *
     * @param  string|null  $connection  a named connection, or null for the default one
     * @return array<string, mixed>
     */
    public function database(?string $connection = null): array
    {
        $key = $connection ?? '@default';

        if (isset($this->databases[$key])) {
            return $this->databases[$key];
        }

        $resolved = $this->connections->connection($connection);
        $driver = $resolved->getDriverName();

        return $this->databases[$key] = [
            'driver' => $driver,
            'server_identity_hash' => hash('sha256', $this->serverIdentity($resolved, $driver)),
            'name_hash' => hash('sha256', (string) $resolved->getDatabaseName()),
        ];
    }

    /**
     * The stable identity of the public destination a release publishes to.
     *
     * The *public sermon* disk rather than the quarantine disk, deliberately.
     * Quarantine is operation-owned and per-environment; the bucket that must
     * never be written to from a mislabelled process is the one visitors read,
     * and that is what plan §4.2.1 asks HIR7 to refuse.
     *
     * @return array<string, mixed>
     */
    public function storage(): array
    {
        return $this->diskIdentity($this->publicDiskName());
    }

    /**
     * The same anchor for a named connection, so a disposable restore can be
     * identified the way production is.
     *
     * HIR5 needs three answers this gives: whether a restore is the production
     * database, whether the two restores are one database counted twice, and
     * which database a row manifest was actually read from. Version 1 asked the
     * verifier to type a `restored_target_fingerprint` that differed from the
     * operation's, which any string satisfies.
     */
    public function databaseAnchor(?string $connection = null): string
    {
        return CanonicalJson::hash($this->database($connection));
    }

    public function storageAnchor(): string
    {
        return CanonicalJson::hash($this->storage());
    }

    /**
     * The same anchor for a named disk, so a release can ask "is *this*
     * destination the recorded production one" without assuming the answer is
     * whatever `sermon_disk` currently resolves to.
     */
    public function anchorFor(string $disk): string
    {
        return CanonicalJson::hash($this->diskIdentity($disk));
    }

    /**
     * A disk reduced to what distinguishes it, with nothing that identifies an
     * account or a filesystem layout.
     *
     * `endpoint` is included because a bucket name alone does not identify a
     * store: `bucket_endpoint => true` means the configured endpoint already
     * embeds the bucket, and the live region (`lon1`) differs from the config
     * default (`nyc3`). Hashed rather than recorded verbatim so the value stays
     * safe to print.
     *
     * @return array<string, string|null>
     */
    public function diskIdentity(string $disk): array
    {
        $configuration = config("filesystems.disks.{$disk}", []);

        if (! is_array($configuration)) {
            throw new RuntimeException("Storage disk '{$disk}' is not configured.");
        }

        $root = $configuration['root'] ?? null;
        $prefix = $configuration['prefix'] ?? null;
        $endpoint = $configuration['endpoint'] ?? null;

        return [
            'name' => $disk,
            'driver' => is_string($configuration['driver'] ?? null) ? $configuration['driver'] : null,
            'bucket' => is_string($configuration['bucket'] ?? null) ? $configuration['bucket'] : null,
            'endpoint_fingerprint' => is_string($endpoint) && $endpoint !== '' ? hash('sha256', $endpoint) : null,
            'prefix' => is_string($prefix) ? $prefix : null,
            'root_fingerprint' => is_string($root) ? hash('sha256', $root) : null,
        ];
    }

    private function publicDiskName(): string
    {
        $disk = config('media-processing.storage.sermon_disk');

        if (! is_string($disk) || trim($disk) === '') {
            throw new RuntimeException('The public sermon media disk is not configured.');
        }

        return trim($disk);
    }

    /**
     * The server's own identity, not the application's opinion of it.
     *
     * `@@server_uuid` is regenerated when a data directory is reinitialised, so
     * a managed-database rebuild or node replacement changes the anchor. That
     * fails closed, which is safe, but it is why plan §4.2 requires the anchor
     * to be re-recorded before a window rather than discovered during one.
     */
    private function serverIdentity(Connection $connection, string $driver): string
    {
        $statement = self::ServerIdentityQueries[$driver] ?? null;

        if ($statement === null) {
            throw new RuntimeException(
                "Database driver '{$driver}' exposes no supported stable server identity, so no production anchor can be observed."
            );
        }

        $row = $connection->selectOne($statement['query']);
        $identity = is_object($row) ? ($row->{$statement['column']} ?? null) : null;

        if (! is_scalar($identity) || trim((string) $identity) === '') {
            throw new RuntimeException('The database server did not report a stable identity for the production anchor.');
        }

        return trim((string) $identity);
    }
}
