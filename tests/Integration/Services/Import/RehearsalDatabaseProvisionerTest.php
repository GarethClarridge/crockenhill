<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Import;

use App\Models\ChurchService;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\HistoricImportTargetFingerprint;
use App\Services\Import\RehearsalDatabaseProvisioner;
use App\Services\Import\UnevidencedCanonicalItemGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The provisioning half of F2's "clean rehearsal database".
 *
 * {@see UnevidencedCanonicalItemGuard} refuses a staging run
 * over an evidence-free import of the same corpus; this class is what an operator
 * runs to make that refusal go away honestly. It drops a database, which makes it
 * the most destructive command in the workstream, so most of what is asserted here
 * is what it declines to do.
 *
 * The DDL itself is deliberately not exercised: creating and dropping databases
 * from the suite would need privileges the test user is not granted, and the
 * interesting behaviour is the guard set rather than `CREATE DATABASE`.
 */
class RehearsalDatabaseProvisionerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_distinct_correctly_named_rehearsal_database_is_not_refused(): void
    {
        Config::set('database.connections.rehearsal.database', 'crockenhill_rehearsal');

        $this->assertNull($this->provisioner()->refusalFor('provision'));
    }

    /**
     * The case that actually loses data is not a process that knows it is
     * production — it is a development shell resolving the production target.
     */
    #[Test]
    public function provisioning_is_refused_when_the_shell_resolves_the_production_target(): void
    {
        Config::set('database.connections.rehearsal.database', 'crockenhill_rehearsal');
        Config::set(
            'church.historic_corpus.production_target_fingerprint',
            app(HistoricImportTargetFingerprint::class)->hash(),
        );

        $refusal = $this->provisioner('local')->refusalFor('provision');

        $this->assertIsString($refusal);
        $this->assertStringContainsString('resolves the production target', $refusal);
    }

    #[Test]
    public function provisioning_is_refused_when_no_rehearsal_database_is_configured(): void
    {
        Config::set('database.connections.rehearsal.database', '   ');

        $refusal = $this->provisioner()->refusalFor('provision');

        $this->assertIsString($refusal);
        $this->assertStringContainsString('DB_REHEARSAL_DATABASE', $refusal);
    }

    /**
     * A database name cannot be a bound parameter, so it reaches DDL by
     * interpolation. The identifier rule is what makes that safe.
     */
    #[Test]
    public function provisioning_is_refused_when_the_name_is_not_a_plain_identifier(): void
    {
        Config::set('database.connections.rehearsal.database', 'crockenhill_rehearsal`; DROP DATABASE `crockenhill');

        $refusal = $this->provisioner()->refusalFor('provision');

        $this->assertIsString($refusal);
        $this->assertStringContainsString('not a plain database identifier', $refusal);
    }

    #[Test]
    public function provisioning_is_refused_when_the_name_is_not_a_rehearsal_name(): void
    {
        Config::set('database.connections.rehearsal.database', 'crockenhill');

        $refusal = $this->provisioner()->refusalFor('provision');

        $this->assertIsString($refusal);
        $this->assertStringContainsString('not named as a rehearsal database', $refusal);
    }

    /**
     * Ordered before the naming rule: an operator who has already repointed
     * DB_DATABASE satisfies the naming rule and would still be dropping the
     * database underneath the shell they are typing into.
     */
    #[Test]
    public function provisioning_is_refused_when_it_would_drop_the_working_database(): void
    {
        Config::set('database.connections.rehearsal.database', DB::connection()->getDatabaseName());

        $refusal = $this->provisioner()->refusalFor('provision');

        $this->assertIsString($refusal);
        $this->assertStringContainsString('would destroy the working corpus', $refusal);
    }

    #[Test]
    public function provisioning_is_refused_when_the_base_schema_dump_is_missing(): void
    {
        Config::set('database.connections.rehearsal.database', 'crockenhill_rehearsal');
        DB::setDefaultConnection('sqlite');

        try {
            $refusal = $this->provisioner()->refusalFor('provision');
        } finally {
            DB::setDefaultConnection('mysql');
        }

        $this->assertIsString($refusal);
        $this->assertStringContainsString('base schema dump', $refusal);
    }

    /**
     * The trap this pins: Laravel resolves the stored schema by *connection
     * name*, so migrating the `rehearsal` connection looks for
     * `rehearsal-schema.sql`, silently finds nothing and migrates from empty —
     * which cannot build this schema, because the migration set was pruned and
     * no migration on disk creates a base table.
     */
    #[Test]
    public function the_schema_path_follows_the_default_connection_not_the_rehearsal_one(): void
    {
        $path = $this->provisioner()->schemaPath();

        $this->assertStringEndsWith('mysql-schema.sql', $path);
        $this->assertFileExists($path);
    }

    /**
     * Certification is not the provisioner checking its own work. It is what
     * catches DB_REHEARSAL_DATABASE pointed at a database somebody has already
     * staged into.
     */
    #[Test]
    public function certification_fails_when_the_target_already_holds_canonical_rows(): void
    {
        ChurchService::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('church_services');

        $this->provisioner()->certify(DB::getDefaultConnection());
    }

    /**
     * Exercises the no-argument default, which is what the command uses. The
     * rehearsal connection is pointed at the test database, so this also proves
     * the certification opens its own session rather than reading whichever
     * connection happens to be current.
     */
    #[Test]
    public function certification_passes_and_reports_every_canonical_table_as_empty(): void
    {
        Config::set('database.connections.rehearsal.database', DB::connection()->getDatabaseName());
        DB::purge('rehearsal');

        $counts = $this->provisioner()->certify();

        $this->assertSame(
            ['church_services', 'church_service_items', 'church_service_source_records', 'sermons', 'inbound_emails'],
            array_keys($counts),
        );
        $this->assertSame([0, 0, 0, 0, 0], array_values($counts));
    }

    private function provisioner(string $environment = 'testing'): RehearsalDatabaseProvisioner
    {
        // `isProduction()` reads the container's `env` binding rather than
        // `config('app.env')`, so setting the config alone would leave the
        // production guard looking at the test environment.
        $this->app['env'] = $environment;

        return new RehearsalDatabaseProvisioner(
            $this->app->make('db'),
            new HistoricImportProductionGuard($this->app),
        );
    }
}
