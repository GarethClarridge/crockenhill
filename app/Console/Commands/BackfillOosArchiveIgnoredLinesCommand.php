<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\BackfillOosArchiveIgnoredLines;
use Illuminate\Console\Command;

/**
 * Recovers `ignored_lines` for archive sources parsed before the field was persisted, from the
 * annotations the parse cache already holds. Makes no model calls.
 *
 * Dry-run by default: this writes into the banked evidence the whole historic lane replays from,
 * so the counts are reviewed before anything is written.
 *
 * Deleted with the rest of the historic import surface at IC8 closeout.
 */
class BackfillOosArchiveIgnoredLinesCommand extends Command
{
    protected $signature = 'oos:backfill-archive-ignored-lines
        {--apply : Write the recovered ignored lines; without this the command only reports}';

    protected $description = 'Recover ignored_lines for archive sources from their banked annotations, without model calls';

    public function handle(BackfillOosArchiveIgnoredLines $backfill): int
    {
        $apply = (bool) $this->option('apply');
        $result = $backfill->backfill($apply);

        $this->table(['Measure', 'Count'], [
            ['Sources with a parse cache', $result['examined']],
            ['Already carrying ignored lines', $result['already_present']],
            [$apply ? 'Backfilled' : 'Would backfill', $result['backfilled']],
            ['Ignored lines recovered', $result['lines']],
            ['Skipped', count($result['skipped'])],
        ]);

        if ($result['skipped'] !== []) {
            /**
             * Enumerated rather than counted. A skipped source keeps a parse the portable path
             * still refuses, so it is outstanding review work, and a bare total would hide which
             * sources carry it.
             */
            $this->warn('Skipped sources, each with the reason its annotations could not be replayed:');
            $this->table(
                ['Message ID', 'Reason'],
                array_map(
                    static fn (array $skip): array => [$skip['message_id'], $skip['reason']],
                    $result['skipped'],
                ),
            );
        }

        if (! $apply) {
            $this->info('Dry run: nothing was written. Re-run with --apply to write.');
        }

        return self::SUCCESS;
    }
}
