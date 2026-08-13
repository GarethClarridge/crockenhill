<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Services\Import\HistoricImportResourceIdentity;
use App\Services\Import\HistoricReleaseDestinationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Plan §4.2.1: the compensating control HIR-D2 left HIR7 to carry.
 *
 * HIR-D2 demoted the storage anchor so only the database anchor arms the
 * production guard — it had to, because `.env` resolves local dev's public
 * sermon disk to the production bucket and an OR-ed storage anchor would have
 * classified every developer machine as production and refused the §13.5
 * rehearsal. The accepted residual risk was that a local release still writes to
 * the production public bucket. This is where that write is refused instead.
 *
 * The guard is deliberately *not* re-arming the anchor: it refuses one thing,
 * at one point, and a rehearsal publishing to a rehearsal disk never meets it.
 */
class HistoricReleaseDestinationGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_non_production_process_may_not_write_to_the_recorded_production_destination(): void
    {
        $this->recordProductionAnchorFor('public');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resolves to the recorded production storage anchor');

        app(HistoricReleaseDestinationGuard::class)->assertWritable('public');
    }

    /** The rehearsal publishes somewhere else, and is untouched by this. */
    #[Test]
    public function a_destination_that_is_not_the_production_one_is_writable(): void
    {
        $this->recordProductionAnchorFor('public');

        app(HistoricReleaseDestinationGuard::class)->assertWritable('historic_quarantine');

        $this->addToAssertionCount(1);
    }

    /**
     * An environment that never recorded an anchor cannot know it is about to
     * write to production, and refusing every release on that basis would gate
     * the rehearsal on configuration only a production deploy supplies. That is
     * the same reasoning HIR1 applied to an absent database anchor.
     */
    #[Test]
    public function an_absent_or_malformed_anchor_refuses_nothing(): void
    {
        Config::set('church.historic_corpus.production_storage_anchor', null);
        app(HistoricReleaseDestinationGuard::class)->assertWritable('public');

        Config::set('church.historic_corpus.production_storage_anchor', 'not-a-digest');
        app(HistoricReleaseDestinationGuard::class)->assertWritable('public');

        $this->addToAssertionCount(2);
    }

    /**
     * The override is separately named so nothing that authorises the rest of
     * the operation can switch it off as a side effect.
     */
    #[Test]
    public function the_separately_named_override_accepts_the_write(): void
    {
        $this->recordProductionAnchorFor('public');
        Config::set('church.historic_corpus.allow_non_production_release_destination', true);

        app(HistoricReleaseDestinationGuard::class)->assertWritable('public');

        $this->addToAssertionCount(1);
    }

    /**
     * On the production target itself the guard has nothing to say: the whole
     * point of the release is to write there.
     */
    #[Test]
    public function the_production_target_writes_to_its_own_destination(): void
    {
        $this->recordProductionAnchorFor('public');
        Config::set(
            'church.historic_corpus.production_database_anchor',
            app(HistoricImportResourceIdentity::class)->databaseAnchor(),
        );

        app(HistoricReleaseDestinationGuard::class)->assertWritable('public');

        $this->addToAssertionCount(1);
    }

    private function recordProductionAnchorFor(string $disk): void
    {
        Config::set(
            'church.historic_corpus.production_storage_anchor',
            app(HistoricImportResourceIdentity::class)->anchorFor($disk),
        );
        Config::set('church.historic_corpus.allow_non_production_release_destination', false);
    }
}
