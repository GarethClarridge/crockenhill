<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\HistoricSourceDispositionWorksheet;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use Throwable;

/**
 * Step one of source acquisition: enumerate, so the operator can adjudicate.
 *
 * Read-only against the copy, and it decides nothing — every drafted
 * disposition is null. Fill the worksheet in, add one written reason per
 * disposition class, then run `historic-import:capture-source-acquisition`.
 *
 * Delete after the historic source custody artifact has passed production
 * acceptance and its source-retention window has expired.
 */
class DraftHistoricSourceDispositionsCommand extends Command
{
    protected $signature = 'historic-import:draft-source-dispositions
        {copy : Protected source copy to enumerate}
        {--worksheet= : Immutable worksheet path below storage/app/private}';

    protected $description = 'Draft the complete whole-tree disposition worksheet for one historic source copy';

    public function handle(HistoricSourceDispositionWorksheet $worksheets): int
    {
        try {
            $path = PrivateEvidenceFile::resolve(
                $this->option('worksheet'),
                'The historic source disposition worksheet',
            );
            $worksheet = $worksheets->draft((string) $this->argument('copy'));

            PrivateEvidenceFile::writeOnce(
                $path,
                json_encode($worksheet, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
                'The historic source disposition worksheet',
            );

            $this->info("Drafted {$worksheet['path_count']} paths awaiting an explicit disposition.");
            $this->line("Worksheet: {$path}");
            $this->line('Next: decide every path, add a reason per disposition, then capture custody.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
