<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecomposeForCorpusRerun;
use App\Actions\RetranscribeForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\TalkEdgeChecks;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * What a batch's review taught (ensemble plan §3.10, decision 10): the questions asked and still
 * open, how they were answered, what the samples measured, and what the review cost. Read before
 * proposing a rule, so an adoption rests on the batch's numbers rather than on how the queue felt.
 *
 * Reads only. Open questions are counted by replaying each run's latest draws with its answers,
 * exactly as the review page and the recompose round see them.
 */
class ReportEnsembleBatchCommand extends Command
{
    /** Answers further apart than this start a new sitting when review time is estimated. */
    private const SITTING_GAP_SECONDS = 600;

    protected $signature = 'structure:ensemble-batch-report
        {runs?* : Processing log ids; without them, --since selects the batch}
        {--since= : Every run whose latest ensemble bundle started at or after this time}
        {--export=* : Review page directories (answers-map.json, sample-results.json) whose samples to count}
        {--report= : Write the JSON report here}';

    protected $description = 'Report a batch\'s ensemble questions, answers, samples and review cost, read-only';

    public function handle(ServiceStructureEnsembleReplay $replay): int
    {
        $logs = $this->selectedRuns();

        if ($logs === []) {
            $this->error('No run with an ensemble bundle matches; pass run ids or --since.');

            return self::FAILURE;
        }

        $totals = [
            'runs' => count($logs),
            'runs_with_open_questions' => 0,
            'open_questions' => 0,
            'open_by_type' => [],
            'deferred' => 0,
            'talk_edge_checks_open' => 0,
            'majority_decisions' => 0,
            'degraded_attempts' => 0,
            'attempts' => 0,
            'rounds' => ['detected' => 0, 'recomposed' => 0, 'transcribed' => 0],
            'answers' => 0,
            'answers_by_kind' => [],
            'corrections' => 0,
            'confirmations' => 0,
            'applied' => 0,
            'stale' => 0,
            'conflicting' => 0,
            'unreplayable_runs' => [],
        ];
        $answeredAt = [];

        foreach ($logs as $log) {
            $metadata = $log->processing_metadata?->toArray() ?? [];
            $bank = array_values(array_filter($metadata['service_structure_ensemble'] ?? [], 'is_array'));
            $rulings = array_values(array_filter($metadata['service_structure_ensemble_rulings'] ?? [], 'is_array'));
            $totals['attempts'] += count($bank);
            $totals['degraded_attempts'] += count(array_filter($bank, static fn (array $attempt): bool => ($attempt['composition']['degraded'] ?? false) === true));

            foreach ($log->corpusRerunStamps() as $stamp) {
                $round = match (true) {
                    RetranscribeForCorpusRerun::transcribedOnly($stamp) => 'transcribed',
                    ($stamp['detection'] ?? null) === RecomposeForCorpusRerun::DETECTION_RECOMPOSE => 'recomposed',
                    default => 'detected',
                };
                $totals['rounds'][$round]++;
            }

            foreach ($rulings as $ruling) {
                $kind = (string) ($ruling['kind'] ?? 'unknown');
                $totals['answers']++;
                $totals['answers_by_kind'][$kind] = ($totals['answers_by_kind'][$kind] ?? 0) + 1;

                if ($kind !== 'defer') {
                    $totals[$this->confirms($ruling) ? 'confirmations' : 'corrections']++;
                }

                if (is_string($ruling['answered_at'] ?? null)) {
                    $answeredAt[] = CarbonImmutable::parse($ruling['answered_at'])->getTimestamp();
                }
            }

            try {
                $replayed = $replay->replay(end($bank), $rulings, $log);
            } catch (Throwable $exception) {
                $totals['unreplayable_runs'][] = ['run' => $log->id, 'reason' => $exception->getMessage()];

                continue;
            }

            $open = array_values(array_filter($replayed['disputes'], static fn (array $dispute): bool => ($dispute['deferred'] ?? false) !== true));
            $totals['open_questions'] += count($open);
            $totals['deferred'] += count($replayed['disputes']) - count($open);
            $totals['runs_with_open_questions'] += $open === [] ? 0 : 1;
            $totals['talk_edge_checks_open'] += count(array_filter($open, static fn (array $dispute): bool => ($dispute['check'] ?? null) === TalkEdgeChecks::CHECK));
            $totals['majority_decisions'] += count($replayed['majority_decisions']);
            $totals['applied'] += count($replayed['applied_rulings']);
            $totals['stale'] += count($replayed['stale_rulings']);
            $totals['conflicting'] += count($replayed['conflicting_rulings']);

            foreach ($open as $dispute) {
                $type = (string) ($dispute['type'] ?? 'unknown');
                $totals['open_by_type'][$type] = ($totals['open_by_type'][$type] ?? 0) + 1;
            }
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'run_ids' => array_map(static fn (MediaProcessingLog $log): int => $log->id, $logs),
            ...$totals,
            'questions_per_run' => round($totals['open_questions'] / max(1, $totals['runs']), 2),
            'review_minutes_estimate' => $this->reviewMinutes($answeredAt),
            'samples' => $this->samples($logs),
        ];

        $this->table(['Measure', 'Value'], [
            ['Runs', $report['runs']],
            ['Rounds (detected / recomposed / transcribed)', implode(' / ', $report['rounds'])],
            ['Open questions (per run)', "{$report['open_questions']} ({$report['questions_per_run']})"],
            ['Runs with open questions', $report['runs_with_open_questions']],
            ['Talk-edge checks open', $report['talk_edge_checks_open']],
            ['Deferred (can\'t tell)', $report['deferred']],
            ['Majority decisions on the skim list', $report['majority_decisions']],
            ['Answers (corrections / confirmations)', "{$report['answers']} ({$report['corrections']} / {$report['confirmations']})"],
            ['Answers applied / stale / conflicting', "{$report['applied']} / {$report['stale']} / {$report['conflicting']}"],
            ['Degraded attempts', $report['degraded_attempts']],
            ['Review minutes (estimate)', $report['review_minutes_estimate']],
            ['Sampled majority decisions (wrong)', "{$report['samples']['decided']['answered']} ({$report['samples']['decided']['majority_wrong']})"],
            ['Sampled cuts (wrong)', "{$report['samples']['cuts']['heard']} ({$report['samples']['cuts']['wrong']})"],
            ['Runs that could not be replayed', count($report['unreplayable_runs'])],
        ]);

        $path = $this->option('report');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info("Report written to {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * Whether an answer kept what the ensemble proposed: an acceptance, or the choice of the
     * version that was written. Any other choice, a correction or a removal changed the output.
     *
     * @param  array<string, mixed>  $ruling
     */
    private function confirms(array $ruling): bool
    {
        $kind = $ruling['kind'] ?? null;
        $question = is_array($ruling['question'] ?? null) ? $ruling['question'] : [];

        if ($kind === 'accept') {
            return true;
        }

        if ($kind !== 'choose' || ($question['written'] ?? false) !== true) {
            return false;
        }

        $chosen = $ruling['resolution']['sections'] ?? null;
        $written = null;

        foreach ($question['alternatives'] ?? [] as $alternative) {
            if (is_array($alternative) && ($alternative['slots'] ?? null) === ($question['supporting_slots'] ?? false)) {
                $written = $alternative['section'] ?? null;
            }
        }

        if (! is_array($chosen) || count($chosen) !== 1 || ! is_array($chosen[0]) || ! is_array($written)) {
            return false;
        }

        foreach (['type', 'start_time', 'end_time', 'oos_item_id', 'song_title', 'reading_reference', 'sermon_reference'] as $field) {
            $left = $chosen[0][$field] ?? null;
            $right = $written[$field] ?? null;
            $same = is_numeric($left) && is_numeric($right) ? (float) $left === (float) $right : $left === $right;

            if (! $same) {
                return false;
            }
        }

        return true;
    }

    /**
     * Minutes spent answering, from the answers' times: the gaps inside a sitting, plus a minute
     * for each sitting's first answer. An estimate, labelled as one.
     *
     * @param  list<int>  $timestamps
     */
    private function reviewMinutes(array $timestamps): int
    {
        if ($timestamps === []) {
            return 0;
        }

        sort($timestamps);
        $seconds = 60;

        for ($index = 1; $index < count($timestamps); $index++) {
            $gap = $timestamps[$index] - $timestamps[$index - 1];
            $seconds += $gap <= self::SITTING_GAP_SECONDS ? $gap : 60;
        }

        return (int) round($seconds / 60);
    }

    /**
     * The samples drawn on the review pages: majority decisions answered, and how many of those
     * overruled the vote; cuts heard, and how many were judged wrong.
     *
     * @param  list<MediaProcessingLog>  $logs
     * @return array{decided: array{drawn: int, answered: int, majority_wrong: int}, cuts: array{drawn: int, heard: int, right: int, wrong: int, deferred: int}}
     */
    private function samples(array $logs): array
    {
        $result = ['decided' => ['drawn' => 0, 'answered' => 0, 'majority_wrong' => 0], 'cuts' => ['drawn' => 0, 'heard' => 0, 'right' => 0, 'wrong' => 0, 'deferred' => 0]];
        $rulingsByQuestion = [];

        foreach ($logs as $log) {
            foreach ($log->processing_metadata?->toArray()['service_structure_ensemble_rulings'] ?? [] as $ruling) {
                if (is_array($ruling) && is_string($ruling['question_id'] ?? null)) {
                    $rulingsByQuestion[$ruling['question_id']] = $ruling;
                }
            }
        }

        foreach ((array) $this->option('export') as $directory) {
            $map = $this->readJson(rtrim((string) $directory, '/').'/answers-map.json')['items'] ?? [];

            foreach (is_array($map) ? $map : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (($item['sampled'] ?? false) === true && ($item['decided'] ?? false) === true) {
                    $result['decided']['drawn']++;
                    $ruling = $rulingsByQuestion[$item['question_id'] ?? ''] ?? null;

                    if (is_array($ruling) && ($ruling['kind'] ?? null) !== 'defer') {
                        $result['decided']['answered']++;
                        $result['decided']['majority_wrong'] += $this->confirms($ruling) ? 0 : 1;
                    }
                }

                $result['cuts']['drawn'] += ($item['type'] ?? null) === 'sample_cut' ? 1 : 0;
            }

            $samplesPath = rtrim((string) $directory, '/').'/sample-results.json';

            foreach (is_file($samplesPath) ? ($this->readJson($samplesPath)['samples'] ?? []) : [] as $sample) {
                $answer = is_array($sample) ? ($sample['answer'] ?? null) : null;
                $result['cuts']['heard']++;
                $result['cuts'][match ($answer) {
                    'sample_right' => 'right',
                    'sample_wrong' => 'wrong',
                    default => 'deferred',
                }]++;
            }
        }

        return $result;
    }

    /** @return list<MediaProcessingLog> */
    private function selectedRuns(): array
    {
        $ids = array_map('intval', (array) $this->argument('runs'));
        $since = $this->option('since');

        if ($ids === [] && (! is_string($since) || $since === '')) {
            return [];
        }

        $query = MediaProcessingLog::query()->whereNotNull('processing_metadata->service_structure_ensemble')->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $threshold = is_string($since) && $since !== '' ? CarbonImmutable::parse($since) : null;

        return array_values($query->get()->filter(static function (MediaProcessingLog $log) use ($threshold): bool {
            if (! $threshold instanceof CarbonImmutable) {
                return true;
            }

            $bank = $log->processing_metadata?->raw['service_structure_ensemble'] ?? [];
            $startedAt = is_array($bank) && $bank !== [] ? (end($bank)['started_at'] ?? null) : null;

            return is_string($startedAt) && CarbonImmutable::parse($startedAt)->greaterThanOrEqualTo($threshold);
        })->all());
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("{$path} does not exist.");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
