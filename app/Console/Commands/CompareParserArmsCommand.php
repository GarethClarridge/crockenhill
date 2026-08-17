<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ItemGroundTruthArmComparison;
use App\Services\Email\OosParserArmPrimaryComparison;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Decide the OoS parser model evaluation from two frozen arms.
 *
 * Two questions are answered here and they are not equals:
 *
 * - The **primary** is source-faithful extraction — did the arm reproduce what the email itself
 *   states — read from each arm's raw-result projection and tested against a declared
 *   non-inferiority margin. Run without `--truth` it enumerates the raw-discordant sources and
 *   emits the adjudication worksheet, and emits no decision label at all. Run with an adjudicated
 *   worksheet it produces the decision.
 * - The **secondary** is agreement with the hymn workbook and the OpenLP decks, over the item
 *   ground-truth artifacts, and only when both are supplied. It is descriptive: a service can change
 *   after the email was written, so a disagreement there is not automatically a parser error, it
 *   carries no p-values, and it never adopts or rejects an arm.
 *
 * Read-only. It opens artifacts and writes reports, and it never touches the parser, the corpus or
 * production configuration.
 *
 * Delete once the Luna adoption report is accepted and no rerun remains, or at historic-import IC8
 * closeout at the latest.
 */
class CompareParserArmsCommand extends Command
{
    private const EvaluationRoot = 'scratch/oos-parser-evaluation';

    private const ProjectionFile = 'raw-result-projection.json';

    private const IncompleteFormat = 'crockenhill-oos-parser-arm-comparison-incomplete';

    protected $signature = 'service-tracking:compare-ground-truth-arms
        {--baseline= : Baseline arm run-directory name, or an absolute path to its raw-result projection}
        {--candidate= : Candidate arm run-directory name, or an absolute path to its raw-result projection}
        {--truth= : Adjudicated source-faithfulness label artifact; without it no decision label is emitted}
        {--worksheet= : Absolute path the adjudication worksheet is created at}
        {--output= : Absolute path the comparison report is created at}
        {--baseline-ground-truth= : Baseline item ground-truth artifact for the secondary diagnostic}
        {--candidate-ground-truth= : Candidate item ground-truth artifact for the secondary diagnostic}
        {--examples=10 : How many changed identities to record per secondary dimension}';

    protected $description = 'Decide the OoS parser evaluation: source-level non-inferiority, guardrails and secondary diagnostics';

    public function handle(OosParserArmPrimaryComparison $primary, ItemGroundTruthArmComparison $secondary): int
    {
        $output = $this->absoluteOption('output');

        try {
            $truthPath = $this->resolvedPath($this->option('truth'), 'truth artifact');

            $report = $primary->compare(
                $this->readArtifact($this->projectionPath('baseline'), 'baseline projection'),
                $this->readArtifact($this->projectionPath('candidate'), 'candidate projection'),
                $truthPath === null ? null : $this->readArtifact($truthPath, 'truth artifact'),
            );

            $secondaryReport = $this->secondary($secondary);
            $report['secondary_diagnostic'] = $secondaryReport;

            $this->renderPrimary($report);
            $this->writeWorksheet($report);

            if ($secondaryReport !== null) {
                $this->renderSecondary($secondaryReport);
            }

            if ($output !== null) {
                $this->createOnce($output, $report);
                $this->line("Report: {$output}");
            }

            $this->line('Report sha256: '.CanonicalJson::hash($report));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->writeIncomplete($output, $exception);

            return self::FAILURE;
        }
    }

    /**
     * A validation failure emits an `incomplete` diagnostic and never an inferential result.
     *
     * The failure modes this command raises on — a one-sided source, curated drift, an unlabelled
     * discordant source, both arms served the same model — all mean the two artifacts do not
     * measure the same thing. A partial report written at that point would be quoted later as if it
     * did, so the artifact that lands says only what went wrong.
     */
    private function writeIncomplete(?string $output, Throwable $exception): void
    {
        if ($output === null || file_exists($output)) {
            return;
        }

        try {
            $this->createOnce($output, [
                'format' => self::IncompleteFormat,
                'version' => 1,
                'status' => 'incomplete',
                'reason' => $exception->getMessage(),
                'note' => 'No rate, interval, guardrail or decision label was computed. The inputs did not '
                    .'pass validation, so nothing in this file may be read as a result.',
            ]);
            $this->line("Incomplete diagnostic: {$output}");
        } catch (Throwable) {
            // The failure already reported is the one worth surfacing.
        }
    }

    /** @param array<string, mixed> $report */
    private function renderPrimary(array $report): void
    {
        /** @var array<string, mixed> $arms */
        $arms = $report['arms'];
        /** @var array<string, mixed> $baselineArm */
        $baselineArm = $arms['baseline'];
        /** @var array<string, mixed> $candidateArm */
        $candidateArm = $arms['candidate'];

        $this->info("Baseline {$baselineArm['arm']} ({$baselineArm['model']}) vs candidate {$candidateArm['arm']} ({$candidateArm['model']})");

        /** @var array<string, mixed> $inputs */
        $inputs = $report['inputs'];
        $this->line("Parser surface: {$inputs['parser_surface_hash']} (identical in both arms, or this comparison would have refused)");
        $this->line('Application commit: '.($inputs['application_commit'] ?? 'not determinable'));

        /** @var array<string, mixed> $population */
        $population = $report['population'];
        $this->line("Sources: {$population['sources']}, N_primary (full scope): {$population['n_primary']}");

        /** @var array<string, mixed> $stability */
        $stability = $report['stability'];
        $this->line(sprintf(
            'Within-arm self-disagreement: baseline %.1f%%, candidate %.1f%% → %s',
            $stability['baseline_self_disagreement'] * 100,
            $stability['candidate_self_disagreement'] * 100,
            $stability['consequence'],
        ));

        /** @var array<string, mixed> $discordance */
        $discordance = $report['discordance'];
        /** @var array<string, mixed> $threshold */
        $threshold = $discordance['labelling_threshold'];
        $this->line("Raw discordance M (full scope): {$discordance['m_primary']} ".
            "(extraction {$discordance['m_primary_extraction']}, routing only {$discordance['m_primary_routing_only']}); ".
            "all tiers {$discordance['m_all_tiers']}");
        $this->line("Labelling threshold: {$threshold['value']} — a tie passes up to {$threshold['passes_at_a_tie_up_to']}. ".
            'M is not b + c: it bounds the labelling work, it does not decide anything.');
        $this->line("Routing-safety adjudications required: {$discordance['routing_safety_adjudications']}");
        $this->newLine();

        if ($report['primary'] === null) {
            $this->warn((string) $report['decision_note']);

            return;
        }

        /** @var array<string, mixed> $primary */
        $primary = $report['primary'];
        /** @var array<string, int> $adjudicated */
        $adjudicated = $primary['adjudicated'];

        $this->line("Adjudicated: both {$adjudicated['both_faithful']}, candidate only {$adjudicated['candidate_only_faithful']} (b), ".
            "baseline only {$adjudicated['baseline_only_faithful']} (c), neither {$adjudicated['neither_faithful']}");
        $this->line(sprintf(
            'Difference (candidate − baseline): %+.2fpp, lower one-sided 95%% bound %+.2fpp against a %+.2fpp margin',
            $primary['point_difference'] * 100,
            $primary['lower_one_sided_95'] * 100,
            $primary['margin'] * 100,
        ));
        $this->newLine();

        /** @var list<array<string, mixed>> $guardrails */
        $guardrails = $report['guardrails'];
        $this->table(
            ['Guardrail', 'Hard', 'Status'],
            array_map(
                static fn (array $guardrail): array => [
                    (string) $guardrail['name'],
                    $guardrail['hard'] === true ? 'yes' : 'no',
                    (string) $guardrail['status'],
                ],
                $guardrails,
            ),
        );

        $decision = (string) $report['decision'];
        $decision === 'adopt_candidate'
            ? $this->info("Decision: {$decision} — {$report['decision_note']}")
            : $this->warn("Decision: {$decision} — {$report['decision_note']}");
    }

    /**
     * The secondary diagnostic, printed as it was before the primary joined this command: paired
     * transition matrices per dimension, no combined score and no p-values.
     *
     * @param  array<string, mixed>  $report
     */
    private function renderSecondary(array $report): void
    {
        $this->newLine();
        $this->info("Secondary diagnostic — shared identities: {$report['shared_identities']} ".
            "(baseline only {$report['baseline_only_identities']}, candidate only {$report['candidate_only_identities']})");
        $this->line('<comment>Descriptive only.</comment> These dimensions ask whether the email plan agrees with '
            .'what was later sung or projected, which a service can change after the email was written.');
        $this->newLine();

        /** @var array<string, array<string, mixed>> $dimensions */
        $dimensions = $report['dimensions'];

        foreach ($dimensions as $dimension => $result) {
            $this->line("<comment>{$dimension}</comment> (evidence: {$result['evidence']}, ".
                "tier: {$result['tier']}, population: {$result['population']})");

            /** @var array<string, int> $withheld */
            $withheld = $result['withheld_by_tier'];

            if ($withheld !== []) {
                $this->line('  Withheld as not model-addressable: '.implode(', ', array_map(
                    static fn (string $tier, int $count): string => "{$tier} {$count}",
                    array_keys($withheld),
                    array_values($withheld),
                )));
            }

            if ($result['evidence_drift_identities'] > 0) {
                $this->error("{$result['evidence_drift_identities']} identities differ in evidence availability between arms; ".
                    'the two artifacts were not built against the same evidence and this comparison is not sound.');
            }

            if ($result['tier_drift_identities'] > 0) {
                $this->error("{$result['tier_drift_identities']} identities differ in curation tier between arms; ".
                    'the corpus was re-curated between them and the two artifacts do not measure the same sources.');
            }

            /** @var array<string, array<string, int>> $transitions */
            $transitions = $result['transitions'];

            $this->table(
                ['Baseline \\ Candidate', 'match', 'mismatch', 'indeterminate', 'circular'],
                array_map(
                    static fn (int|string $from, array $row): array => [
                        (string) $from,
                        (string) $row['match'],
                        (string) $row['mismatch'],
                        (string) $row['indeterminate'],
                        (string) $row['circular'],
                    ],
                    array_keys($transitions),
                    array_values($transitions),
                ),
            );

            $this->line("Total extraction failures fixed: {$result['total_extraction_failures_fixed']}, ".
                "introduced: {$result['total_extraction_failures_introduced']}");

            /** @var array<string, array<string, mixed>> $matchRate */
            $matchRate = $result['match_rate'];
            $this->line('Match rate: '.$this->formatRate($matchRate['baseline']).' → '.$this->formatRate($matchRate['candidate']));

            /** @var array<string, mixed> $discordance */
            $discordance = $result['discordance'];
            $this->line("Discordant pairs: {$discordance['discordant']} ".
                "(baseline only correct {$discordance['only_baseline_correct']}, candidate only correct {$discordance['only_candidate_correct']})");

            $this->newLine();
        }
    }

    /** @param array<string, mixed> $rate */
    private function formatRate(array $rate): string
    {
        if ($rate['rate'] === null) {
            return 'n/a';
        }

        return sprintf(
            '%.1f%% [%.1f–%.1f] (%d/%d)',
            $rate['rate'] * 100,
            $rate['ci_lower'] * 100,
            $rate['ci_upper'] * 100,
            $rate['matches'],
            $rate['population'],
        );
    }

    /** @param array<string, mixed> $report */
    private function writeWorksheet(array $report): void
    {
        $path = $this->absoluteOption('worksheet');
        $worksheet = $report['adjudication_worksheet'] ?? null;

        if (! is_array($worksheet)) {
            if ($path !== null) {
                throw new RuntimeException('--worksheet has nothing to write: a comparison run with --truth has already been adjudicated.');
            }

            return;
        }

        if ($path === null) {
            $this->warn('Pass --worksheet=<absolute path> to write the adjudication worksheet for these '
                .count($worksheet['labels']).' discordant sources.');

            return;
        }

        $this->createOnce($path, $worksheet);
        $this->line("Adjudication worksheet: {$path}");
    }

    /** @return array<string, mixed>|null */
    private function secondary(ItemGroundTruthArmComparison $comparison): ?array
    {
        $baseline = $this->resolvedPath($this->option('baseline-ground-truth'), 'baseline ground-truth artifact');
        $candidate = $this->resolvedPath($this->option('candidate-ground-truth'), 'candidate ground-truth artifact');

        if ($baseline === null && $candidate === null) {
            return null;
        }

        if ($baseline === null || $candidate === null) {
            throw new RuntimeException('The secondary diagnostic needs both ground-truth artifacts or neither.');
        }

        return $comparison->compare(
            $this->readArtifact($baseline, 'baseline ground-truth artifact'),
            $this->readArtifact($candidate, 'candidate ground-truth artifact'),
            max(0, (int) $this->option('examples')),
        );
    }

    /**
     * `--baseline=luna-none` names a run directory beneath the private evaluation root, exactly as
     * the arm runner's `--output` created it. An absolute path is accepted too, so a projection kept
     * somewhere else can still be compared.
     */
    private function projectionPath(string $option): string
    {
        $value = $this->option($option);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$option} is required; a comparison has no default arm.");
        }

        $value = trim($value);

        if (str_starts_with($value, '/')) {
            return $value;
        }

        if (! preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $value)) {
            throw new RuntimeException("--{$option} must be a run-directory name or an absolute path.");
        }

        return storage_path(self::EvaluationRoot."/{$value}/".self::ProjectionFile);
    }

    /**
     * An absolute path, or a bare file name resolved inside the private evaluation root — where the
     * arm runner already keeps its output, and the only place this command writes.
     */
    private function resolvedPath(mixed $value, string $label): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, '/')) {
            return $value;
        }

        if (! preg_match('/\A[a-z0-9][a-z0-9.-]*\z/', $value)) {
            throw new RuntimeException("The {$label} must be an absolute path or a file name inside the evaluation root.");
        }

        $root = storage_path(self::EvaluationRoot);

        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException("Unable to create the private evaluation root at {$root}.");
        }

        return "{$root}/{$value}";
    }

    private function absoluteOption(string $option): ?string
    {
        return $this->resolvedPath($this->option($option), "--{$option} path");
    }

    /** @return array<string, mixed> */
    private function readArtifact(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No {$label} at {$path}.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read the {$label} at {$path}.");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("The {$label} at {$path} is not a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Created once at a new path, matching every other artifact in this evaluation: a comparison is
     * evidence too, and overwriting one invalidates any figure already quoted from it.
     *
     * @param  array<string, mixed>  $contents
     */
    private function createOnce(string $path, array $contents): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Refusing to overwrite an existing artifact at {$path}.");
        }

        try {
            if (fwrite($handle, json_encode($contents, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL) === false) {
                throw new RuntimeException("Unable to write {$path}.");
            }
        } finally {
            fclose($handle);
        }

        chmod($path, 0600);
    }
}
