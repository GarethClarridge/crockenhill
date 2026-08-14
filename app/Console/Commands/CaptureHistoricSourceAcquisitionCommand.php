<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\HistoricSourceCustodyCapture;
use App\Services\Import\HistoricSourceDispositionWorksheet;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Step two of source acquisition: sign what was observed.
 *
 * Produces the custody artifact `historic-import:verify-source-acquisition`
 * consumes. Every observable fact is measured from the two copies; the facts
 * file supplies only what no filesystem can answer — the physical drive's
 * identity and health, the malware scan, the retention window and the accepted
 * capacity plan.
 *
 * Delete after the historic source custody artifact has passed production
 * acceptance and its source-retention window has expired.
 */
class CaptureHistoricSourceAcquisitionCommand extends Command
{
    protected $signature = 'historic-import:capture-source-acquisition
        {worksheet : Completed disposition worksheet from historic-import:draft-source-dispositions}
        {facts : Operator-supplied physical source, malware scan, retention and capacity facts}
        {evidence-copy : Protected metadata-faithful evidence copy}
        {working-copy : Protected materialised processing copy}
        {--custody= : Immutable custody artifact path below storage/app/private}';

    protected $description = 'Capture and sign the historic source custody artifact from two protected copies';

    public function handle(
        HistoricSourceDispositionWorksheet $worksheets,
        HistoricSourceCustodyCapture $capture,
    ): int {
        try {
            $path = PrivateEvidenceFile::resolve($this->option('custody'), 'The historic source custody artifact');
            $worksheet = $this->readJson((string) $this->argument('worksheet'), 'disposition worksheet');
            $facts = $this->readJson((string) $this->argument('facts'), 'acquisition facts');
            $key = config('media-processing.historic_import.evidence_signing_key');

            if (! is_string($key) || $key === '') {
                throw new RuntimeException('Historic source custody cannot be signed: no evidence signing key is configured.');
            }

            $captured = $capture->capture(
                $worksheets->decisions($worksheet),
                $facts,
                (string) $this->argument('evidence-copy'),
                (string) $this->argument('working-copy'),
                $key,
            );

            PrivateEvidenceFile::writeOnce(
                $path,
                json_encode($captured['custody'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
                'The historic source custody artifact',
            );

            $this->reportUnclaimableXattrs($captured['unclaimable_xattrs']);

            $custody = $captured['custody'];

            $this->info("Captured custody for {$custody['capacity_plan']['source_bytes']} observed source bytes.");
            $this->line("Custody: {$path}");
            /**
             * The worksheet is where D5's written reasons live, and the custody
             * schema has no room for them. Its digest is printed so the two can
             * be filed as one piece of evidence.
             */
            $this->line('Worksheet SHA-256: '.hash_file('sha256', (string) $this->argument('worksheet')));
            $this->line('Next: historic-import:verify-source-acquisition against the same two copies.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param list<string> $unclaimable */
    private function reportUnclaimableXattrs(array $unclaimable): void
    {
        if ($unclaimable === []) {
            return;
        }

        $this->warn(
            'These extended attributes are not present and equal on both copies, so they cannot be claimed and are '
            .'not covered by the custody signature:'
        );

        foreach ($unclaimable as $attribute) {
            $this->warn("  {$attribute}");
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Historic source {$label} is missing: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Historic source {$label} must be a JSON object.");
        }

        return $decoded;
    }
}
