<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricFailedTranscriptRepair;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Support\CanonicalJson;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete once adopted failed-stretch repairs are reconciled and historic
 * closeout retention has expired. Only the words action executes local edge decodes.
 */
class RepairFailedHistoricTranscriptsCommand extends Command
{
    protected $signature = 'historic-import:repair-failed-transcripts
        {action : inspect, words, prepare, apply or rollback}
        {--run=* : Exact run IDs to inspect, apply, or roll back; required}
        {--donor-dir=* : H10b directories to search during inspection; ambiguous matches are unresolved}
        {--donor=* : Explicit run_id=/absolute/donor.json selections, overriding directory matches}
        {--listening=* : Existing routing and per-window verdict JSON files to bind into proposals}
        {--section=* : Exact section IDs for words; requires exactly one run}
        {--input= : Proposal bundle for prepare, or adoption bundle for apply}
        {--reviews= : JSON object with runs keyed by run ID, containing source-verified decisions}
        {--output= : New private JSON artifact path for inspect/prepare or an execution summary}
        {--receipt= : Adoption sha256 to roll back on exactly one run}
        {--execute : Bank local word evidence, install accepted transcripts or roll back; default is read-only}';

    protected $description = 'Inspect banked H10b failed-window text, prepare source-reviewed repairs, and reversibly adopt exact candidates';

    public function handle(HistoricFailedTranscriptRepair $repair): int
    {
        try {
            $action = (string) $this->argument('action');
            $ids = $this->runIds();
            $execute = (bool) $this->option('execute');
            if (! in_array($action, ['inspect', 'words', 'prepare', 'apply', 'rollback'], true)
                || ($execute && in_array($action, ['inspect', 'prepare'], true))) {
                throw new RuntimeException('Choose inspect, words, prepare, apply or rollback; only words/apply/rollback accept --execute.');
            }
            $sectionIds = [];
            if ($action === 'words') {
                $sections = $this->paths('section');
                if (count($ids) !== 1 || $sections === [] || array_any($sections, static fn (string $id): bool => ! ctype_digit($id) || (int) $id < 1)) {
                    throw new RuntimeException('Words requires exactly one run and explicit positive --section IDs.');
                }
                $sectionIds = array_values(array_unique(array_map(intval(...), $sections)));
            }
            $output = null;
            if ($this->option('output') !== null || in_array($action, ['inspect', 'prepare'], true)) {
                $output = PrivateEvidenceFile::resolve($this->option('output'), 'Repair report');
                if (file_exists($output)) {
                    throw new RuntimeException('Repair report must use a new path.');
                }
            }
            $entries = [];
            $failed = false;
            $input = in_array($action, ['words', 'prepare', 'apply'], true) ? $repair->readJson($this->requiredPath('input')) : [];
            $reviews = $action === 'prepare' ? $repair->readJson($this->requiredPath('reviews')) : [];
            if (in_array($action, ['words', 'prepare', 'apply'], true) && ($input['schema'] ?? null) !== HistoricFailedTranscriptRepair::SCHEMA) {
                throw new RuntimeException('Unknown repair bundle schema.');
            }
            $donors = $this->donors();
            foreach ($ids as $id) {
                $this->line("{$action}: run {$id}");
                try {
                    $run = MediaProcessingLog::query()->findOrFail($id);
                    if ($action === 'inspect' && $this->failedWindows($id) === []) {
                        $entries[(string) $id] = ['run_id' => $id, 'outcome' => 'unaffected', 'failed_windows' => []];
                        $this->line('No failed windows.');

                        continue;
                    }
                    $entry = match ($action) {
                        'inspect' => $repair->inspect($run, $this->donorPath($id, $donors), $this->paths('listening'),
                            array_values(array_diff($this->directoryDonors($id), [$this->donorPath($id, $donors)]))),
                        'prepare' => $repair->prepare($this->entry($input, $id), $this->review($reviews, $id)),
                        'words' => $repair->prepareWords($this->entry($input, $id), $sectionIds, $execute),
                        'apply' => $execute
                            ? ['run_id' => $id, 'outcome' => $repair->apply($this->entry($input, $id))]
                            : $repair->previewAdoption($this->entry($input, $id)),
                        'rollback' => count($ids) === 1
                            ? ['run_id' => $id, 'outcome' => $repair->rollback($run, (string) $this->option('receipt'), $execute)]
                            : throw new RuntimeException('Rollback requires exactly one run and --receipt.'),
                    };
                    $entries[(string) $id] = $entry;
                    $this->line($action === 'inspect'
                        ? sprintf('%d failed windows; %d candidate cues; %d affected edges.', count((array) data_get($entry, 'report.windows')), count((array) data_get($entry, 'report.candidates')), count((array) ($entry['edges'] ?? [])))
                        : (string) ($entry['outcome'] ?? 'prepared; nothing installed'));
                } catch (Throwable $exception) {
                    $failed = true;
                    $entries[(string) $id] = ['run_id' => $id, 'outcome' => 'unresolved', 'reason' => $exception->getMessage(),
                        'failed_windows' => $this->failedWindows($id)];
                    $this->warn("Run {$id}: {$exception->getMessage()}");
                }
            }
            if ($output !== null) {
                PrivateEvidenceFile::writeOnce($output, CanonicalJson::encodeReadable([
                    'schema' => HistoricFailedTranscriptRepair::SCHEMA, 'action' => $action,
                    'selected_run_ids' => $ids, 'membership_sha256' => CanonicalJson::hash($ids),
                    'summary' => $this->summary($entries), 'entries' => $entries,
                ]), 'Repair report');
                $this->info('Report: '.$output);
            }
            if (! $execute) {
                $this->info('Read-only: no stored transcripts, sections, holds or media changed.');
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<int> */
    private function runIds(): array
    {
        $ids = (array) $this->option('run');
        if ($ids === [] || array_any($ids, static fn ($id): bool => ! is_string($id) || ! ctype_digit($id) || (int) $id < 1)) {
            throw new RuntimeException('Explicit positive --run IDs are required.');
        }
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        sort($ids);

        return $ids;
    }

    /** @return array<int, string> */
    private function donors(): array
    {
        $donors = [];
        foreach ($this->paths('donor') as $selection) {
            $parts = explode('=', $selection, 2);
            if (count($parts) !== 2 || ! ctype_digit($parts[0]) || isset($donors[(int) $parts[0]])) {
                throw new RuntimeException('Each --donor must be a unique run_id=/path selection.');
            }
            $donors[(int) $parts[0]] = $parts[1];
        }

        return $donors;
    }

    /** @param array<int, string> $explicit */
    private function donorPath(int $id, array $explicit): string
    {
        $matches = isset($explicit[$id]) ? [$explicit[$id]] : [];
        if ($matches === []) {
            $matches = $this->directoryDonors($id);
        }
        $matches = array_values(array_unique(array_filter(array_map(realpath(...), $matches))));
        if (count($matches) !== 1) {
            throw new RuntimeException('Missing or ambiguous donor; supply an exact --donor selection.');
        }

        return $matches[0];
    }

    /** @return list<string> */
    private function directoryDonors(int $id): array
    {
        $matches = [];
        foreach ($this->paths('donor-dir') as $directory) {
            $path = realpath(rtrim($directory, '/')."/run-{$id}.json");
            if (is_string($path) && is_file($path)) {
                $matches[] = $path;
            }
        }
        sort($matches);

        return array_values(array_unique($matches));
    }

    /** @return list<string> */
    private function paths(string $option): array
    {
        return array_values(array_filter((array) $this->option($option), is_string(...)));
    }

    private function requiredPath(string $option): string
    {
        $path = $this->option($option);
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('--'.$option.' is required.');
        }

        return $path;
    }

    /** @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function entry(array $bundle, int $id): array
    {
        $entry = data_get($bundle, 'entries.'.$id);
        if (! is_array($entry) || ($entry['kind'] ?? null) === null
            || (int) ($entry['run_id'] ?? data_get($entry, 'proposal.run_id', 0)) !== $id) {
            throw new RuntimeException('Bundle has no valid entry for run '.$id);
        }

        return $entry;
    }

    /** @param array<string, mixed> $reviews
     * @return array<string, mixed>
     */
    private function review(array $reviews, int $id): array
    {
        $review = data_get($reviews, 'runs.'.$id);
        if (! is_array($review)) {
            throw new RuntimeException('No source listening review for run '.$id);
        }

        return $review;
    }

    /** @return list<array{start: float, end: float, reason: string}>|null */
    private function failedWindows(int $id): ?array
    {
        try {
            $run = MediaProcessingLog::query()->findOrFail($id);
            $context = $run->historicStagingContext();
            if ($context === null) {
                return null;
            }

            return app(HistoricStagingContextRegistry::class)->within($context, static fn (): array => array_values(array_filter(
                app(ServiceTranscriptReader::class)->read($run)->unobservableWindows,
                static fn (array $w): bool => $w['reason'] === 'retranscription_failed',
            )));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int|string, array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function summary(array $entries): array
    {
        $summary = ['runs' => count($entries), 'unaffected' => 0, 'unresolved' => 0, 'failed_windows' => 0,
            'failed_seconds' => 0.0, 'edge_bearing_windows' => 0, 'affected_edges' => 0, 'candidate_cues' => 0, 'quarantined_cues' => 0,
            'listening_cues' => 0];
        foreach ($entries as $entry) {
            foreach (['unaffected', 'unresolved'] as $outcome) {
                if (($entry['outcome'] ?? null) === $outcome) {
                    $summary[$outcome]++;
                }
            }
            $windows = data_get($entry, 'report.windows', $entry['failed_windows'] ?? []);
            $edges = (array) ($entry['edges'] ?? []);
            foreach (is_array($windows) ? $windows : [] as $window) {
                $summary['failed_windows']++;
                $summary['failed_seconds'] += $window['end'] - $window['start'];
                if (array_any($edges, static fn (array $edge): bool => $edge['window'] === $window)) {
                    $summary['edge_bearing_windows']++;
                }
            }
            $summary['affected_edges'] += count($edges);
            $summary['listening_cues'] += count((array) ($entry['listening_candidate_ids'] ?? []));
            foreach ((array) data_get($entry, 'report.candidates', []) as $candidate) {
                $summary['candidate_cues']++;
                if ($candidate['flags'] !== []) {
                    $summary['quarantined_cues']++;
                }
            }
        }
        $summary['failed_seconds'] = round($summary['failed_seconds'], 2);

        return $summary;
    }
}
