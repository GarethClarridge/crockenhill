<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\HistoricSourceAcquisitionVerifier;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete after the historic source custody artifact has passed production
 * acceptance and its source-retention window has expired.
 */
class VerifyHistoricSourceAcquisitionCommand extends Command
{
    private const string Artifact = 'The historic source acquisition report';

    protected $signature = 'historic-import:verify-source-acquisition
        {custody : Signed custody JSON captured before corpus access}
        {evidence-copy : Protected metadata-faithful evidence copy}
        {working-copy : Protected materialised processing copy}
        {--report= : Immutable report path below storage/app/private}';

    protected $description = 'Verify complete historic source custody, independent copies and whole-tree inventory';

    public function handle(HistoricSourceAcquisitionVerifier $verifier): int
    {
        try {
            $custody = $this->readCustody((string) $this->argument('custody'));
            $key = config('media-processing.historic_import.evidence_signing_key');

            if (! is_string($key)) {
                throw new RuntimeException('Historic source evidence signing key is not configured.');
            }

            $report = $verifier->verify(
                $custody,
                (string) $this->argument('evidence-copy'),
                (string) $this->argument('working-copy'),
                $key,
            );
            $path = PrivateEvidenceFile::resolve($this->option('report'), self::Artifact);

            PrivateEvidenceFile::writeOnce(
                $path,
                json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
                self::Artifact,
            );
            $pathCount = $report['copies']['working']['path_count'] ?? null;

            if (! is_int($pathCount)) {
                throw new RuntimeException('Historic source acquisition report lost its path count.');
            }

            $this->info("Historic source acquisition verified for {$pathCount} paths.");
            $this->line("Report: {$path}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function readCustody(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Historic source custody artifact is missing.');
        }

        $custody = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($custody)) {
            throw new RuntimeException('Historic source custody artifact must be a JSON object.');
        }

        return $custody;
    }
}
