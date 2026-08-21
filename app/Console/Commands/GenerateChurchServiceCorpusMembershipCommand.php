<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCorpusMembership;
use App\Support\CanonicalJson;
use App\Support\ChurchServiceSourceKey;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Generate the exact, hash-verified membership artifact consumed by the historic census gate.
 *
 * Delete this one-shot command after the final historic import acceptance index is archived.
 */
class GenerateChurchServiceCorpusMembershipCommand extends Command
{
    protected $signature = 'services:generate-corpus-membership
        {--batch-hash= : Exact source-record batch hash}
        {--source= : Source kind to include, for example email or openlp}
        {--output= : JSON output path; writes to storage/scratch when relative}';

    protected $description = 'Generate exact source-item membership for the historic proposal census';

    public function handle(): int
    {
        $batchHash = $this->option('batch-hash');
        $source = $this->option('source');

        if (! is_string($batchHash) || preg_match('/^[a-f0-9]{64}$/', $batchHash) !== 1) {
            $this->error('A valid --batch-hash= SHA-256 value is required.');

            return self::FAILURE;
        }

        $sourceEnum = is_string($source) ? ChurchServiceSource::tryFrom($source) : null;
        if (! $sourceEnum instanceof ChurchServiceSource) {
            $this->error('A valid --source= value is required.');

            return self::FAILURE;
        }

        $records = ChurchServiceSourceRecord::query()
            ->with('churchService')
            ->where('batch_hash', $batchHash)
            ->where('source', $sourceEnum)
            ->orderBy('source_key_hash')
            ->get();

        if ($records->isEmpty()) {
            $this->error('No source records matched the supplied batch and source.');

            return self::FAILURE;
        }

        $items = $records->map(function (ChurchServiceSourceRecord $record): array {
            $service = $record->churchService;

            if ($service === null) {
                throw new RuntimeException("Source record {$record->id} has incomplete membership data.");
            }

            return [
                'source' => $record->source->value,
                'batch_hash' => $record->batch_hash,
                'source_key' => ChurchServiceSourceKey::canonical($record->source_key),
                'input_hash' => $record->input_hash,
                'processing_fingerprint' => $record->processing_fingerprint,
                'identity' => [
                    'date' => $service->date->toDateString(),
                    'service' => $service->service->value,
                ],
            ];
        })->values()->all();

        $membership = [
            'format' => ChurchServiceCorpusMembership::Format,
            'version' => ChurchServiceCorpusMembership::Version,
            'items' => $items,
        ];
        $membership['membership_hash'] = CanonicalJson::hash($membership);

        $output = $this->option('output');
        if (! is_string($output) || trim($output) === '') {
            $this->line((string) json_encode($membership, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $path = str_starts_with($output, '/') ? $output : storage_path("scratch/{$output}");
        if (file_put_contents($path, CanonicalJson::encodeReadable($membership).PHP_EOL) === false) {
            $this->error("Could not write membership artifact to {$path}.");

            return self::FAILURE;
        }

        $this->info("Wrote {$records->count()} source item(s) to {$path}.");

        return self::SUCCESS;
    }
}
