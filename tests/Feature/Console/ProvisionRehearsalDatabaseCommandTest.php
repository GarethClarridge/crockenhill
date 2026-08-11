<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Services\Import\RehearsalDatabaseProvisionerTest;
use Tests\TestCase;

/**
 * The operator entry point for readiness-plan §13.5 step 3.
 *
 * The DDL is not exercised here — building the schema loads the whole stored
 * dump, and a suite that dropped databases would collide with itself under
 * `--parallel`. {@see RehearsalDatabaseProvisionerTest}
 * covers the guard set; what matters at this layer is that the command is
 * reachable and that a refusal reaches the operator with nothing done.
 */
class ProvisionRehearsalDatabaseCommandTest extends TestCase
{
    /**
     * Laravel silently omits a command whose file cannot load, which has already
     * cost this workstream one absent closeout command. Asserting registration
     * is cheap insurance against a parse error hiding the same way.
     */
    #[Test]
    public function the_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'historic-import:provision-rehearsal-database',
            $this->app[Kernel::class]->all(),
        );
    }

    #[Test]
    public function it_refuses_a_target_that_is_the_working_database_and_builds_nothing(): void
    {
        $working = DB::connection()->getDatabaseName();
        Config::set('database.connections.rehearsal.database', $working);

        $this->artisan('historic-import:provision-rehearsal-database')
            ->expectsOutputToContain('would destroy the working corpus')
            ->assertExitCode(1);

        // The refusal is only worth anything if the working schema survived it.
        $this->assertSame($working, DB::connection()->getDatabaseName());
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('church_services'));
    }

    #[Test]
    public function it_refuses_an_unconfigured_rehearsal_database(): void
    {
        Config::set('database.connections.rehearsal.database', null);

        $this->artisan('historic-import:provision-rehearsal-database')
            ->expectsOutputToContain('DB_REHEARSAL_DATABASE')
            ->assertExitCode(1);
    }
}
