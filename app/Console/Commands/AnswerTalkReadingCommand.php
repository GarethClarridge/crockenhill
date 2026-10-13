<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServiceSection;
use App\Services\ChurchService\TalkReadingPairing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Record whether a short talk is about the reading directly before it, and so is cut with it
 * ({@see TalkReadingPairing}). The talk's candidate is re-cut the next time its run prepares
 * candidates, and an approval given before the answer no longer stands.
 *
 * Membership is all or nothing, as for holds: a named talk that is not directly after a reading
 * stops the whole run before anything is written.
 */
class AnswerTalkReadingCommand extends Command
{
    protected $signature = 'service:answer-talk-reading
        {--include=* : Talk section IDs that are about the reading before them, so cut with it}
        {--alone=* : Talk section IDs that stand on their own}
        {--evidence= : Where the answers were given, such as a listening page}
        {--execute : Record the answers; without this option the command is a dry run}';

    protected $description = 'Record whether short talks are cut with the reading directly before them';

    public function handle(TalkReadingPairing $pairing): int
    {
        // `+`, not a spread: a spread renumbers the integer keys that name the talks.
        $answers = array_fill_keys($this->ids('include'), true) + array_fill_keys($this->ids('alone'), false);
        $evidence = trim((string) $this->option('evidence'));

        if ($answers === []) {
            $this->error('Name at least one talk with --include or --alone.');

            return self::FAILURE;
        }

        $both = array_intersect($this->ids('include'), $this->ids('alone'));

        if ($both !== []) {
            $this->error('Named both to include and to stand alone: '.implode(', ', $both).'.');

            return self::FAILURE;
        }

        if ($evidence === '') {
            $this->error('--evidence is required: an answer nobody can trace cannot be revisited.');

            return self::FAILURE;
        }

        $talks = ServiceSection::query()->whereKey(array_keys($answers))->orderBy('id')->get()->keyBy('id');
        $rows = [];
        $refused = [];

        foreach ($answers as $id => $include) {
            $talk = $talks->get($id);
            $reading = $talk instanceof ServiceSection ? $pairing->readingBefore($talk) : null;

            if ($reading === null) {
                $refused[] = $talk === null ? "Section {$id} does not exist." : "Section {$id} is not a short talk directly after a reading.";

                continue;
            }

            $rows[] = [$id, $talk->media_processing_log_id, $talk->title, $reading->metadata->readingReference ?? "§{$reading->id}", $include ? 'with the reading' : 'alone'];
        }

        if ($refused !== []) {
            foreach ($refused as $line) {
                $this->error($line);
            }
            $this->error('Nothing was recorded: the named membership must be exact.');

            return self::FAILURE;
        }

        $this->table(['Talk', 'Run', 'Title', 'Reading before it', 'Cut'], $rows);

        if (! (bool) $this->option('execute')) {
            $this->warn('DRY RUN: nothing was written. Re-run with --execute to record these answers.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($answers, $talks, $pairing, $evidence): void {
            foreach ($talks as $talk) {
                $pairing->record($talk, $answers[$talk->id], $evidence);
            }
        });

        $this->info(sprintf('Recorded %d answer(s).', count($answers)));

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function ids(string $option): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (mixed $id): int => (int) $id, (array) $this->option($option)))));
    }
}
