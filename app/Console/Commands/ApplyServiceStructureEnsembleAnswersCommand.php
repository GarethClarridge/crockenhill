<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ServiceReview\AnswerServiceStructureEnsembleQuestion;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Applies the answers saved on a review page that `structure:ensemble-export-questions` wrote.
 *
 * Each saved choice is mapped through the export's answer map to the answer it stands for, so
 * no label is interpreted here. "None of these is right" needs the corrected sections written
 * into the answer (from the operator's note) before it can apply; without them it is reported
 * and left open, never guessed. Lists the plan unless --execute is given.
 */
class ApplyServiceStructureEnsembleAnswersCommand extends Command
{
    protected $signature = 'structure:ensemble-apply-answers
        {export : Directory the export command wrote (holds answers-map.json)}
        {answers : JSON list of the answers saved on the page, each as saved or wrapped as {id, data}}
        {--operator= : User id recorded as answering; defaults to the only administrator}
        {--execute : Apply the answers; without it, only list what would be applied}';

    protected $description = 'Apply the answers saved on an ensemble review page to their runs';

    public function handle(AnswerServiceStructureEnsembleQuestion $answerQuestion): int
    {
        $map = $this->readJson(rtrim((string) $this->argument('export'), '/').'/answers-map.json')['items'] ?? null;

        if (! is_array($map)) {
            throw new RuntimeException('The export has no answer map.');
        }

        $plan = array_map(fn (array $answer): array => $this->planned($answer, $map), $this->answers());
        usort($plan, static fn (array $a, array $b): int => strnatcmp($a['id'], $b['id']));

        $this->table(['Answer', 'Run', 'Kind', 'Status'], array_map(
            static fn (array $row): array => [$row['id'], $row['run'] ?? '–', $row['kind'] ?? '–', $row['status']],
            $plan,
        ));

        $ready = array_values(array_filter($plan, static fn (array $row): bool => $row['status'] === 'ready'));
        $blocked = count($plan) - count($ready);

        if (! $this->option('execute')) {
            $this->info(sprintf('%d ready, %d need attention. Nothing applied; pass --execute to apply.', count($ready), $blocked));

            return self::SUCCESS;
        }

        $operator = $this->operator();
        $failed = 0;
        $openByRun = [];

        foreach ($ready as $row) {
            try {
                $result = $answerQuestion->execute(
                    (int) $row['run'],
                    (string) $row['question_id'],
                    (string) $row['kind'],
                    $operator,
                    $row['slot'],
                    $row['sections'],
                    $row['explanation'],
                );
                $openByRun[$row['run']] = count($result['disputes'] ?? []);
                $this->line("{$row['id']}: applied ({$row['kind']})");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$row['id']}: {$exception->getMessage()}");
            }
        }

        foreach ($openByRun as $run => $open) {
            $this->line("Run {$run}: {$open} questions still open");
        }

        $this->info(sprintf('%d applied, %d failed, %d need attention.', count($ready) - $failed, $failed, $blocked));

        return $failed === 0 && $blocked === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $map
     * @return array{id: string, run: int|null, kind: string|null, status: string, question_id: string, slot: int|null, sections: list<array<string, mixed>>, explanation: string}
     */
    private function planned(array $answer, array $map): array
    {
        $id = (string) ($answer['question_id'] ?? '');
        $item = $map[$id] ?? null;
        $unplanned = ['id' => $id, 'run' => null, 'kind' => null, 'question_id' => '', 'slot' => null, 'sections' => [], 'explanation' => ''];

        if (! is_array($item)) {
            return [...$unplanned, 'status' => 'not in this export'];
        }

        $choice = (string) ($answer['choice'] ?? '');
        $meaning = $item['answers'][$choice] ?? null;

        if (! is_array($meaning)) {
            return [...$unplanned, 'run' => (int) $item['run'], 'status' => "unknown choice “{$choice}”"];
        }

        $kind = (string) $meaning['kind'];
        $note = trim((string) ($answer['note'] ?? ''));
        $sections = array_values(array_filter($answer['sections'] ?? [], 'is_array'));
        $row = [
            'id' => $id,
            'run' => (int) $item['run'],
            'kind' => $kind,
            'status' => 'ready',
            'question_id' => (string) $item['question_id'],
            'slot' => isset($meaning['slot']) ? (int) $meaning['slot'] : null,
            'sections' => $kind === 'correct' ? $sections : [],
            'explanation' => trim(($answer['choice_label'] ?? $choice).($note === '' ? '' : ". Note: {$note}")).' [review page]',
        ];

        if ($kind === 'correct' && $sections === []) {
            $row['status'] = 'needs corrected sections from the note';
        }

        if ($kind === 'absent' && $note === '') {
            $row['status'] = 'needs a note saying why there was no sermon';
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function answers(): array
    {
        $rows = $this->readJson((string) $this->argument('answers'));
        $rows = array_is_list($rows) ? $rows : ($rows['docs'] ?? $rows['items'] ?? []);

        return array_values(array_map(
            static fn (array $row): array => is_array($row['data'] ?? null)
                ? [...$row['data'], 'question_id' => $row['data']['question_id'] ?? $row['id'] ?? null]
                : $row,
            array_filter(is_array($rows) ? $rows : [], 'is_array'),
        ));
    }

    private function operator(): User
    {
        $id = $this->option('operator');

        if (is_string($id) && $id !== '') {
            return User::query()->findOrFail((int) $id);
        }

        $admins = User::query()->where('is_admin', true)->get();

        if ($admins->count() !== 1) {
            throw new RuntimeException('Pass --operator: there is not exactly one administrator.');
        }

        return $admins->sole();
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("{$path} does not exist.");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$path} is not a JSON object or list.");
        }

        return $decoded;
    }
}
