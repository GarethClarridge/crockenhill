<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ItemGroundTruthArmComparison;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Score one parser arm against the baseline on item-level ground truth.
 *
 * The producer for the model evaluation's **secondary** diagnostics. Each arm builds its own
 * ground-truth artifact against its own rehearsal database; this joins two of them into paired
 * transition matrices over the corroborating evidence.
 *
 * It does not produce the decision. The primary outcome is source-faithful extraction — whether the
 * arm reproduced what the email itself states — tested against a declared non-inferiority margin and
 * read from the raw parse, before manifest-backed identity and content-scope resolution can conceal a
 * model's own identity or scope error. What this command reports is a different question: whether the
 * email plan agrees with what was later sung or projected. A service can change after the email was
 * written, so a disagreement here is not automatically a parser error and never adopts or rejects an
 * arm on its own. Hence no p-values.
 *
 * It deliberately does not emit a single combined score. Song membership, song count and song
 * order are corroborated by different sources and answer different questions, and collapsing them
 * would hide the case this evaluation exists to catch — an arm that extracts more items and so
 * converts silent failures into visible, scoreable disagreements.
 *
 * Read-only: it opens two artifacts and writes one report.
 */
class CompareItemGroundTruthArmsCommand extends Command
{
    protected $signature = 'service-tracking:compare-ground-truth-arms
        {--baseline= : Path to the baseline arm\'s ground-truth artifact}
        {--candidate= : Path to the candidate arm\'s ground-truth artifact}
        {--output= : Absolute path the comparison report is created at}
        {--examples=10 : How many changed identities to record per dimension}';

    protected $description = 'Compare two parser arms on item-level ground truth with paired transition matrices';

    public function handle(ItemGroundTruthArmComparison $comparison): int
    {
        try {
            $report = $comparison->compare(
                $this->readArtifact($this->requiredOption('baseline'), 'baseline'),
                $this->readArtifact($this->requiredOption('candidate'), 'candidate'),
                max(0, (int) $this->option('examples')),
            );

            $this->report($report);

            $path = $this->option('output');

            if (is_string($path) && $path !== '') {
                if (! str_starts_with($path, '/')) {
                    throw new RuntimeException('A comparison report requires an absolute --output path.');
                }

                $this->createOnce($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
                $this->line("Report: {$path}");
            }

            $this->line('Report sha256: '.CanonicalJson::hash($report));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $report */
    private function report(array $report): void
    {
        $this->info("Shared identities: {$report['shared_identities']} ".
            "(baseline only {$report['baseline_only_identities']}, candidate only {$report['candidate_only_identities']})");
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
                    array_keys($result['transitions']),
                    array_values($result['transitions']),
                ),
            );

            $this->line("Total extraction failures fixed: {$result['total_extraction_failures_fixed']}, ".
                "introduced: {$result['total_extraction_failures_introduced']}");
            $this->line('Match rate: '.$this->formatRate($result['match_rate']['baseline']).' → '.$this->formatRate($result['match_rate']['candidate']));

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

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$name} is required; a comparison has no default arm.");
        }

        return trim($value);
    }

    /** @return array<string, mixed> */
    private function readArtifact(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No {$label} artifact at {$path}.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read the {$label} artifact at {$path}.");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("The {$label} artifact at {$path} is not a JSON object.");
        }

        return $decoded;
    }

    /**
     * Created once at a new path, matching the ground-truth artifacts this reads: a comparison is
     * evidence too, and overwriting one invalidates any figure already quoted from it.
     */
    private function createOnce(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Refusing to overwrite an existing report at {$path}.");
        }

        try {
            if (fwrite($handle, $contents) === false) {
                throw new RuntimeException("Unable to write the report at {$path}.");
            }
        } finally {
            fclose($handle);
        }
    }
}
