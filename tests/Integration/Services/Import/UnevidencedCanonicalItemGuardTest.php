<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Import;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Services\Import\UnevidencedCanonicalItemGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F2 of the 2026-08-07 readiness audit.
 *
 * `IngestChurchServiceSourceRevision` correctly refuses to project over items no
 * normalized source accounts for — projecting would delete them. Staged against a
 * database that already holds a evidence-free import of the same corpus, that correct
 * rule turns every affected service into a proposal, and the §9.4 census ends up
 * measuring the previous import rather than the projector.
 *
 * §13.5 step 3 now says "clean rehearsal database". This guard is that sentence made
 * answerable, on the same principle as `HistoricImportProductionGuard`: an unenforced
 * precondition has to be interpreted, an enforced one simply answers.
 */
class UnevidencedCanonicalItemGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_clean_database_is_not_refused(): void
    {
        $refusal = app(UnevidencedCanonicalItemGuard::class)
            ->refusalFor('oos:import-archive --import', [['2026-02-22', 'morning']]);

        $this->assertNull($refusal);
    }

    #[Test]
    public function an_identity_holding_items_without_normalized_evidence_is_refused(): void
    {
        $this->serviceHoldingItem('2026-02-22', 'morning', evidenced: false);

        $refusal = app(UnevidencedCanonicalItemGuard::class)
            ->refusalFor('oos:import-archive --import', [['2026-02-22', 'morning']]);

        $this->assertIsString($refusal);
        $this->assertStringContainsString('1 of 1', $refusal);
        $this->assertStringContainsString('--accept-unevidenced-items', $refusal);
    }

    /**
     * The rule is about evidence, not about emptiness. A service already projected from
     * normalized assertions is exactly what a re-run should meet, and re-projecting it
     * is a no-op rather than a deletion.
     */
    #[Test]
    public function an_identity_whose_items_carry_normalized_evidence_is_not_refused(): void
    {
        $this->serviceHoldingItem('2026-02-22', 'morning', evidenced: true);

        $refusal = app(UnevidencedCanonicalItemGuard::class)
            ->refusalFor('oos:import-archive --import', [['2026-02-22', 'morning']]);

        $this->assertNull($refusal);
    }

    /**
     * Only the corpus about to be staged matters. An unrelated legacy service is not
     * this run's problem and must not block it — §12.4 owns that population, and
     * refusing here would make the guard un-satisfiable on any real database.
     */
    #[Test]
    public function an_identity_outside_the_staged_corpus_is_ignored(): void
    {
        $this->serviceHoldingItem('2019-01-06', 'evening', evidenced: false);

        $refusal = app(UnevidencedCanonicalItemGuard::class)
            ->refusalFor('oos:import-archive --import', [['2026-02-22', 'morning']]);

        $this->assertNull($refusal);
    }

    #[Test]
    public function a_service_with_no_items_at_all_is_not_refused(): void
    {
        ChurchService::factory()->create(['date' => '2026-02-22', 'service' => 'morning']);

        $refusal = app(UnevidencedCanonicalItemGuard::class)
            ->refusalFor('oos:import-archive --import', [['2026-02-22', 'morning']]);

        $this->assertNull($refusal);
    }

    #[Test]
    public function the_refusal_counts_affected_identities_rather_than_items(): void
    {
        $this->serviceHoldingItem('2026-02-22', 'morning', evidenced: false, items: 3);
        $this->serviceHoldingItem('2026-03-01', 'morning', evidenced: false, items: 4);
        $this->serviceHoldingItem('2026-03-08', 'morning', evidenced: true);

        $refusal = app(UnevidencedCanonicalItemGuard::class)->refusalFor('oos:import-archive --import', [
            ['2026-02-22', 'morning'],
            ['2026-03-01', 'morning'],
            ['2026-03-08', 'morning'],
        ]);

        $this->assertIsString($refusal);
        $this->assertStringContainsString('2 of 3', $refusal);
    }

    private function serviceHoldingItem(
        string $date,
        string $service,
        bool $evidenced,
        int $items = 1,
    ): ChurchService {
        $churchService = ChurchService::factory()->create(['date' => $date, 'service' => $service]);

        foreach (range(1, $items) as $position) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $position,
                'metadata' => $evidenced ? ['source_assertion_hashes' => ['abc']] : ['source' => 'legacy'],
            ]);
        }

        return $churchService;
    }
}
