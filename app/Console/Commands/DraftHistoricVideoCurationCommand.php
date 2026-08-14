<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HistoricVideoCorroborationGrade;
use App\Services\Media\Video\HistoricVideoCurationDraft;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Gives the historic video curation manifest the producer it never had (F66),
 * in the stage that stays cheap to re-run.
 *
 * The manifest is the only source that can corroborate the 2020-03-22 to 2022
 * window, where the hymn workbooks have no sheets and OpenLP has not yet
 * started. Everything earlier has no recording at all and is hand-verified or
 * unpublished; see the readiness remediation plan §13.1.
 *
 * This drafts, it does not approve. Every entry lands as an include with empty
 * editorial facts. Adjudicate the worksheet, then run
 * `historic-import:capture-video-curation` to freeze it into a hashed manifest.
 *
 * Delete alongside the capture command, once the frozen video manifest has
 * passed production acceptance and no further curation pass is possible.
 */
class DraftHistoricVideoCurationCommand extends Command
{
    protected $signature = 'historic-import:draft-video-curation
        {root : Service recordings root to enumerate, laid out as YYYY-MM-DD/Morning|Evening/}
        {--worksheet= : Immutable curation worksheet path below storage/app/private}
        {--batch-key= : Batch key this curation will be approved under}
        {--rule-version= : Approved decision rule version recorded on every entry}
        {--skip-probe : Skip ffprobe, leaving every single-file entry graded "unknown"}';

    protected $description = 'Draft the historic video curation worksheet from the mounted recordings corpus';

    public function handle(HistoricVideoCurationDraft $drafts): int
    {
        try {
            $path = PrivateEvidenceFile::resolve(
                $this->option('worksheet'),
                'The historic video curation worksheet',
            );
            $batchKey = $this->requiredOption('batch-key');
            $ruleVersion = $this->requiredOption('rule-version');
            $probe = ! $this->option('skip-probe');

            if (! $probe) {
                $this->warn('Skipping ffprobe: every single-file entry will be graded "unknown".');
            }

            $bar = null;
            $worksheet = $drafts->draft(
                (string) $this->argument('root'),
                $batchKey,
                $ruleVersion,
                $probe,
                function (int $drafted, int $total) use (&$bar): void {
                    $bar ??= $this->output->createProgressBar($total);
                    $bar->setProgress($drafted);
                },
            );
            $bar?->finish();
            $this->newLine(2);

            PrivateEvidenceFile::writeOnce(
                $path,
                json_encode($worksheet, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
                'The historic video curation worksheet',
            );

            $this->report($worksheet['entries']);
            $this->line("Worksheet: {$path}");
            $this->line('Next: adjudicate every entry, add a reason per exclusion, fill editorial facts,');
            $this->line('then run historic-import:capture-video-curation to hash and freeze it.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("The historic video curation worksheet requires an explicit --{$name}.");
        }

        return trim($value);
    }

    /** @param list<array<string, mixed>> $entries */
    private function report(array $entries): void
    {
        $files = array_sum(array_map(static fn (array $entry): int => count($entry['files']), $entries));
        $this->info(count($entries).' service identities drafted from '.$files.' recordings.');

        $rows = [];

        foreach (HistoricVideoCorroborationGrade::cases() as $grade) {
            $matching = array_filter($entries, static fn (array $entry): bool => $entry['corroboration'] === $grade->value);
            $rows[] = [
                $grade->label(),
                count($matching),
                $grade->corroboratesSongMembership() ? 'songs + service' : 'service only',
            ];
        }

        $this->table(['Corroboration', 'Identities', 'May certify'], $rows);
    }
}
