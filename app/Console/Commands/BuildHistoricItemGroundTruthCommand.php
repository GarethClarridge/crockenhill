<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\HistoricItemGroundTruth;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Build the historic import's item-level ground truth — IC3, item 0(2).
 *
 * The import has measured itself with `exact_correct` throughout, which checks date, service
 * slot and non-emptiness and never opens an item. This joins the two sources that can see
 * inside a service — the hymn workbook's song membership and the OpenLP archives' item count
 * and order — against the staged plans, and reports per identity which source corroborated it
 * and on what.
 *
 * Read-only by construction: it opens two evidence artifacts, queries the staged corpus, and
 * writes one artifact of its own. Point `--connection` at the rehearsal database; measuring
 * against whatever the working database happens to hold would not be a measurement of the
 * staged import.
 */
class BuildHistoricItemGroundTruthCommand extends Command
{
    protected $signature = 'service-tracking:build-item-ground-truth
        {--hymn-reconciliation= : Path to the hymn reconciliation artifact supplying song membership}
        {--openlp-manifest= : Path to the approved OpenLP curation manifest}
        {--openlp-root= : Directory holding the approved OpenLP .osz corpus}
        {--output= : Absolute path the ground-truth artifact is created at}
        {--connection= : Named database connection holding the staged corpus to measure}';

    protected $description = 'Join the hymn workbook and OpenLP corpus against the staged plans to produce item-level ground truth';

    public function handle(HistoricItemGroundTruth $groundTruth): int
    {
        try {
            $this->selectConnection();

            $artifact = $groundTruth->generate(
                $this->requiredOption('hymn-reconciliation'),
                $this->requiredOption('openlp-manifest'),
                $this->requiredOption('openlp-root'),
            );
            $path = $this->outputPath();

            $this->createOnce($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

            $this->report($artifact);
            $this->line("Artifact: {$path}");
            $this->line('Artifact sha256: '.CanonicalJson::hash($artifact));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * The corpus is chosen by pointing the default connection at it rather than by threading a
     * connection name through every query. Nothing here writes, so the switch cannot escape as
     * a mutation.
     */
    private function selectConnection(): void
    {
        $connection = $this->option('connection');

        if (! is_string($connection) || trim($connection) === '') {
            return;
        }

        DB::setDefaultConnection(trim($connection));
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$name} is required; this measurement has no default source.");
        }

        return trim($value);
    }

    private function outputPath(): string
    {
        $option = $this->option('output');

        if (! is_string($option) || ! str_starts_with($option, '/')) {
            throw new RuntimeException('A ground-truth artifact requires an absolute --output path.');
        }

        return $option;
    }

    /**
     * Created once at a new path.
     *
     * Ground truth is evidence. Overwriting one in place would invalidate any figure already
     * quoted from it, so a second run has to name a new path.
     */
    private function createOnce(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Refusing to overwrite an existing artifact at {$path}.");
        }

        try {
            if (fwrite($handle, $contents) === false) {
                throw new RuntimeException("Unable to write the artifact at {$path}.");
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $artifact */
    private function report(array $artifact): void
    {
        /** @var array<string, mixed> $counts */
        $counts = $artifact['counts'];
        /** @var array<string, int> $bySource */
        $bySource = $counts['by_source'];
        /** @var array<string, mixed> $corpus */
        $corpus = $artifact['corpus'];
        /** @var array<string, int> $songItems */
        $songItems = $counts['staged_song_items'];

        $this->info("Corpus: {$corpus['database']} — {$counts['staged_identities']} staged identities, {$corpus['item_count']} items");
        $this->line("Corroborated: {$counts['corroborated_identities']} (hymn workbook only {$bySource['hymn_workbook_only']}, ".
            "OpenLP only {$bySource['openlp_only']}, both {$bySource['both']}, none {$bySource['none']})");
        $this->line("Staged song items: {$songItems['total']} — {$songItems['resolved']} resolved to the catalogue, {$songItems['unresolved']} unresolved");

        $this->newLine();

        /** @var array<string, array<string, int>> $verdicts */
        $verdicts = $counts['verdicts'];
        $columns = ['match', 'mismatch', 'indeterminate', 'circular', 'not_corroborated'];

        $this->table(
            ['Dimension', ...$columns],
            array_map(
                static fn (string $dimension, array $tally): array => [
                    $dimension,
                    ...array_map(static fn (string $verdict): string => (string) ($tally[$verdict] ?? 0), $columns),
                ],
                array_keys($verdicts),
                array_values($verdicts),
            ),
        );

        /** @var array<string, int> $mismatches */
        $mismatches = $counts['song_membership_mismatches'];

        if ($mismatches['total'] > 0) {
            $this->line("Membership mismatches: {$mismatches['total']} — {$mismatches['missing_from_staged_only']} missing from the staged plan only, ".
                "{$mismatches['extra_in_staged_only']} extra in the staged plan only, {$mismatches['both_directions']} in both directions");
            $this->line("Of those, {$mismatches['explainable_by_an_unresolved_title']} carried a title the catalogue could not resolve on one side.");
        }

        $this->reportTitleHygiene($artifact['title_hygiene']);

        /** @var array<string, int> $byYear */
        $byYear = $counts['uncorroborated_by_year'];

        foreach ($byYear as $year => $count) {
            $this->warn("Uncorroborated identities in {$year}: {$count}");
        }

        /** @var array<string, mixed> $openLp */
        $openLp = $artifact['sources']['openlp'];
        /** @var list<array<string, string>> $unreadable */
        $unreadable = $openLp['unreadable'];

        foreach ($unreadable as $entry) {
            $this->warn("OpenLP archive unreadable: {$entry['item_key']} — {$entry['reason']}");
        }
    }

    /**
     * Item 0(4). Printed immediately under the membership mismatches because it is the line above
     * it that most invites over-reading: a mismatch explained by an unresolved title is only an
     * extraction fault when that title is `defective`.
     *
     * @param  array<string, mixed>  $hygiene
     */
    private function reportTitleHygiene(array $hygiene): void
    {
        /** @var array<string, int> $byVerdict */
        $byVerdict = $hygiene['by_verdict'];
        /** @var array<string, int> $byDefect */
        $byDefect = $hygiene['by_defect'];

        $this->newLine();
        $this->line("Unresolved staged song titles: {$hygiene['unresolved_occurrences']} occurrences across {$hygiene['distinct_titles']} distinct titles");

        $this->table(
            ['Hygiene verdict', 'Occurrences', 'Who acts on it'],
            [
                ['defective', (string) $byVerdict['defective'], 'extraction — the title is damaged'],
                ['decorated', (string) $byVerdict['decorated'], 'resolver — the title is correct behind decoration'],
                ['not_a_title', (string) $byVerdict['not_a_title'], 'nobody — no title was stated'],
                ['clean', (string) $byVerdict['clean'], 'catalogue — the song is not catalogued'],
            ],
        );

        $this->line("Resolvable once decoration is stripped: {$hygiene['recovered_by_normalisation']} occurrences.");

        if ($byDefect !== []) {
            $this->table(
                ['Defect family (overlapping)', 'Occurrences'],
                array_map(
                    static fn (string $family, int $count): array => [$family, (string) $count],
                    array_keys($byDefect),
                    array_values($byDefect),
                ),
            );
        }
    }
}
