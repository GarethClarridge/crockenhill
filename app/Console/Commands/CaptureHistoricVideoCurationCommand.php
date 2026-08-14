<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\Video\HistoricVideoCurationCapture;
use App\Services\Media\Video\HistoricVideoCurationManifest;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use Throwable;

/**
 * Stage two of video curation: freeze the adjudicated worksheet into the
 * hash-covered manifest.
 *
 * This is the expensive one — it reads every declared file to hash it, which
 * against the real corpus means roughly a terabyte at about 88 MB/s. Run it
 * once, when the worksheet is settled.
 *
 * The manifest it writes is immediately validated, so a capture that produces
 * something the importer would reject fails here rather than at import.
 *
 * Delete once the frozen video manifest has passed production acceptance and
 * the source-retention window on the recordings corpus has expired.
 */
class CaptureHistoricVideoCurationCommand extends Command
{
    protected $signature = 'historic-import:capture-video-curation
        {root : Service recordings root the worksheet was drafted from}
        {--worksheet= : Adjudicated curation worksheet below storage/app/private}
        {--manifest= : Immutable frozen manifest path below storage/app/private}';

    protected $description = 'Hash and freeze an adjudicated historic video curation worksheet into a manifest';

    public function handle(HistoricVideoCurationCapture $captures, HistoricVideoCurationManifest $manifests): int
    {
        try {
            $worksheetPath = PrivateEvidenceFile::resolve(
                $this->option('worksheet'),
                'The historic video curation worksheet',
            );
            $manifestPath = PrivateEvidenceFile::resolve(
                $this->option('manifest'),
                'The historic video curation manifest',
            );
            $root = (string) $this->argument('root');

            $this->info('Hashing every declared recording; this reads the whole corpus.');

            $bar = null;
            $manifest = $captures->capture(
                $root,
                $worksheetPath,
                function (int $captured, int $total) use (&$bar): void {
                    $bar ??= $this->output->createProgressBar($total);
                    $bar->setProgress($captured);
                },
            );
            $bar?->finish();
            $this->newLine(2);

            PrivateEvidenceFile::writeOnce(
                $manifestPath,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
                'The historic video curation manifest',
            );

            // Proving the frozen artifact is acceptable authority is the point of
            // the stage, so validate it here rather than discovering it at import.
            $plan = $manifests->plan($root, $manifestPath);

            $this->info("Frozen {$plan->counts['include']} included and {$plan->counts['exclude']} excluded identities.");
            $this->table(
                ['Count', 'Value'],
                array_map(static fn (string $key, int $value): array => [$key, $value], array_keys($plan->counts), $plan->counts),
            );
            $this->line("Manifest: {$manifestPath}");
            $this->line("Manifest hash: {$plan->manifestHash}");
            $this->line("Plan hash: {$plan->planHash}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
