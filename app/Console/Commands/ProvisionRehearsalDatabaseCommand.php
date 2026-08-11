<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\RehearsalDatabaseProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Provision and reset the readiness-plan §13.5 step 3 rehearsal database.
 *
 * The §9.4 census is a loop — automate a proposal class, re-project, re-census,
 * repeat — and each iteration wants the corpus staged into a database that no
 * previous iteration has touched. So this is a reset command an operator runs
 * repeatedly, not a one-off bootstrap.
 *
 * The procedure it belongs to:
 *
 *   1. vendor/bin/sail artisan historic-import:provision-rehearsal-database
 *   2. Point DB_DATABASE at the provisioned database (this command deliberately
 *      does not, so that switching targets stays an explicit operator act).
 *   3. Stage the approved corpus:
 *      vendor/bin/sail artisan oos:import-archive --import \
 *        --manifest=storage/scratch/oos-curation-manifest.json --plan-hash=...
 *   4. Project and run the §9.4 census; automate a class; return to step 1.
 *
 * Delete once the historic import operation has passed production acceptance
 * and no further rehearsal is possible.
 */
class ProvisionRehearsalDatabaseCommand extends Command
{
    protected $signature = 'historic-import:provision-rehearsal-database';

    protected $description = 'Drop, rebuild and certify the clean rehearsal database historic staging requires';

    public function handle(RehearsalDatabaseProvisioner $provisioner): int
    {
        $operation = 'historic-import:provision-rehearsal-database';
        $refusal = $provisioner->refusalFor($operation);

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $database = $provisioner->targetDatabase();

        try {
            $this->line("Provisioning `{$database}`.");
            $provisioner->provision();

            /*
             * `--schema-path` is required, not tidiness. Laravel resolves the
             * base dump by connection name, so it would look for
             * `rehearsal-schema.sql`, silently find nothing, and migrate from
             * empty — which cannot build this schema, because the migration set
             * was pruned and no migration on disk creates a base table.
             */
            $exitCode = $this->call('migrate', [
                '--database' => RehearsalDatabaseProvisioner::Connection,
                '--schema-path' => $provisioner->schemaPath(),
                '--force' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->error("Migrating `{$database}` failed; the rehearsal database is not usable.");

                return self::FAILURE;
            }

            $counts = $provisioner->certify();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Canonical table', 'Rows'],
            array_map(
                static fn (string $table, int $rows): array => [$table, (string) $rows],
                array_keys($counts),
                array_values($counts),
            ),
        );

        $this->info("`{$database}` is provisioned and certified clean.");

        if (! $provisioner->isCurrentTarget()) {
            $this->newLine();
            $this->warn('The application is still pointed at the working database.');
            $this->line("Set DB_REHEARSAL_DATABASE's value as DB_DATABASE before staging, or the run will refuse:");
            $this->line("  DB_DATABASE={$database}");
        }

        return self::SUCCESS;
    }
}
