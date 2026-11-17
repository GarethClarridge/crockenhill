<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecomposeForCorpusRerun;
use App\Actions\RetranscribeForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\TalkEdgeChecks;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * What a batch's review taught (ensemble plan §3.10, decision 10): the questions asked and still
 * open, how they were answered, what the samples measured, and what the review cost. Read before
 * proposing a rule, so an adoption rests on the batch's numbers rather than on how the queue felt.
 *
 * Reads only. Open questions are counted by replaying each run's latest draws with its answers,
 * exactly as the review page and the recompose round see them.
 *
 * The `redraw` block says how far a second draw moved a run: the last two banked attempts compared
 * section by section, and the sermon-song introduction answers (K16) compared across identities.
 * It counts changes, not consequences. Whether a changed end moved a heard cut needs the
 * rerun-diff join to cut changes, which this command does not make.
 */
class ReportEnsembleBatchCommand extends Command
{
    /** Answers further apart than this start a new sitting when review time is estimated. */
    private const SITTING_GAP_SECONDS = 600;

    /**
     * Seconds two edges may differ before a redraw counts as having moved one. Matches the
     * tolerance SectionStructureFlagRederiver uses when it compares a section's times.
     */
    private const REDRAW_TOLERANCE_SECONDS = 0.5;

    /** Kind prefix of the banked sermon-song introduction answers (K16). */
    private const K16_ARTIFACT_PREFIX = 'sermon-song-introduction-';

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
        $redraw = ['runs_compared' => 0, 'runs_changed' => 0, 'changes' => [], 'k16_flips' => []];

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

            $this->compareRedraw($log, $bank, $redraw);

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
            'redraw' => $redraw,
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
            ['Redraw changes (runs changed / compared)', "{$redraw['runs_changed']} / {$redraw['runs_compared']}"],
            ['Redraw section changes / K16 flips', count($redraw['changes']).' / '.count($redraw['k16_flips'])],
            ['Runs that could not be replayed', count($report['unreplayable_runs'])],
        ]);

        if ($redraw['changes'] !== []) {
            $this->table(['Run', 'Change', 'Before', 'After'], array_map(fn (array $change): array => [
                $change['run'], $change['change'], $this->describe($change['before']), $this->describe($change['after']),
            ], $redraw['changes']));
        }

        if ($redraw['k16_flips'] !== []) {
            $this->table(['Run', 'Part', 'Song start', 'Before', 'After'], array_map(fn (array $flip): array => [
                $flip['run'], $flip['part'] ?? 'output', $flip['song_start'], json_encode($flip['before']), json_encode($flip['after']),
            ], $redraw['k16_flips']));
        }

        $path = $this->option('report');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info("Report written to {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * Adds a run's redraw to the block: its last two attempts' sections, and its K16 answers.
     *
     * @param  list<array<string, mixed>>  $bank
     * @param  array{runs_compared: int, runs_changed: int, changes: list<array<string, mixed>>, k16_flips: list<array<string, mixed>>}  $redraw
     */
    private function compareRedraw(MediaProcessingLog $log, array $bank, array &$redraw): void
    {
        if (count($bank) >= 2) {
            $redraw['runs_compared']++;
            $changes = $this->sectionChanges($this->sectionsOf($bank[count($bank) - 2]), $this->sectionsOf($bank[count($bank) - 1]));
            $redraw['runs_changed'] += $changes === [] ? 0 : 1;

            foreach ($changes as $change) {
                $redraw['changes'][] = ['run' => $log->id, ...$change];
            }
        }

        foreach ($this->k16Flips($log) as $flip) {
            $redraw['k16_flips'][] = ['run' => $log->id, ...$flip];
        }
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array{type: string|null, title: string|null, start_time: float, end_time: float}>
     */
    private function sectionsOf(array $attempt): array
    {
        $sections = [];

        foreach ($attempt['composition']['structure']['sections'] ?? [] as $section) {
            if (is_array($section) && is_numeric($section['start_time'] ?? null) && is_numeric($section['end_time'] ?? null)) {
                $sections[] = [
                    'type' => is_string($section['type'] ?? null) ? $section['type'] : null,
                    'title' => is_string($section['title'] ?? null) ? $section['title'] : null,
                    'start_time' => (float) $section['start_time'],
                    'end_time' => (float) $section['end_time'],
                ];
            }
        }

        return $sections;
    }

    /**
     * Sections paired by the time they cover (largest overlap first), never by id; what is left
     * over was removed or added, and each pair is checked for type, edges and title.
     *
     * @param  list<array{type: string|null, title: string|null, start_time: float, end_time: float}>  $before
     * @param  list<array{type: string|null, title: string|null, start_time: float, end_time: float}>  $after
     * @return list<array{change: string, before: array<string, mixed>|null, after: array<string, mixed>|null}>
     */
    private function sectionChanges(array $before, array $after): array
    {
        $pairs = [];

        foreach ($before as $left => $a) {
            foreach ($after as $right => $b) {
                $overlap = min($a['end_time'], $b['end_time']) - max($a['start_time'], $b['start_time']);

                if ($overlap > 0) {
                    $pairs[] = [$overlap, $left, $right];
                }
            }
        }

        usort($pairs, static fn (array $x, array $y): int => [$y[0], $x[1], $x[2]] <=> [$x[0], $y[1], $y[2]]);
        $matched = [];

        foreach ($pairs as [, $left, $right]) {
            if (! isset($matched[$left]) && ! in_array($right, $matched, true)) {
                $matched[$left] = $right;
            }
        }

        $changes = [];

        foreach ($before as $left => $a) {
            if (! isset($matched[$left])) {
                $changes[] = ['change' => 'removed', 'before' => $a, 'after' => null];

                continue;
            }

            $b = $after[$matched[$left]];
            $kinds = array_keys(array_filter([
                'retyped' => $a['type'] !== $b['type'],
                'moved' => abs($a['start_time'] - $b['start_time']) > self::REDRAW_TOLERANCE_SECONDS || abs($a['end_time'] - $b['end_time']) > self::REDRAW_TOLERANCE_SECONDS,
                'retitled' => $a['title'] !== $b['title'],
            ]));

            foreach ($kinds as $kind) {
                $changes[] = ['change' => $kind, 'before' => $a, 'after' => $b];
            }
        }

        foreach ($after as $right => $b) {
            if (! in_array($right, $matched, true)) {
                $changes[] = ['change' => 'added', 'before' => null, 'after' => $b];
            }
        }

        return $changes;
    }

    /**
     * K16 answers grouped by part and song start: where the first and last banked identity
     * disagree on the introduction's end or on whether it ties to the sermon.
     *
     * @return list<array{part: int|null, song_start: float|null, before: array{end: float|null, ties_to_sermon: bool|null}, after: array{end: float|null, ties_to_sermon: bool|null}}>
     */
    private function k16Flips(MediaProcessingLog $log): array
    {
        $groups = [];

        foreach (ServiceArtifactStorage::recordedFor($log) as $entry) {
            if (! str_starts_with($entry['kind'], self::K16_ARTIFACT_PREFIX)) {
                continue;
            }

            try {
                $payload = json_decode((string) Storage::disk($entry['disk'])->get($entry['path']), true);
            } catch (Throwable) {
                continue;
            }

            $identity = is_array($payload) && is_array($payload['identity'] ?? null) ? $payload['identity'] : null;

            if ($identity === null || ! array_key_exists('end', $payload)) {
                continue;
            }

            $part = is_numeric($identity['part'] ?? null) ? (int) $identity['part'] : null;
            $songStart = is_numeric($identity['song_start'] ?? null) ? (float) $identity['song_start'] : null;
            $groups["{$part}|{$songStart}"]['part'] = $part;
            $groups["{$part}|{$songStart}"]['song_start'] = $songStart;
            $groups["{$part}|{$songStart}"]['answers'][] = [
                'end' => is_numeric($payload['end']) ? (float) $payload['end'] : null,
                'ties_to_sermon' => is_bool($payload['ties_to_sermon'] ?? null) ? $payload['ties_to_sermon'] : null,
            ];
        }

        $flips = [];

        foreach ($groups as $group) {
            $first = $group['answers'][0];
            $last = $group['answers'][count($group['answers']) - 1];
            $endMoved = ($first['end'] === null) !== ($last['end'] === null)
                || ($first['end'] !== null && $last['end'] !== null && abs($first['end'] - $last['end']) > self::REDRAW_TOLERANCE_SECONDS);

            if (count($group['answers']) >= 2 && ($endMoved || $first['ties_to_sermon'] !== $last['ties_to_sermon'])) {
                $flips[] = ['part' => $group['part'], 'song_start' => $group['song_start'], 'before' => $first, 'after' => $last];
            }
        }

        return $flips;
    }

    /** @param  array<string, mixed>|null  $section */
    private function describe(?array $section): string
    {
        return $section === null ? '-' : "{$section['type']} \"{$section['title']}\" {$section['start_time']}-{$section['end_time']}";
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
