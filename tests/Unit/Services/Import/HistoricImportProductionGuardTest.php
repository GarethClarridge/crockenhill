<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Services\Import\HistoricImportProductionGuard;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricImportProductionGuardTest extends TestCase
{
    #[Test]
    public function it_is_silent_outside_production(): void
    {
        // The whole point of scoping the G8 prohibition: a rehearsal database is
        // where §13.5 steps 3-4 stage and re-project the corpus, repeatedly.
        Config::set('church.historic_corpus.production_import_approval', null);

        $this->assertNull($this->guard('local')->refusalFor('oos:import-archive --import'));
    }

    #[Test]
    public function it_refuses_in_production_when_no_approval_is_recorded(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);

        $refusal = $this->guard('production')->refusalFor('oos:import-archive --import');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('oos:import-archive --import', $refusal);
        $this->assertStringContainsString('HISTORIC_IMPORT_PRODUCTION_APPROVAL', $refusal);
    }

    #[Test]
    public function it_permits_a_production_run_the_approval_names(): void
    {
        Config::set('church.historic_corpus.production_import_approval', 'g8-2026-08-20');

        $guard = $this->guard('production');

        $this->assertNull($guard->refusalFor('oos:import-archive --import'));
        $this->assertSame('g8-2026-08-20', $guard->approvedOperationId());
    }

    /**
     * A fail-closed default that a stray space defeats is not fail-closed. The
     * realistic way this happens is an env line left as `=` or `= ` while a
     * window is being set up.
     */
    #[Test]
    public function it_does_not_read_a_blank_approval_as_permission(): void
    {
        Config::set('church.historic_corpus.production_import_approval', '   ');

        $guard = $this->guard('production');

        $this->assertNull($guard->approvedOperationId());
        $this->assertNotNull($guard->refusalFor('oos:import-archive --import'));
    }

    #[Test]
    public function it_trims_a_recorded_approval(): void
    {
        Config::set('church.historic_corpus.production_import_approval', "  g8-2026-08-20\n");

        $this->assertSame('g8-2026-08-20', $this->guard('production')->approvedOperationId());
    }

    private function guard(string $environment): HistoricImportProductionGuard
    {
        // `isProduction()` reads the container's `env` binding rather than
        // `config('app.env')`, so setting the config alone would leave the guard
        // looking at the test environment and every assertion below vacuous.
        $this->app['env'] = $environment;

        return new HistoricImportProductionGuard($this->app);
    }
}
