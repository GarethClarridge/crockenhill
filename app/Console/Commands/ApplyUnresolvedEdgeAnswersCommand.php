<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\ChurchService\CueSafeExtractionPlan;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Records the operator's answers to the edges {@see ExportUnresolvedEdgeExcerptsCommand} wrote
 * excerpts for, where the cut planner reads them ({@see CueSafeExtractionPlan::EDGE_ANSWERS_KEY}).
 * Each answer is checked against a fresh plan first: an edge the plan no longer refuses on the same
 * evidence, for the same output, is reported and not recorded. The answer keeps the refusal's key
 * ({@see CueSafeExtractionPlan::edgeAnswerKey()}), so it applies to nothing else. Dry run unless `--execute`. Nothing is cut or published:
 * the next extraction plans with the answer.
 *
 * Deletion trigger: delete once unresolved edges can be heard and answered on the service screen.
 */
class ApplyUnresolvedEdgeAnswersCommand extends Command
{
    protected $signature = 'historic-import:unresolved-edge-answers
        {export : The directory the excerpts were written to}
        {answers : JSON list of {id, choice: right|cut_at|defer, time?, note?}}
        {--operator= : The admin user answering}
        {--execute : Record the answers; without it, report only}';

    protected $description = 'Record operator answers to refused cut edges, checked against a fresh plan';

    public function handle(CueSafeExtractionPlan $plans): int
    {
        $entries = collect($this->json(rtrim((string) $this->argument('export'), '/').'/excerpts.json'))->keyBy('id');
        $operator = User::query()->find((int) $this->option('operator'));

        if (! $operator instanceof User) {
            throw new RuntimeException('Pass --operator with the answering admin user id.');
        }

        $rows = [];
        $ready = [];

        foreach ($this->json((string) $this->argument('answers')) as $answer) {
            $id = (string) ($answer['id'] ?? '');
            $choice = (string) ($answer['choice'] ?? '');
            $entry = $entries->get($id);
            $status = match (true) {
                ! is_array($entry) || ($entry['kind'] ?? null) !== 'needs_operator' => 'not an edge in this export',
                $choice === 'defer' => 'deferred: nothing recorded',
                ! in_array($choice, ['right', 'cut_at'], true) => "unknown choice “{$choice}”",
                $choice === 'cut_at' && ! is_numeric($answer['time'] ?? null) => 'needs the time to cut at',
                ! $this->stillRefused($plans, $entry) => 'the plan no longer refuses this edge on the same evidence',
                default => 'ready',
            };
            $rows[] = [$id, $choice, $status];

            if ($status === 'ready' && is_array($entry)) {
                $ready[] = [(int) $entry['run'], [
                    'key' => $entry['key'], 'edge' => $entry['edge'], 'reason' => $entry['reason'], 'proposed_time' => (float) $entry['time'],
                    'original_time' => (float) $entry['original_time'], 'output' => $entry['output'], 'decision' => $choice,
                    'time' => $choice === 'cut_at' ? (float) $answer['time'] : (float) $entry['time'],
                    'note' => trim((string) ($answer['note'] ?? '')), 'operator_id' => $operator->id, 'recorded_at' => now()->toIso8601String(),
                ]];
            }
        }

        $this->table(['Answer', 'Choice', 'Status'], $rows);

        if (! $this->option('execute')) {
            $this->info(sprintf('%d ready. Nothing recorded; pass --execute to record.', count($ready)));

            return self::SUCCESS;
        }

        foreach ($ready as [$run, $record]) {
            MediaProcessingLog::query()->findOrFail($run)->writeProcessingMetadata(static function (array $metadata) use ($record): array {
                $kept = array_values(array_filter($metadata[CueSafeExtractionPlan::EDGE_ANSWERS_KEY] ?? [], static fn (mixed $answer): bool => ! is_array($answer)
                    || ($answer['key'] ?? null) !== $record['key']));
                $metadata[CueSafeExtractionPlan::EDGE_ANSWERS_KEY] = [...$kept, $record];

                return $metadata;
            });
        }

        $this->info(sprintf('%d applied. The next extraction plans with them.', count($ready)));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $entry */
    private function stillRefused(CueSafeExtractionPlan $plans, array $entry): bool
    {
        $log = MediaProcessingLog::query()->find((int) $entry['run']);

        if (! $log instanceof MediaProcessingLog) {
            return false;
        }

        try {
            $sectionId = preg_match('/§(\d+)/', (string) $entry['output'], $match) === 1 ? (int) $match[1] : null;
            $section = $sectionId !== null ? $log->serviceSections()->find($sectionId) : null;
            $audit = $section !== null
                ? $plans->forSection($section)['cue_edge_widening']
                : $plans->forSpans($log, array_values($log->serviceSections()->whereIn('id', $log->processing_metadata?->raw['sermon_composition']['selected_section_ids'] ?? [])
                    ->orderBy('start_time')->get()->map(static fn ($s): array => ['start_time' => (float) $s->start_time, 'end_time' => (float) $s->end_time])->all()), sermonEnd: true)['cue_edge_widening'];
        } catch (OutputEdgeTimingsMissing) {
            return false;
        }

        return array_any($audit, static fn (array $current): bool => CueSafeExtractionPlan::edgeAnswerKey($current) === $entry['key']);
    }

    /** @return list<array<string, mixed>> */
    private function json(string $path): array
    {
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($decoded)) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return array_values(array_filter($decoded, 'is_array'));
    }
}
