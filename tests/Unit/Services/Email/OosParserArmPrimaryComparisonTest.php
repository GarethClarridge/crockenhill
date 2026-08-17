<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\OosParserArmPrimaryComparison;
use App\Services\Email\OosSourceFaithfulnessLabels;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\OosParserProjectionFactory as Factory;

class OosParserArmPrimaryComparisonTest extends TestCase
{
    private const Baseline = 'gpt-5.4-nano';

    private const Candidate = 'gpt-5.6-luna';

    private OosParserArmPrimaryComparison $comparison;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comparison = new OosParserArmPrimaryComparison;
    }

    // ---------------------------------------------------------------------------------------
    // Discordance and the worksheet
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function it_counts_raw_discordance_and_emits_a_worksheet_without_a_decision(): void
    {
        $report = $this->comparison->compare(
            $this->armProjection(self::Baseline, [
                $this->source('a', [['Amazing Grace']]),
                $this->source('b', [['Be Thou My Vision']]),
            ]),
            $this->armProjection(self::Candidate, [
                $this->source('a', [['Amazing Grace']]),
                $this->source('b', [['Be Thou My Vision', 'How Great Thou Art']]),
            ]),
        );

        $this->assertSame(2, $report['population']['n_primary']);
        $this->assertSame(1, $report['discordance']['m_primary']);
        $this->assertSame(1, $report['discordance']['m_primary_extraction']);
        $this->assertNull($report['primary']);
        $this->assertNull($report['decision']);
        $this->assertNull($report['guardrails']);

        $worksheet = $report['adjudication_worksheet'];
        $this->assertSame(OosSourceFaithfulnessLabels::StatusWorksheet, $worksheet['status']);
        $this->assertCount(1, $worksheet['labels']);
        $this->assertSame('b', $worksheet['labels'][0]['source_key']);
        $this->assertTrue($worksheet['labels'][0]['requires_item_counts']);
    }

    #[Test]
    public function the_labelling_threshold_reports_the_boundary_the_plan_sized_the_work_with(): void
    {
        $sources = [];

        for ($index = 0; $index < 500; $index++) {
            $sources[] = $this->source("s{$index}", [['Amazing Grace']]);
        }

        $report = $this->comparison->compare(
            $this->armProjection(self::Baseline, $sources),
            $this->armProjection(self::Candidate, $sources),
        );

        $threshold = $report['discordance']['labelling_threshold'];

        $this->assertSame(500, $threshold['n_primary']);
        $this->assertSame(83, $threshold['passes_at_a_tie_up_to']);
        $this->assertEqualsWithDelta(83.15, $threshold['value'], 0.05);
    }

    #[Test]
    public function routing_disagreement_alone_is_raw_discordance(): void
    {
        $held = $this->source('a', [['Amazing Grace']]);
        $held['raw_result']['service_plans'][0]['disposition'] = 'review_required';
        $held['raw_result']['service_plans'][0]['hold_reasons'] = ['low_confidence'];
        $held['raw_result']['disposition'] = 'review_required';
        $held['routing'] = [
            'category' => 'review_required',
            'auto_importable_plan_keys' => [],
            'importable_plan_keys' => ['morning:2023-01-01'],
        ];

        $report = $this->comparison->compare(
            $this->armProjection(self::Baseline, [$held]),
            $this->armProjection(self::Candidate, [$this->source('a', [['Amazing Grace']])]),
        );

        $this->assertSame(1, $report['discordance']['m_primary']);
        $this->assertSame(0, $report['discordance']['m_primary_extraction']);
        $this->assertSame(1, $report['discordance']['m_primary_routing_only']);
        $this->assertSame(1, $report['discordance']['routing_safety_adjudications']);
    }

    #[Test]
    public function a_confidence_difference_alone_is_not_extraction_discordance(): void
    {
        $baseline = $this->source('a', [['Amazing Grace']]);
        $candidate = $this->source('a', [['Amazing Grace']]);
        $candidate['raw_result']['service_plans'][0]['confidence'] = 0.42;
        $candidate['raw_result']['confidence'] = 0.42;

        $report = $this->comparison->compare(
            $this->armProjection(self::Baseline, [$baseline]),
            $this->armProjection(self::Candidate, [$candidate]),
        );

        $this->assertSame(0, $report['discordance']['m_primary']);
    }

    // ---------------------------------------------------------------------------------------
    // Fail-closed validation
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function a_one_sided_source_email_is_fatal(): void
    {
        $this->expectExceptionMessageMatches('/One-sided source emails make an arm incomplete/');

        $this->comparison->compare(
            $this->armProjection(self::Baseline, [$this->source('a', [['Amazing Grace']]), $this->source('b', [['Be Thou My Vision']])]),
            $this->armProjection(self::Candidate, [$this->source('a', [['Amazing Grace']])]),
        );
    }

    #[Test]
    public function a_one_sided_model_produced_service_is_scored_over_the_union_rather_than_refused(): void
    {
        $report = $this->comparison->compare(
            $this->armProjection(self::Baseline, [
                $this->source('a', [['Amazing Grace'], ['Abide With Me']], services: ['morning', 'evening']),
            ]),
            $this->armProjection(self::Candidate, [
                $this->source('a', [['Amazing Grace']]),
            ]),
        );

        $this->assertSame(1, $report['discordance']['m_primary']);

        $diff = $report['adjudication_worksheet']['labels'][0]['plan_diff'];
        $eveningDiff = array_values(array_filter($diff, static fn (array $row): bool => $row['plan_key'] === 'evening:2023-01-01'));

        $this->assertCount(1, $eveningDiff);
        $this->assertNotNull($eveningDiff[0]['baseline']);
        $this->assertNull($eveningDiff[0]['candidate'], 'A service the candidate never produced must be scored as an explicit absence.');
    }

    #[Test]
    public function curated_drift_between_the_arms_is_fatal(): void
    {
        $drifted = $this->source('a', [['Amazing Grace']]);
        $drifted['curation']['ground_truth_date'] = '2023-02-05';

        $this->expectExceptionMessageMatches('/the fixed inputs drifted/');

        $this->comparison->compare(
            $this->armProjection(self::Baseline, [$this->source('a', [['Amazing Grace']])]),
            $this->armProjection(self::Candidate, [$drifted]),
        );
    }

    #[Test]
    public function two_arms_that_ran_the_same_model_are_fatal(): void
    {
        $this->expectExceptionMessageMatches("/perfect, meaningless 'no difference'/");

        $this->comparison->compare(
            $this->armProjection(self::Baseline, [$this->source('a', [['Amazing Grace']])]),
            $this->armProjection(self::Baseline, [$this->source('a', [['Amazing Grace']])], arm: 'luna-none'),
        );
    }

    #[Test]
    public function an_arm_that_does_not_reproduce_its_own_source_key_list_hash_is_fatal(): void
    {
        $this->expectExceptionMessageMatches('/do not reproduce its own source-key list hash/');

        $this->comparison->compare(
            $this->armProjection(self::Baseline, [$this->source('a', [['Amazing Grace']])], overrides: ['source_key_list_hash' => 'not-the-hash']),
            $this->armProjection(self::Candidate, [$this->source('a', [['Amazing Grace']])]),
        );
    }

    #[Test]
    public function an_unknown_curation_tier_never_defaults_to_full(): void
    {
        $unknown = $this->source('a', [['Amazing Grace']]);
        $unknown['curation']['content_scope'] = 'unknown';

        $this->expectExceptionMessageMatches('/never treated as full/');

        $this->comparison->compare(
            $this->armProjection(self::Baseline, [$unknown]),
            $this->armProjection(self::Candidate, [$unknown]),
        );
    }

    #[Test]
    public function a_call_with_no_usage_record_is_fatal(): void
    {
        $unmetered = $this->source('a', [['Amazing Grace']]);
        $unmetered['telemetry'][0]['usage_missing'] = true;

        $this->expectExceptionMessageMatches('/cannot be costed/');

        $this->comparison->compare(
            $this->armProjection(self::Baseline, [$unmetered]),
            $this->armProjection(self::Candidate, [$this->source('a', [['Amazing Grace']])]),
        );
    }

    #[Test]
    public function a_retry_recorded_twice_under_one_attempt_number_is_fatal(): void
    {
        $duplicated = $this->source('a', [['Amazing Grace']]);
        $duplicated['telemetry'][] = Factory::call('a', self::Baseline);

        $this->expectExceptionMessageMatches('/would be counted as a second source/');

        $this->comparison->compare(
            $this->armProjection(self::Baseline, [$duplicated]),
            $this->armProjection(self::Candidate, [$this->source('a', [['Amazing Grace']])]),
        );
    }

    #[Test]
    public function a_version_one_projection_without_routing_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/reads version 2 only/');

        $this->comparison->compare(
            $this->armProjection(self::Baseline, [$this->source('a', [['Amazing Grace']])], overrides: ['version' => 1]),
            $this->armProjection(self::Candidate, [$this->source('a', [['Amazing Grace']])]),
        );
    }

    // ---------------------------------------------------------------------------------------
    // The adjudicated decision
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function an_unlabelled_discordant_source_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/would be optional stopping/');

        $this->decide(
            baselineTitles: [['Amazing Grace'], ['Be Thou My Vision']],
            candidateTitles: [['Amazing Grace'], ['Be Thou My Vision', 'How Great Thou Art']],
            verdicts: [],
        );
    }

    #[Test]
    public function a_label_on_a_concordant_source_is_refused_as_a_hand_picked_sample(): void
    {
        $this->expectExceptionMessageMatches('/reintroduces the selection this design removed/');

        $this->decide(
            baselineTitles: [['Amazing Grace'], ['Be Thou My Vision']],
            candidateTitles: [['Amazing Grace'], ['Be Thou My Vision', 'How Great Thou Art']],
            verdicts: ['s0' => 'both_faithful', 's1' => 'candidate_only_faithful'],
        );
    }

    #[Test]
    public function parity_passes_and_the_bound_is_taken_at_the_worst_case_allocation(): void
    {
        $report = $this->decideWide(candidateWins: 20, baselineWins: 20, concordant: 460);

        $this->assertSame(20, $report['primary']['adjudicated']['candidate_only_faithful']);
        $this->assertSame(20, $report['primary']['adjudicated']['baseline_only_faithful']);
        $this->assertSame(0.0, $report['primary']['point_difference']);
        $this->assertSame(460, $report['primary']['unlabelled_concordant']);
        $this->assertGreaterThan(-0.03, $report['primary']['lower_one_sided_95']);
        $this->assertTrue($report['primary']['passes']);
        $this->assertSame('adopt_candidate', $report['decision']);
    }

    #[Test]
    public function an_exact_tie_too_wide_for_the_corpus_does_not_pass_on_the_tie_alone(): void
    {
        // b = c = 8 at N = 100 is 16 discordant sources against a threshold of 3: a tie is not a
        // pass in itself, only a tie the corpus is large enough to resolve.
        $report = $this->decideWide(candidateWins: 8, baselineWins: 8, concordant: 84);

        $this->assertSame(0.0, $report['primary']['point_difference']);
        $this->assertFalse($report['discordance']['labelling_threshold']['m_primary_within_threshold']);
        $this->assertFalse($report['primary']['passes']);
        $this->assertSame('stay_on_baseline', $report['decision']);
    }

    #[Test]
    public function a_regression_beyond_the_margin_keeps_the_baseline(): void
    {
        $report = $this->decideWide(candidateWins: 0, baselineWins: 20, concordant: 80);

        $this->assertEqualsWithDelta(-0.2, $report['primary']['point_difference'], 0.000001);
        $this->assertFalse($report['primary']['passes']);
        $this->assertSame('stay_on_baseline', $report['decision']);
    }

    #[Test]
    public function an_unusable_parse_in_both_arms_counts_as_source_incorrect_not_as_a_missing_observation(): void
    {
        $unusable = $this->source('s1', []);

        $report = $this->decide(
            baselineTitles: [['Amazing Grace']],
            candidateTitles: [['Amazing Grace']],
            verdicts: [],
            extraBaseline: [$unusable],
            extraCandidate: [$unusable],
        );

        $this->assertSame(1, $report['primary']['concordant_unusable_in_both']);
        $this->assertSame(1, $report['primary']['unlabelled_concordant']);
        $this->assertSame(0, $report['primary']['adjudicated']['neither_faithful']);
    }

    // ---------------------------------------------------------------------------------------
    // Guardrails
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function a_candidate_only_auto_import_of_an_incorrect_plan_fails_the_arm(): void
    {
        $held = $this->source('s0', [['Amazing Grace']]);
        $held['raw_result']['service_plans'][0]['disposition'] = 'review_required';
        $held['raw_result']['service_plans'][0]['hold_reasons'] = ['low_confidence'];
        $held['raw_result']['disposition'] = 'review_required';
        $held['routing'] = [
            'category' => 'review_required',
            'auto_importable_plan_keys' => [],
            'importable_plan_keys' => ['morning:2023-01-01'],
        ];

        $report = $this->decideOn(
            $this->armProjection(self::Baseline, [$held]),
            $this->armProjection(self::Candidate, [$this->source('s0', [['Amazing Grace']])]),
            ['s0' => 'neither_faithful'],
        );

        $routing = $this->guardrail($report, 'routing_safety');

        $this->assertSame('fail', $routing['status']);
        $this->assertSame(1, $routing['detail']['candidate_only_auto_importable']);
        $this->assertCount(1, $routing['detail']['candidate_only_false_auto_imports']);
        $this->assertSame('stay_on_baseline', $report['decision']);
    }

    #[Test]
    public function a_hold_reason_that_escaped_into_an_auto_importable_plan_is_a_safety_breach(): void
    {
        $escaped = $this->source('s0', [['Amazing Grace']]);
        $escaped['raw_result']['service_plans'][0]['hold_reasons'] = ['missing_identity'];

        $report = $this->decideOn(
            $this->armProjection(self::Baseline, [$this->source('s0', [['Amazing Grace']])]),
            $this->armProjection(self::Candidate, [$escaped]),
            [],
        );

        $safety = $this->guardrail($report, 'safety');

        $this->assertSame('fail', $safety['status']);
        $this->assertSame(1, $safety['detail']['candidate_escaped_hold_count']);
        $this->assertSame(0, $safety['detail']['baseline_escaped_hold_count']);
        $this->assertSame('stay_on_baseline', $report['decision']);
    }

    #[Test]
    public function a_fall_in_review_share_beyond_the_band_is_not_silently_favourable(): void
    {
        $baseline = [];
        $candidate = [];

        for ($index = 0; $index < 20; $index++) {
            $held = $this->source("s{$index}", [['Amazing Grace']]);

            if ($index < 4) {
                $held['raw_result']['service_plans'][0]['disposition'] = 'review_required';
                $held['raw_result']['service_plans'][0]['hold_reasons'] = ['low_confidence'];
                $held['raw_result']['disposition'] = 'review_required';
                $held['routing'] = [
                    'category' => 'review_required',
                    'auto_importable_plan_keys' => [],
                    'importable_plan_keys' => ['morning:2023-01-01'],
                ];
            }

            $baseline[] = $held;
            $candidate[] = $this->source("s{$index}", [['Amazing Grace']]);
        }

        $verdicts = [];

        for ($index = 0; $index < 4; $index++) {
            $verdicts["s{$index}"] = 'both_faithful';
        }

        $report = $this->decideOn(
            $this->armProjection(self::Baseline, $baseline),
            $this->armProjection(self::Candidate, $candidate),
            $verdicts,
        );

        $review = $this->guardrail($report, 'review_burden');

        $this->assertSame('fail', $review['status']);
        $this->assertEqualsWithDelta(-0.2, $review['detail']['change'], 0.000001);
        $this->assertSame('fewer plans held for review', $review['detail']['direction']);
    }

    #[Test]
    public function a_decision_run_without_a_price_snapshot_is_refused(): void
    {
        $baseline = $this->armProjection(self::Baseline, [$this->source('s0', [['Amazing Grace']])]);
        $candidate = $this->armProjection(self::Candidate, [$this->source('s0', [['Amazing Grace']])]);

        $this->expectExceptionMessageMatches('/would pass by omission/');

        $this->comparison->compare($baseline, $candidate, $this->truth($baseline, $candidate, []));
    }

    #[Test]
    public function asymmetric_within_arm_instability_refuses_inference(): void
    {
        $report = $this->decideOn(
            $this->armProjection(self::Baseline, [$this->source('s0', [['Amazing Grace']])]),
            $this->armProjection(
                self::Candidate,
                [$this->source('s0', [['Amazing Grace']])],
                overrides: ['stability' => ['sample_size' => 30, 'self_disagreements' => 9, 'rate' => 0.3]],
            ),
            [],
        );

        $this->assertSame('inference_refused', $report['stability']['consequence']);
        $this->assertSame('no_conclusion', $report['decision']);
    }

    #[Test]
    public function item_recall_needs_counts_for_every_extraction_discordant_source(): void
    {
        $this->expectExceptionMessageMatches('/Item recall cannot be bounded without them/');

        $this->decide(
            baselineTitles: [['Amazing Grace'], ['Be Thou My Vision']],
            candidateTitles: [['Amazing Grace'], ['Be Thou My Vision', 'How Great Thou Art']],
            verdicts: ['s1' => 'candidate_only_faithful'],
        );
    }

    #[Test]
    public function item_recall_is_source_normalised_and_zero_wherever_the_arms_agree(): void
    {
        $report = $this->decide(
            baselineTitles: [['Amazing Grace'], ['Be Thou My Vision']],
            candidateTitles: [['Amazing Grace'], ['Be Thou My Vision', 'How Great Thou Art']],
            verdicts: ['s1' => 'candidate_only_faithful'],
            itemCounts: ['s1' => ['truth_items' => 2, 'baseline_supported_items' => 1, 'candidate_supported_items' => 2]],
        );

        $recall = $this->guardrail($report, 'item_recall');

        // Two sources: the concordant one contributes an exact zero and the discordant one +1/2.
        $this->assertSame(2, $recall['detail']['population']);
        $this->assertEqualsWithDelta(0.25, $recall['detail']['mean_source_normalised_difference'], 0.000001);
    }

    #[Test]
    public function item_recall_passes_on_a_corpus_large_enough_to_bound_it(): void
    {
        $recall = $this->guardrail(
            $this->decideWide(candidateWins: 20, baselineWins: 20, concordant: 460),
            'item_recall',
        );

        $this->assertSame(500, $recall['detail']['population']);
        $this->assertEqualsWithDelta(0.0, $recall['detail']['mean_source_normalised_difference'], 0.000001);
        $this->assertGreaterThan(-0.05, $recall['detail']['lower_one_sided_95']);
        $this->assertSame('pass', $recall['status']);
    }

    // ---------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function armProjection(string $model, array $sources, ?string $arm = null, array $overrides = []): array
    {
        $arm ??= $model === self::Baseline ? 'baseline-nano-none' : 'luna-none';

        $sources = array_map(
            static function (array $source) use ($model): array {
                $source['telemetry'] = array_map(
                    static fn (array $call): array => ['response_model' => $model] + $call,
                    $source['telemetry'],
                );

                return $source;
            },
            $sources,
        );

        return Factory::projection($arm, $model, $sources, $overrides);
    }

    /**
     * @param  list<list<string>>  $plans  one item-title list per service
     * @param  list<string>  $services
     * @return array<string, mixed>
     */
    private function source(string $sourceKey, array $plans, array $services = ['morning']): array
    {
        $built = [];

        foreach (array_values($plans) as $index => $titles) {
            $built[] = Factory::plan($services[$index] ?? 'other', '2023-01-01', $titles, [
                'source_provenance' => [
                    'plan_index' => $index,
                    'rejected_service' => null,
                    'service_evidence_line_ids' => [1],
                    'structural_findings' => [],
                    'items' => array_map(
                        static fn (int $position): array => ['position' => $position + 1, 'source_line_ids' => [$position + 2], 'continuation' => false],
                        array_keys($titles),
                    ),
                ],
            ]);
        }

        return Factory::source($sourceKey, self::Baseline, $built);
    }

    /**
     * @param  list<list<string>>  $baselineTitles
     * @param  list<list<string>>  $candidateTitles
     * @param  array<string, string>  $verdicts
     * @param  array<string, array{truth_items:int,baseline_supported_items:int,candidate_supported_items:int}>  $itemCounts
     * @param  list<array<string, mixed>>  $extraBaseline
     * @param  list<array<string, mixed>>  $extraCandidate
     * @return array<string, mixed>
     */
    private function decide(
        array $baselineTitles,
        array $candidateTitles,
        array $verdicts,
        array $itemCounts = [],
        array $extraBaseline = [],
        array $extraCandidate = [],
    ): array {
        $baseline = [];
        $candidate = [];

        foreach ($baselineTitles as $index => $titles) {
            $baseline[] = $this->source("s{$index}", [$titles]);
            $candidate[] = $this->source("s{$index}", [$candidateTitles[$index]]);
        }

        return $this->decideOn(
            $this->armProjection(self::Baseline, [...$baseline, ...$extraBaseline]),
            $this->armProjection(self::Candidate, [...$candidate, ...$extraCandidate]),
            $verdicts,
            $itemCounts,
        );
    }

    /**
     * A corpus where the two arms disagree on a declared number of sources, split by who was right.
     *
     * @return array<string, mixed>
     */
    private function decideWide(int $candidateWins, int $baselineWins, int $concordant): array
    {
        $baseline = [];
        $candidate = [];
        $verdicts = [];
        $itemCounts = [];
        $index = 0;

        foreach (['candidate_only_faithful' => $candidateWins, 'baseline_only_faithful' => $baselineWins] as $verdict => $count) {
            for ($taken = 0; $taken < $count; $taken++, $index++) {
                $key = "s{$index}";
                $baseline[] = $this->source($key, [['Amazing Grace']]);
                $candidate[] = $this->source($key, [['Amazing Grace', 'How Great Thou Art']]);
                $verdicts[$key] = $verdict;
                $itemCounts[$key] = $verdict === 'candidate_only_faithful'
                    ? ['truth_items' => 2, 'baseline_supported_items' => 1, 'candidate_supported_items' => 2]
                    : ['truth_items' => 2, 'baseline_supported_items' => 2, 'candidate_supported_items' => 1];
            }
        }

        for ($taken = 0; $taken < $concordant; $taken++, $index++) {
            $key = "s{$index}";
            $baseline[] = $this->source($key, [['Amazing Grace']]);
            $candidate[] = $this->source($key, [['Amazing Grace']]);
        }

        return $this->decideOn(
            $this->armProjection(self::Baseline, $baseline),
            $this->armProjection(self::Candidate, $candidate),
            $verdicts,
            $itemCounts,
        );
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @param  array<string, string>  $verdicts
     * @param  array<string, array{truth_items:int,baseline_supported_items:int,candidate_supported_items:int}>  $itemCounts
     * @return array<string, mixed>
     */
    private function decideOn(array $baseline, array $candidate, array $verdicts, array $itemCounts = []): array
    {
        return $this->comparison->compare(
            $baseline,
            $candidate,
            $this->truth($baseline, $candidate, $verdicts, $itemCounts),
            Factory::prices(),
            'price-hash',
        );
    }

    /**
     * The binding is recomputed from the two projections exactly as the comparison does, so a test
     * cannot accidentally prove that labels bind when they were read against different output.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @param  array<string, string>  $verdicts
     * @param  array<string, array{truth_items:int,baseline_supported_items:int,candidate_supported_items:int}>  $itemCounts
     * @return array<string, mixed>
     */
    private function truth(array $baseline, array $candidate, array $verdicts, array $itemCounts = []): array
    {
        $labels = [];

        foreach ($verdicts as $sourceKey => $verdict) {
            $labels[] = [
                'source_key' => $sourceKey,
                'verdict' => $verdict,
                'item_counts' => $itemCounts[$sourceKey] ?? null,
                'note' => null,
            ];
        }

        return [
            'format' => OosSourceFaithfulnessLabels::Format,
            'version' => OosSourceFaithfulnessLabels::Version,
            'status' => OosSourceFaithfulnessLabels::StatusAdjudicated,
            'binding' => [
                'baseline_arm' => $baseline['arm'],
                'candidate_arm' => $candidate['arm'],
                'baseline_model' => $baseline['model'],
                'candidate_model' => $candidate['model'],
                'source_key_list_hash' => $baseline['source_key_list_hash'],
                'baseline_projection_sha256' => CanonicalJson::hash($baseline),
                'candidate_projection_sha256' => CanonicalJson::hash($candidate),
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function guardrail(array $report, string $name): array
    {
        /** @var list<array<string, mixed>> $guardrails */
        $guardrails = $report['guardrails'];

        foreach ($guardrails as $guardrail) {
            if ($guardrail['name'] === $name) {
                return $guardrail;
            }
        }

        throw new RuntimeException("The report carries no {$name} guardrail.");
    }
}
