<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Models\ScripturePassage;
use App\Services\HistoricMedia\HistoricScripturePassageRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Decision D3's preflight input: what a bundle requires, and what the
 * destination cannot give it.
 */
class HistoricScripturePassageRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private HistoricScripturePassageRequirements $requirements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requirements = app(HistoricScripturePassageRequirements::class);
    }

    #[Test]
    public function it_collects_each_distinct_identity_a_bundle_requires(): void
    {
        $bundle = $this->bundle([
            [$this->publication('bible-a', 'John 3:16'), $this->publication('bible-a', 'Acts 2:1')],
            [$this->publication('bible-a', 'John 3:16'), $this->publication('bible-b', 'John 3:16')],
        ]);

        $this->assertSame([
            ['bible_id' => 'bible-a', 'normalized_reference' => 'Acts 2:1'],
            ['bible_id' => 'bible-a', 'normalized_reference' => 'John 3:16'],
            ['bible_id' => 'bible-b', 'normalized_reference' => 'John 3:16'],
        ], $this->requirements->forBundle($bundle));
    }

    #[Test]
    public function it_requires_nothing_for_a_publication_that_settled_on_an_approved_absence(): void
    {
        $bundle = $this->bundle([[[
            'slug' => 'no-passage',
            'scripture_passage' => null,
            'scripture_passage_outcome' => ['status' => 'approved_absent', 'reason' => 'not_found'],
        ]]]);

        $this->assertSame([], $this->requirements->forBundle($bundle));
    }

    #[Test]
    public function it_refuses_an_absence_the_export_never_settled(): void
    {
        $bundle = $this->bundle([[[
            'slug' => 'unsettled',
            'scripture_passage' => null,
            'scripture_passage_outcome' => ['status' => 'pending', 'reason' => 'not_found'],
        ]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scripture Passage absence outcome is invalid');

        $this->requirements->forBundle($bundle);
    }

    /**
     * HIR0 red test for review finding 6 (Medium), owned by package **HIR3**.
     *
     * `keyFor()` rejects an invalid absence outcome only when the outcome is
     * itself non-null. A publication whose `scripture_passage` is null and whose
     * `scripture_passage_outcome` is missing or explicitly null therefore
     * returns null — indistinguishable from an approved terminal absence — and
     * passes the zero-write preflight and the apply.
     *
     * That inverts the class's own contract: F59 gates export on a *settled*
     * outcome, and missing evidence is not approval. A partial or malformed
     * Bundle A silently drops the destination's Scripture relationship instead
     * of refusing before any row is written.
     *
     * Both shapes are covered because they arrive differently: a key omitted by
     * an exporter, and a key written as `null` by one that emitted the field
     * without settling it.
     *
     * @see docs/reviews/historic-import-commit-review-2026-08-12.md finding 6
     * @see docs/plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §9 (HIR3)
     */
    #[Test]
    #[Group('hir-red')]
    public function it_refuses_an_absent_passage_with_no_outcome_at_all(): void
    {
        $missingKey = $this->bundle([[['slug' => 'no-outcome-key', 'scripture_passage' => null]]]);
        $explicitNull = $this->bundle([[[
            'slug' => 'null-outcome',
            'scripture_passage' => null,
            'scripture_passage_outcome' => null,
        ]]]);

        foreach (['omitted' => $missingKey, 'explicitly null' => $explicitNull] as $shape => $bundle) {
            try {
                $this->requirements->forBundle($bundle);
                $this->fail("An {$shape} Scripture Passage outcome was accepted as an approved absence.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Scripture Passage absence outcome is invalid', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_refuses_a_passage_whose_outcome_does_not_claim_it_is_linked(): void
    {
        $bundle = $this->bundle([[[
            'slug' => 'unlinked',
            'scripture_passage' => ['bible_id' => 'bible-a', 'normalized_reference' => 'John 3:16'],
            'scripture_passage_outcome' => ['status' => 'approved_absent', 'reason' => 'not_found'],
        ]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('scripture passage identity is invalid');

        $this->requirements->forBundle($bundle);
    }

    #[Test]
    public function it_reports_only_the_identities_the_destination_cannot_satisfy(): void
    {
        ScripturePassage::factory()->create([
            'bible_id' => 'bible-a',
            'normalized_reference' => 'John 3:16',
        ]);

        $missing = $this->requirements->missing([
            ['bible_id' => 'bible-a', 'normalized_reference' => 'John 3:16'],
            ['bible_id' => 'bible-a', 'normalized_reference' => 'Acts 2:1'],
            ['bible_id' => 'bible-b', 'normalized_reference' => 'John 3:16'],
        ]);

        $this->assertSame([
            ['bible_id' => 'bible-a', 'normalized_reference' => 'Acts 2:1'],
            ['bible_id' => 'bible-b', 'normalized_reference' => 'John 3:16'],
        ], $missing);
    }

    #[Test]
    public function it_names_every_missing_identity_in_one_refusal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bible-a|Acts 2:1, bible-a|John 3:16');

        $this->requirements->assertAvailable([
            ['bible_id' => 'bible-a', 'normalized_reference' => 'Acts 2:1'],
            ['bible_id' => 'bible-a', 'normalized_reference' => 'John 3:16'],
        ], 'Historic convergence operation test-operation');
    }

    #[Test]
    public function it_accepts_a_bundle_the_destination_can_fully_satisfy(): void
    {
        ScripturePassage::factory()->create([
            'bible_id' => 'bible-a',
            'normalized_reference' => 'John 3:16',
        ]);

        $this->requirements->assertAvailable(
            [['bible_id' => 'bible-a', 'normalized_reference' => 'John 3:16']],
            'Historic convergence operation test-operation',
        );

        $this->assertSame([], $this->requirements->missing([
            ['bible_id' => 'bible-a', 'normalized_reference' => 'John 3:16'],
        ]));
    }

    /**
     * @param  list<list<array<string, mixed>>>  $publicationsPerService
     * @return array<string, mixed>
     */
    private function bundle(array $publicationsPerService): array
    {
        return [
            'services' => array_map(
                static fn (array $publications): array => [
                    'media_graph' => ['publications' => $publications],
                ],
                $publicationsPerService,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function publication(string $bibleId, string $reference): array
    {
        return [
            'slug' => "sermon-{$reference}",
            'scripture_passage' => ['bible_id' => $bibleId, 'normalized_reference' => $reference],
            'scripture_passage_outcome' => ['status' => 'linked'],
        ];
    }
}
