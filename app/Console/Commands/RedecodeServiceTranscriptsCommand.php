<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceTranscriptRedecoder;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;

/**
 * H10b's expensive half: re-decode exact runs into private, create-once artifacts.
 *
 * Writes nothing to the database or to a run's artifacts. Resumable per run: a run
 * whose artifact already exists is skipped, because the staging drive detaches and a
 * bulk decode will be interrupted. Membership is always explicit; there is no "all".
 */
class RedecodeServiceTranscriptsCommand extends Command
{
    protected $signature = 'service:redecode-transcripts
        {runs* : Exact media processing log IDs}
        {--output-dir= : Absolute directory for one private JSON artifact per run}';

    protected $description = 'Re-decode exact historic runs with current decoder settings into side artifacts (writes nothing to the runs)';

    public function handle(ServiceTranscriptRedecoder $redecoder): int
    {
        $outputDir = (string) $this->option('output-dir');

        if (! str_starts_with($outputDir, '/') || ! is_dir($outputDir)) {
            $this->components->error('--output-dir must be an existing absolute directory.');

            return self::FAILURE;
        }

        $rows = [];
        $unassessable = 0;

        foreach (array_unique(array_map('intval', (array) $this->argument('runs'))) as $runId) {
            $path = sprintf('%s/run-%d.json', rtrim($outputDir, '/'), $runId);

            if (file_exists($path)) {
                $rows[] = [$runId, 'skipped', 'artifact already exists'];

                continue;
            }

            $run = MediaProcessingLog::find($runId);
            $result = $run instanceof MediaProcessingLog
                ? $redecoder->redecode($run)
                : ['outcome' => 'unassessable', 'reason' => 'run not found'];

            if ($result['outcome'] === 'unassessable') {
                $unassessable++;
                $rows[] = [$runId, 'unassessable', $result['reason']];

                continue;
            }

            if (! $this->writeOnce($path, CanonicalJson::encodeReadable($result['artifact']).PHP_EOL)) {
                $this->components->error("Could not create private artifact {$path}.");

                return self::FAILURE;
            }

            $rows[] = [$runId, 'decoded', sprintf('%.1fs', $result['artifact']['decode']['seconds'])];
        }

        $this->table(['Run', 'Outcome', 'Detail'], $rows);

        return $unassessable === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Create-once, private from the first byte: `x` refuses an existing file even
     * when another process wrote it after the skip check above.
     */
    private function writeOnce(string $path, string $contents): bool
    {
        $previousUmask = umask(0077);
        $handle = @fopen($path, 'x');
        umask($previousUmask);

        if ($handle === false) {
            return false;
        }

        try {
            return fwrite($handle, $contents) === strlen($contents);
        } finally {
            fclose($handle);
        }
    }
}
