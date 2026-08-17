<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosSourceFaithfulnessVerdict;
use App\Services\Email\OosSourceFaithfulnessLabels;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OosSourceFaithfulnessLabelsTest extends TestCase
{
    #[Test]
    public function the_emitted_worksheet_carries_null_verdicts_and_the_full_vocabulary(): void
    {
        $worksheet = OosSourceFaithfulnessLabels::worksheet($this->binding(), [
            ['source_key' => 'source-a', 'requires_item_counts' => true],
        ]);

        $this->assertSame(OosSourceFaithfulnessLabels::Format, $worksheet['format']);
        $this->assertSame(OosSourceFaithfulnessLabels::Version, $worksheet['version']);
        $this->assertSame(OosSourceFaithfulnessLabels::StatusWorksheet, $worksheet['status']);
        $this->assertNull($worksheet['labels'][0]['verdict']);
        $this->assertNull($worksheet['labels'][0]['item_counts']);
        $this->assertSame('source-a', $worksheet['labels'][0]['source_key']);
        $this->assertSame(
            ['both_faithful', 'baseline_only_faithful', 'candidate_only_faithful', 'neither_faithful'],
            $worksheet['verdict_vocabulary'],
        );
    }

    #[Test]
    public function an_adjudicated_worksheet_reads_back_as_verdicts(): void
    {
        $labels = OosSourceFaithfulnessLabels::fromArtifact(
            $this->adjudicated([
                ['source_key' => 'source-a', 'verdict' => 'candidate_only_faithful'],
                [
                    'source_key' => 'source-b',
                    'verdict' => 'neither_faithful',
                    'item_counts' => ['truth_items' => 8, 'baseline_supported_items' => 6, 'candidate_supported_items' => 7],
                ],
            ]),
            $this->binding(),
        );

        $this->assertSame(OosSourceFaithfulnessVerdict::CandidateOnlyFaithful, $labels->verdictFor('source-a'));
        $this->assertSame(OosSourceFaithfulnessVerdict::NeitherFaithful, $labels->verdictFor('source-b'));
        $this->assertNull($labels->verdictFor('source-c'));
        $this->assertSame(['source-a', 'source-b'], $labels->sourceKeys());
        $this->assertSame(8, $labels->itemCountsFor('source-b')['truth_items']);
        $this->assertNull($labels->itemCountsFor('source-a'));
    }

    #[Test]
    public function an_unfinished_worksheet_is_refused(): void
    {
        $artifact = $this->adjudicated([['source_key' => 'source-a', 'verdict' => 'both_faithful']]);
        $artifact['status'] = OosSourceFaithfulnessLabels::StatusWorksheet;

        $this->expectExceptionMessageMatches('/still a worksheet/');

        OosSourceFaithfulnessLabels::fromArtifact($artifact, $this->binding());
    }

    #[Test]
    public function a_missing_verdict_is_refused_rather_than_treated_as_concordant(): void
    {
        $this->expectExceptionMessageMatches('/a partial label set cannot decide the comparison/');

        OosSourceFaithfulnessLabels::fromArtifact(
            $this->adjudicated([
                ['source_key' => 'source-a', 'verdict' => 'both_faithful'],
                ['source_key' => 'source-b', 'verdict' => null],
            ]),
            $this->binding(),
        );
    }

    #[Test]
    public function an_unknown_verdict_never_falls_back_to_a_default(): void
    {
        $this->expectExceptionMessageMatches("/unknown verdict 'candidate_better'/");

        OosSourceFaithfulnessLabels::fromArtifact(
            $this->adjudicated([['source_key' => 'source-a', 'verdict' => 'candidate_better']]),
            $this->binding(),
        );
    }

    #[Test]
    public function labels_adjudicated_against_a_different_run_are_refused(): void
    {
        $this->expectExceptionMessageMatches('/adjudicated against a different run/');

        OosSourceFaithfulnessLabels::fromArtifact(
            $this->adjudicated([['source_key' => 'source-a', 'verdict' => 'both_faithful']]),
            ['candidate_projection_sha256' => 'a-rerun-that-produced-different-output'] + $this->binding(),
        );
    }

    #[Test]
    public function a_later_format_version_is_refused_rather_than_read_optimistically(): void
    {
        $artifact = $this->adjudicated([['source_key' => 'source-a', 'verdict' => 'both_faithful']]);
        $artifact['version'] = OosSourceFaithfulnessLabels::Version + 1;

        $this->expectExceptionMessageMatches('/reads version 1 only/');

        OosSourceFaithfulnessLabels::fromArtifact($artifact, $this->binding());
    }

    #[Test]
    public function a_source_labelled_twice_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/labelled more than once/');

        OosSourceFaithfulnessLabels::fromArtifact(
            $this->adjudicated([
                ['source_key' => 'source-a', 'verdict' => 'both_faithful'],
                ['source_key' => 'source-a', 'verdict' => 'neither_faithful'],
            ]),
            $this->binding(),
        );
    }

    #[Test]
    public function item_counts_that_exceed_the_email_are_refused(): void
    {
        $this->expectExceptionMessageMatches('/supports more items/');

        OosSourceFaithfulnessLabels::fromArtifact(
            $this->adjudicated([[
                'source_key' => 'source-a',
                'verdict' => 'candidate_only_faithful',
                'item_counts' => ['truth_items' => 4, 'baseline_supported_items' => 2, 'candidate_supported_items' => 9],
            ]]),
            $this->binding(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $labels
     * @return array<string, mixed>
     */
    private function adjudicated(array $labels): array
    {
        return [
            'format' => OosSourceFaithfulnessLabels::Format,
            'version' => OosSourceFaithfulnessLabels::Version,
            'status' => OosSourceFaithfulnessLabels::StatusAdjudicated,
            'binding' => $this->binding(),
            'labels' => $labels,
        ];
    }

    /** @return array<string, string> */
    private function binding(): array
    {
        return [
            'baseline_arm' => 'baseline-nano-none',
            'candidate_arm' => 'luna-none',
            'baseline_model' => 'gpt-5.4-nano',
            'candidate_model' => 'gpt-5.6-luna',
            'source_key_list_hash' => 'source-key-list-hash',
            'baseline_projection_sha256' => 'baseline-projection-hash',
            'candidate_projection_sha256' => 'candidate-projection-hash',
        ];
    }
}
