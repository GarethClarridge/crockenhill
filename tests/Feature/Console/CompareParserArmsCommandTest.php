<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Email\OosSourceFaithfulnessLabels;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OosParserProjectionFactory as Factory;
use Tests\TestCase;

class CompareParserArmsCommandTest extends TestCase
{
    private const Baseline = 'gpt-5.4-nano';

    private const Candidate = 'gpt-5.6-luna';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/arm-comparison-'.bin2hex(random_bytes(6));
        mkdir($root);
        $this->root = $root;
    }

    #[Test]
    public function without_truth_it_reports_discordance_and_writes_the_adjudication_worksheet(): void
    {
        [$baseline, $candidate] = $this->arms();
        $worksheet = "{$this->root}/worksheet.json";
        $output = "{$this->root}/comparison.json";

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $this->write('baseline.json', $baseline),
            '--candidate' => $this->write('candidate.json', $candidate),
            '--worksheet' => $worksheet,
            '--output' => $output,
        ])
            ->expectsOutputToContain('Raw discordance M (full scope): 1')
            ->expectsOutputToContain('No truth artifact was supplied')
            ->assertExitCode(0);

        $written = json_decode((string) file_get_contents($worksheet), true);

        $this->assertSame(OosSourceFaithfulnessLabels::Format, $written['format']);
        $this->assertSame(OosSourceFaithfulnessLabels::StatusWorksheet, $written['status']);
        $this->assertCount(1, $written['labels']);
        $this->assertSame('s1', $written['labels'][0]['source_key']);
        $this->assertNull($written['labels'][0]['verdict']);

        $report = json_decode((string) file_get_contents($output), true);
        $this->assertNull($report['decision']);
        $this->assertSame(CanonicalJson::hash($baseline), $report['inputs']['baseline_projection_sha256']);
    }

    #[Test]
    public function an_adjudicated_worksheet_produces_the_decision(): void
    {
        [$baseline, $candidate] = $this->arms();
        $output = "{$this->root}/decision.json";

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $this->write('baseline.json', $baseline),
            '--candidate' => $this->write('candidate.json', $candidate),
            '--truth' => $this->write('truth.json', $this->truth($baseline, $candidate, [
                's1' => ['verdict' => 'candidate_only_faithful', 'item_counts' => ['truth_items' => 2, 'baseline_supported_items' => 1, 'candidate_supported_items' => 2]],
            ])),
            '--price-snapshot' => $this->write('prices.json', ['models' => Factory::prices()]),
            '--output' => $output,
        ])
            ->expectsOutputToContain('candidate only 1 (b)')
            ->assertExitCode(0);

        $report = json_decode((string) file_get_contents($output), true);

        $this->assertSame(1, $report['primary']['adjudicated']['candidate_only_faithful']);
        $this->assertContains($report['decision'], ['adopt_candidate', 'stay_on_baseline']);
        $this->assertNotNull($report['inputs']['truth_sha256']);
        $this->assertNotNull($report['inputs']['price_snapshot_sha256']);
    }

    #[Test]
    public function a_validation_failure_writes_an_incomplete_diagnostic_and_no_result(): void
    {
        [$baseline] = $this->arms();
        $output = "{$this->root}/incomplete.json";

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $this->write('baseline.json', $baseline),
            '--candidate' => $this->write('same-model.json', Factory::projection('luna-none', self::Baseline, $baseline['raw_results'])),
            '--output' => $output,
        ])
            ->expectsOutputToContain('perfect, meaningless')
            ->assertExitCode(1);

        $written = json_decode((string) file_get_contents($output), true);

        $this->assertSame('incomplete', $written['status']);
        $this->assertArrayNotHasKey('decision', $written);
        $this->assertArrayNotHasKey('primary', $written);
    }

    #[Test]
    public function the_secondary_diagnostic_still_reports_transitions_when_both_artifacts_are_given(): void
    {
        [$baseline, $candidate] = $this->arms();

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $this->write('baseline.json', $baseline),
            '--candidate' => $this->write('candidate.json', $candidate),
            '--baseline-ground-truth' => $this->groundTruth('indeterminate'),
            '--candidate-ground-truth' => $this->groundTruth('match'),
        ])
            ->expectsOutputToContain('Secondary diagnostic — shared identities: 1')
            ->expectsOutputToContain('Total extraction failures fixed: 1')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_secondary_diagnostic_needs_both_artifacts_or_neither(): void
    {
        [$baseline, $candidate] = $this->arms();

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $this->write('baseline.json', $baseline),
            '--candidate' => $this->write('candidate.json', $candidate),
            '--baseline-ground-truth' => $this->groundTruth('match'),
        ])
            ->expectsOutputToContain('needs both ground-truth artifacts or neither')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_report(): void
    {
        [$baseline, $candidate] = $this->arms();
        $output = "{$this->root}/comparison.json";
        file_put_contents($output, 'already here');

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $this->write('baseline.json', $baseline),
            '--candidate' => $this->write('candidate.json', $candidate),
            '--output' => $output,
        ])
            ->expectsOutputToContain('Refusing to overwrite')
            ->assertExitCode(1);

        $this->assertSame('already here', file_get_contents($output));
    }

    #[Test]
    public function a_bare_artifact_name_resolves_inside_the_private_evaluation_root(): void
    {
        [$baseline, $candidate] = $this->arms();

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $this->write('baseline.json', $baseline),
            '--candidate' => $this->write('candidate.json', $candidate),
            '--truth' => 'a-truth-artifact-that-was-never-written.json',
        ])
            ->expectsOutputToContain(storage_path('scratch/oos-parser-evaluation/a-truth-artifact-that-was-never-written.json'))
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_when_an_arm_projection_is_missing(): void
    {
        [, $candidate] = $this->arms();

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => "{$this->root}/absent.json",
            '--candidate' => $this->write('candidate.json', $candidate),
        ])
            ->expectsOutputToContain('No baseline projection at')
            ->assertExitCode(1);
    }

    /**
     * Two sources: one the arms agree on, one where the candidate extracted an extra item.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function arms(): array
    {
        return [
            $this->asItWillBeRead(Factory::projection('baseline-nano-none', self::Baseline, [
                $this->source('s0', self::Baseline, ['Amazing Grace']),
                $this->source('s1', self::Baseline, ['Be Thou My Vision']),
            ])),
            $this->asItWillBeRead(Factory::projection('luna-none', self::Candidate, [
                $this->source('s0', self::Candidate, ['Amazing Grace']),
                $this->source('s1', self::Candidate, ['Be Thou My Vision', 'How Great Thou Art']),
            ])),
        ];
    }

    /**
     * A projection is hashed as the comparison reads it back off disk, and a JSON round trip turns
     * `0.0` into an integer. The truth artifact's binding must be computed against that same value,
     * so the test builds it the way the operator's worksheet would have.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function asItWillBeRead(array $projection): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) json_encode($projection, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  list<string>  $titles
     * @return array<string, mixed>
     */
    private function source(string $sourceKey, string $model, array $titles): array
    {
        return Factory::source($sourceKey, $model, [Factory::plan('morning', '2023-01-01', $titles)]);
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array<string, mixed>>  $labels
     * @return array<string, mixed>
     */
    private function truth(array $baseline, array $candidate, array $labels): array
    {
        $rows = [];

        foreach ($labels as $sourceKey => $label) {
            $rows[] = ['source_key' => $sourceKey, 'note' => null] + $label;
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
            'labels' => $rows,
        ];
    }

    private function groundTruth(string $verdict): string
    {
        return $this->write("ground-truth-{$verdict}.json", [
            'identities' => [[
                'date' => '2023-01-01',
                'service' => 'morning',
                'staged' => ['song_item_count' => 3, 'curation_tier' => 'full'],
                'hymn_workbook' => ['statements' => 3],
                'openlp' => ['item_key' => '2023-01-01-morning'],
                'verdicts' => [
                    'song_membership' => $verdict,
                    'song_count' => $verdict,
                    'song_order' => $verdict,
                ],
            ]],
        ]);
    }

    /** @param array<string, mixed> $contents */
    private function write(string $name, array $contents): string
    {
        $path = "{$this->root}/{$name}";
        file_put_contents($path, json_encode($contents, JSON_THROW_ON_ERROR));

        return $path;
    }
}
