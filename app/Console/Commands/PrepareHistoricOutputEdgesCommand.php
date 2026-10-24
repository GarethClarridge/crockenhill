<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\TranscribeOutputEdges;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\PrepareOutputEdgeEvidence;
use Illuminate\Console\Command;

/**
 * Banks a completed run's output-edge evidence in place ({@see PrepareOutputEdgeEvidence}): the
 * edge decodes and the structure-model questions current code asks of its cuts. Unlike
 * {@see TranscribeOutputEdges} it leaves the run's status alone and cuts nothing, so a
 * re-cut stays a separate, deliberate step. Each run is named; an unknown one refuses them all.
 *
 * Deletion trigger: delete after IC8 closeout once historic output-edge evidence, acceptance
 * and the final re-cuts are complete and the historic import tooling is retired.
 */
class PrepareHistoricOutputEdgesCommand extends Command
{
    protected $signature = 'historic-import:prepare-output-edges
        {runs* : Media processing log IDs}
        {--dry-run : List the runs without preparing anything}';

    protected $description = 'Bank the edge decodes and edge questions for completed runs, without changing their status or cutting';

    public function handle(PrepareOutputEdgeEvidence $evidence): int
    {
        /** @var list<int> $runIds */
        $runIds = array_values(array_map(static fn (mixed $id): int => (int) $id, (array) $this->argument('runs')));
        $runs = MediaProcessingLog::query()->whereKey($runIds)->get()->keyBy('id');
        $unknown = array_values(array_filter($runIds, static fn (int $id): bool => ! $runs->has($id)));
        if ($unknown !== []) {
            $this->error('Unknown run(s): '.implode(', ', $unknown).'. Nothing was prepared.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($runIds as $runId) {
            /** @var MediaProcessingLog $run */
            $run = $runs->get($runId);
            if ($this->option('dry-run')) {
                $this->line(sprintf('run #%d: would prepare (status %s)', $run->id, $run->status->value));

                continue;
            }
            $started = microtime(true);
            try {
                $summary = $evidence->prepare($run);
            } catch (\Throwable $exception) {
                $failed++;
                $this->error(sprintf('run #%d: failed — %s', $run->id, $exception->getMessage()));

                continue;
            }
            $this->line(sprintf('run #%d: prepared in %.0f s %s', $run->id, microtime(true) - $started, json_encode($summary, JSON_THROW_ON_ERROR)));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
