<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\ItemGroundTruthArmComparison;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ItemGroundTruthArmComparisonTest extends TestCase
{
    private ItemGroundTruthArmComparison $comparison;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comparison = new ItemGroundTruthArmComparison;
    }

    /**
     * The defect this whole comparison exists to prevent.
     *
     * A parse that returns no songs is scored `indeterminate`, so a population filtered on
     * scoreable verdicts drops that parse's total failures from its own denominator. Here the
     * candidate fixes three services the baseline extracted nothing from. Under a filtered
     * denominator both arms would post 1/1; the fixed population has to show the gain.
     */
    #[Test]
    public function it_scores_extraction_failures_the_baseline_never_produced_a_verdict_for(): void
    {
        $baseline = $this->artifact([
            $this->identity('2020-01-05', 'morning', 'indeterminate', songItems: 0),
            $this->identity('2020-01-12', 'morning', 'indeterminate', songItems: 0),
            $this->identity('2020-01-19', 'morning', 'indeterminate', songItems: 0),
            $this->identity('2020-01-26', 'morning', 'match', songItems: 3),
        ]);

        $candidate = $this->artifact([
            $this->identity('2020-01-05', 'morning', 'match', songItems: 3),
            $this->identity('2020-01-12', 'morning', 'match', songItems: 4),
            $this->identity('2020-01-19', 'morning', 'match', songItems: 2),
            $this->identity('2020-01-26', 'morning', 'match', songItems: 3),
        ]);

        $result = $this->comparison->compare($baseline, $candidate)['dimensions']['song_membership'];

        $this->assertSame(4, $result['population'], 'every identity with evidence stays in the denominator');
        $this->assertSame(3, $result['transitions']['indeterminate']['match']);
        $this->assertSame(3, $result['total_extraction_failures_fixed']);
        $this->assertSame(0.25, $result['match_rate']['baseline']['rate']);
        $this->assertSame(1.0, $result['match_rate']['candidate']['rate']);
    }

    /**
     * The mirror case: an arm that extracts *more* can convert silent failures into visible
     * disagreements. That is a real improvement in extraction and it must not read as a
     * regression, so the transition cells are reported rather than a bare match rate.
     */
    #[Test]
    public function it_distinguishes_a_newly_visible_disagreement_from_a_regression(): void
    {
        $baseline = $this->artifact([
            $this->identity('2020-02-02', 'morning', 'indeterminate', songItems: 0),
            $this->identity('2020-02-09', 'morning', 'match', songItems: 3),
        ]);

        $candidate = $this->artifact([
            $this->identity('2020-02-02', 'morning', 'mismatch', songItems: 4),
            $this->identity('2020-02-09', 'morning', 'indeterminate', songItems: 0),
        ]);

        $result = $this->comparison->compare($baseline, $candidate)['dimensions']['song_membership'];

        $this->assertSame(1, $result['transitions']['indeterminate']['mismatch'], 'a silent failure became a visible one');
        $this->assertSame(1, $result['transitions']['match']['indeterminate'], 'a scored identity went silent');
        $this->assertSame(1, $result['total_extraction_failures_fixed']);
        $this->assertSame(1, $result['total_extraction_failures_introduced']);
    }

    #[Test]
    public function it_pairs_identities_and_reports_only_discordant_pairs_as_evidence(): void
    {
        $baseline = $this->artifact([
            $this->identity('2020-03-01', 'morning', 'match'),
            $this->identity('2020-03-08', 'morning', 'mismatch'),
            $this->identity('2020-03-15', 'morning', 'mismatch'),
            $this->identity('2020-03-22', 'morning', 'match'),
        ]);

        $candidate = $this->artifact([
            $this->identity('2020-03-01', 'morning', 'match'),
            $this->identity('2020-03-08', 'morning', 'match'),
            $this->identity('2020-03-15', 'morning', 'match'),
            $this->identity('2020-03-22', 'morning', 'mismatch'),
        ]);

        $discordance = $this->comparison->compare($baseline, $candidate)['dimensions']['song_membership']['discordance'];

        $this->assertSame(2, $discordance['only_candidate_correct']);
        $this->assertSame(1, $discordance['only_baseline_correct']);
        $this->assertSame(3, $discordance['discordant']);
    }

    #[Test]
    public function an_arm_that_changes_nothing_has_no_discordant_pairs(): void
    {
        $identities = [
            $this->identity('2020-04-05', 'morning', 'match'),
            $this->identity('2020-04-12', 'morning', 'mismatch'),
        ];

        $discordance = $this->comparison
            ->compare($this->artifact($identities), $this->artifact($identities))['dimensions']['song_membership']['discordance'];

        $this->assertSame(0, $discordance['discordant']);
    }

    /**
     * These three dimensions decide nothing, so they carry no inferential test.
     *
     * They compare the email plan with what was later sung or projected, and a service can change
     * after the email was written — so a disagreement is not automatically a parser error. Publishing
     * a p-value here would invite adopting an arm on evidence that cannot support it, and three
     * unadjusted p-values would reintroduce the multiplicity obligation that the deleted Holm
     * correction used to discharge. The guard is that no p-value is emitted at all.
     */
    #[Test]
    public function the_secondary_dimensions_carry_no_inferential_p_value(): void
    {
        $baseline = $this->artifact(array_map(
            fn (int $week): array => $this->identity("2020-05-{$this->day($week)}", 'morning', 'mismatch'),
            range(1, 12),
        ));

        $candidate = $this->artifact(array_map(
            fn (int $week): array => $this->identity("2020-05-{$this->day($week)}", 'morning', 'match'),
            range(1, 12),
        ));

        $report = $this->comparison->compare($baseline, $candidate);

        foreach (['song_membership', 'song_count', 'song_order'] as $dimension) {
            $result = $report['dimensions'][$dimension];

            $this->assertArrayNotHasKey('mcnemar', $result, 'the discordance block is not named after a test');
            $this->assertSame(
                ['only_baseline_correct', 'only_candidate_correct', 'discordant'],
                array_keys($result['discordance']),
                'discordance reports counts only',
            );
            $this->assertSame(
                [],
                array_filter(
                    array_keys($result),
                    static fn (string $key): bool => str_contains($key, 'p_value'),
                ),
                "{$dimension} must publish no p-value",
            );
        }

        $this->assertSame(2, $report['version'], 'dropping the p-values is a report format change');
    }

    /**
     * Evidence availability is a fact about the hymn workbook and the OpenLP decks, not about the
     * parse. If it differs between arms the artifacts were built against different evidence and
     * the comparison is unsound, so the identity is withheld and counted rather than scored.
     */
    #[Test]
    public function it_refuses_to_score_identities_whose_evidence_differs_between_arms(): void
    {
        $baseline = $this->artifact([$this->identity('2020-06-07', 'morning', 'match')]);
        $candidate = $this->artifact([$this->identity('2020-06-07', 'morning', 'match', hymnEvidence: false)]);

        $result = $this->comparison->compare($baseline, $candidate)['dimensions']['song_membership'];

        $this->assertSame(1, $result['evidence_drift_identities']);
        $this->assertSame(0, $result['population']);
    }

    #[Test]
    public function it_excludes_identities_no_evidence_corroborates(): void
    {
        $identities = [
            $this->identity('2020-07-05', 'morning', 'not_corroborated', hymnEvidence: false),
            $this->identity('2020-07-12', 'morning', 'match'),
        ];

        $result = $this->comparison
            ->compare($this->artifact($identities), $this->artifact($identities))['dimensions']['song_membership'];

        $this->assertSame(1, $result['population'], 'absent evidence is the only valid exclusion');
    }

    /**
     * A `partial` source is curated to evidence-only retention, so both arms correctly hold no
     * items. Scoring those identities would dilute both arms' match rates with services no model
     * can move, and would read a curation decision as an extraction miss.
     */
    #[Test]
    public function it_withholds_identities_no_model_change_could_affect(): void
    {
        $identities = [
            $this->identity('2020-09-06', 'morning', 'match'),
            $this->identity('2020-09-13', 'morning', 'indeterminate', songItems: 0, tier: 'partial'),
            $this->identity('2020-09-20', 'morning', 'indeterminate', songItems: 0, tier: 'partial'),
            $this->identity('2020-09-27', 'morning', 'indeterminate', songItems: 0, tier: 'no_source'),
            // Current sources disagreeing on scope: no single expectation to score against.
            $this->identity('2020-10-04', 'morning', 'mismatch', tier: 'mixed'),
        ];

        $result = $this->comparison
            ->compare($this->artifact($identities), $this->artifact($identities))['dimensions']['song_membership'];

        $this->assertSame('full', $result['tier']);
        $this->assertSame(1, $result['population'], 'only the model-addressable tier is scored');
        $this->assertSame(['mixed' => 1, 'no_source' => 1, 'partial' => 2], $result['withheld_by_tier']);
        $this->assertSame(1.0, $result['match_rate']['baseline']['rate'], 'the rate is not diluted');
    }

    /**
     * A curation tier is a decision, not a parse output. If it moved between arms the corpus was
     * re-curated and the two artifacts are not measuring the same sources.
     */
    #[Test]
    public function it_refuses_to_score_identities_whose_curation_tier_differs_between_arms(): void
    {
        $baseline = $this->artifact([$this->identity('2020-10-04', 'morning', 'match')]);
        $candidate = $this->artifact([$this->identity('2020-10-04', 'morning', 'match', tier: 'partial')]);

        $result = $this->comparison->compare($baseline, $candidate)['dimensions']['song_membership'];

        $this->assertSame(1, $result['tier_drift_identities']);
        $this->assertSame(0, $result['population']);
    }

    #[Test]
    public function it_rejects_an_artifact_with_no_identities(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('candidate artifact carries no identities array');

        $this->comparison->compare($this->artifact([$this->identity('2020-08-02', 'morning', 'match')]), []);
    }

    private function day(int $week): string
    {
        return str_pad((string) $week, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array<string, mixed>>  $identities
     * @return array<string, mixed>
     */
    private function artifact(array $identities): array
    {
        return ['identities' => $identities];
    }

    /** @return array<string, mixed> */
    private function identity(
        string $date,
        string $service,
        string $verdict,
        int $songItems = 3,
        bool $hymnEvidence = true,
        string $tier = 'full',
    ): array {
        return [
            'date' => $date,
            'service' => $service,
            'staged' => ['song_item_count' => $songItems, 'curation_tier' => $tier],
            'hymn_workbook' => $hymnEvidence ? ['statements' => 3] : null,
            'openlp' => $hymnEvidence ? ['item_key' => "{$date}-{$service}"] : null,
            'verdicts' => [
                'song_membership' => $verdict,
                'song_count' => $verdict,
                'song_order' => $verdict,
            ],
        ];
    }
}
