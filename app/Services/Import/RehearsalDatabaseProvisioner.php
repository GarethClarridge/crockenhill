<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Builds the clean database readiness-plan §13.5 step 3 stages the corpus into.
 *
 * F2 established that staging historic evidence over an earlier evidence-free
 * import of the same corpus turns every affected service into an
 * `unnormalized_legacy_items` proposal, so the §9.4 census measures that import
 * rather than the projector. {@see UnevidencedCanonicalItemGuard} refuses the
 * staging run when that is true; this class is the other half of the same
 * sentence — the thing an operator runs to make the refusal go away honestly.
 *
 * Measured against the approved `oos-curated-2026-08-11` manifest on
 * 2026-08-11, 231 of the corpus's 521 identities already hold unevidenced items
 * in the working database, so this is not a formality.
 *
 * **This is the most destructive command in the workstream: it drops a
 * database.** Every guard here exists to bound that. In particular the target
 * must be a distinct, rehearsal-named database, and
 * {@see HistoricImportProductionGuard::guardsCurrentEnvironment()} is reused
 * rather than a bare `APP_ENV` check, because the case that actually loses data
 * is a local shell pointed at the production target, not a process that knows
 * it is production. Since HIR1 that check compares the stable production
 * database anchor, so it no longer stops protecting this command when the
 * release identifier or a migration count drifts.
 *
 * The rehearsal connection exists only so this class can build the schema
 * without repointing the working one. Nothing else uses it: every importer and
 * {@see HistoricImportTargetFingerprint} resolve the *default* connection, so a
 * staging run happens by pointing `DB_DATABASE` at the provisioned database.
 */
final class RehearsalDatabaseProvisioner
{
    public const Connection = 'rehearsal';

    /**
     * The tables whose emptiness is what F2 means by "clean".
     *
     * Deliberately the canonical and evidence tables rather than every table:
     * a rehearsal database is allowed to carry reference data such as the song
     * catalogue, and demanding a wholly empty schema would make the
     * certification fail for reasons that have nothing to do with the census.
     */
    private const CanonicalTables = [
        'church_services',
        'church_service_items',
        'church_service_source_records',
        'sermons',
        'inbound_emails',
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly HistoricImportProductionGuard $production,
    ) {}

    /**
     * The database this provisioner would build, as configured.
     */
    public function targetDatabase(): string
    {
        $name = config('database.connections.'.self::Connection.'.database');

        return is_string($name) ? trim($name) : '';
    }

    /**
     * The base schema every environment's lineage runs through.
     *
     * `database/schema/mysql-schema.sql` is load-bearing rather than a cache:
     * the migration set was pruned, so no migration on disk creates a base
     * table and a database migrated without the dump comes out unusable. Laravel
     * resolves the dump by *connection name*, so a `rehearsal` connection looks
     * for `rehearsal-schema.sql`, silently finds nothing and migrates from
     * empty. The caller passes this to `migrate --schema-path` to pin it.
     */
    public function schemaPath(): string
    {
        return database_path('schema/'.$this->database->getDefaultConnection().'-schema.sql');
    }

    /**
     * The operator-facing reason this database must not be provisioned, or null.
     *
     * Returns a message rather than throwing, matching the refusal style the
     * other import guards already use.
     */
    public function refusalFor(string $operation): ?string
    {
        if ($this->production->guardsCurrentEnvironment()) {
            return implode(PHP_EOL, [
                "Refusing to run {$operation}: this shell resolves the production database anchor.",
                'A rehearsal database is provisioned on a development machine, never against production.',
                'Run historic-import:prepare-operation --anchors to see what this host resolves.',
            ]);
        }

        $name = $this->targetDatabase();
        $working = (string) $this->database->connection()->getDatabaseName();

        if ($name === '') {
            return implode(PHP_EOL, [
                "Refusing to run {$operation}: no rehearsal database is configured.",
                'Set DB_REHEARSAL_DATABASE to the database this staging run should build.',
            ]);
        }

        /*
         * A database name cannot be a bound parameter, so it is interpolated
         * into DDL. Restricting it to an unquoted MySQL identifier is what makes
         * that safe, and it has to be checked before the name reaches a query.
         */
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1) {
            return implode(PHP_EOL, [
                "Refusing to run {$operation}: '{$name}' is not a plain database identifier.",
                'The name is interpolated into DDL, so only letters, digits and underscores are accepted.',
            ]);
        }

        /*
         * Checked before the naming rule because it is the more urgent of the
         * two: an operator who has already repointed DB_DATABASE at the
         * rehearsal database satisfies the naming rule and would still be
         * dropping the database underneath the shell they are typing into.
         */
        if ($name === $working) {
            return implode(PHP_EOL, [
                "Refusing to run {$operation}: the rehearsal and working databases are both '{$name}'.",
                'Staging must build a separate database; dropping the one in use would destroy the working corpus.',
                'Point DB_DATABASE back at the working database, provision, then repoint it.',
            ]);
        }

        if (! str_contains($name, 'rehearsal')) {
            return implode(PHP_EOL, [
                "Refusing to run {$operation}: '{$name}' is not named as a rehearsal database.",
                'This command drops the database it targets, so the name must contain "rehearsal".',
            ]);
        }

        if (! is_file($this->schemaPath())) {
            return implode(PHP_EOL, [
                "Refusing to run {$operation}: the base schema dump {$this->schemaPath()} is missing.",
                'No migration on disk creates a base table, so a database built without it is unusable.',
            ]);
        }

        return null;
    }

    /**
     * Drop and recreate the rehearsal schema, leaving it empty and migratable.
     *
     * Issued over the *working* connection because a connection cannot be opened
     * to a database that does not exist yet.
     */
    public function provision(): void
    {
        $name = $this->targetDatabase();
        $connection = $this->database->connection();

        try {
            $connection->statement("DROP DATABASE IF EXISTS `{$name}`");
            $connection->statement(
                "CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(implode(PHP_EOL, [
                "Could not provision `{$name}`: {$exception->getMessage()}",
                'The application user is granted only the databases named in',
                'docker/mysql/create-testing-database.sh, and that script runs once when the',
                'MySQL volume is first initialised. On an existing volume, grant it once as root:',
                sprintf(
                    '  vendor/bin/sail exec mysql mysql -uroot -p"$DB_PASSWORD" -e "GRANT ALL PRIVILEGES ON \\`%s\\`.* TO \'%s\'@\'%%\'; FLUSH PRIVILEGES;"',
                    $name,
                    (string) config('database.connections.'.$this->database->getDefaultConnection().'.username'),
                ),
            ]), previous: $exception);
        }

        $this->database->purge(self::Connection);
    }

    /**
     * Prove the provisioned database holds no canonical or evidence rows.
     *
     * The provisioning path cannot produce a dirty database, so this is not
     * checking its own work — it is the evidence F2 asks for, and it is equally
     * the check that catches a `DB_REHEARSAL_DATABASE` pointed at a database
     * somebody else has already staged into.
     *
     * The connection is a parameter rather than a constant because a second PDO
     * session cannot see an open transaction's uncommitted rows, so a test that
     * hard-coded the rehearsal connection could only ever exercise the empty
     * case. The command always passes the default.
     *
     * @return array<string, int> table => row count, every one of them zero
     */
    public function certify(?string $connection = null): array
    {
        $connection = $this->database->connection($connection ?? self::Connection);
        $counts = [];

        foreach (self::CanonicalTables as $table) {
            $counts[$table] = $connection->table($table)->count();
        }

        $populated = array_keys(array_filter($counts, static fn (int $rows): bool => $rows > 0));

        if ($populated !== []) {
            throw new RuntimeException(implode(PHP_EOL, [
                "The rehearsal database `{$this->targetDatabase()}` is not clean: ".implode(', ', $populated).' already hold rows.',
                'Staging over them would make the §9.4 census measure the earlier import rather than the projector.',
            ]));
        }

        return $counts;
    }

    /**
     * Whether the application is pointed at the rehearsal database right now.
     *
     * Reported after provisioning so an operator is never left assuming the
     * switch happened as a side effect of building the schema. It did not.
     */
    public function isCurrentTarget(): bool
    {
        return $this->targetDatabase() !== ''
            && $this->targetDatabase() === (string) $this->database->connection()->getDatabaseName();
    }
}
