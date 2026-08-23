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
 * One certificate spans every source kind the census declares. The artifact has
 * always been able to: each item carries its own `source` and `batch_hash`, and
 * {@see ChurchServiceCorpusMembership::certify()} reports the distinct kinds it
 * found so the gate can refuse a certificate that misses a declared one. Only this
 * producer was single-lane, which is why an `email,openlp` census could not be
 * assembled at all. `--source` and `--batch-hash` are therefore repeatable and
 * paired in the order given.
 *
 * Delete this one-shot command after the final historic import acceptance index is archived.
 */
class GenerateChurchServiceCorpusMembershipCommand extends Command
{
    protected $signature = 'services:generate-corpus-membership
        {--batch-hash=* : Exact source-record batch hash; repeat once per source, in the same order}
        {--source=* : Source kind to include, for example email or openlp; repeatable}
        {--output= : JSON output path; writes to storage/scratch when relative}';

    protected $description = 'Generate exact source-item membership for the historic proposal census';

    public function handle(): int
    {
        $lanes = $this->lanes();

        if ($lanes === null) {
            return self::FAILURE;
        }

        $items = [];

        foreach ($lanes as [$sourceEnum, $batchHash]) {
            $records = ChurchServiceSourceRecord::query()
                ->with('churchService')
                ->where('batch_hash', $batchHash)
                ->where('source', $sourceEnum)
                ->orderBy('source_key_hash')
                ->get();

            if ($records->isEmpty()) {
                $this->error("No source records matched batch {$batchHash} for source {$sourceEnum->value}.");

                return self::FAILURE;
            }

            foreach ($records as $record) {
                $service = $record->churchService;

                if ($service === null) {
                    throw new RuntimeException("Source record {$record->id} has incomplete membership data.");
                }

                $items[] = [
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
            }
        }

        /**
         * Ordered by the same key the certificate is identified by, so the hash is a
         * function of the certified set and not of the order the lanes were named.
         */
        usort($items, static fn (array $left, array $right): int => [$left['source'], $left['source_key']]
            <=> [$right['source'], $right['source_key']]);

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

        $this->info(sprintf(
            'Wrote %d source item(s) across %s to %s.',
            count($items),
            implode(', ', array_values(array_unique(array_column($items, 'source')))),
            $path,
        ));

        return self::SUCCESS;
    }

    /**
     * The (source, batch hash) pairs to certify, or null once an error is reported.
     *
     * Pairing is positional: the nth `--source` belongs with the nth `--batch-hash`.
     * A count mismatch is refused rather than zipped short, because a silently
     * dropped lane produces a certificate that looks complete and is not.
     *
     * @return list<array{0:ChurchServiceSource,1:string}>|null
     */
    private function lanes(): ?array
    {
        /** @var list<string> $batchHashes */
        $batchHashes = array_values((array) $this->option('batch-hash'));
        /** @var list<string> $sources */
        $sources = array_values((array) $this->option('source'));

        if ($sources === [] || $batchHashes === []) {
            $this->error('At least one --source= and matching --batch-hash= are required.');

            return null;
        }

        if (count($sources) !== count($batchHashes)) {
            $this->error(sprintf(
                'Given %d --source= value(s) and %d --batch-hash= value(s). Each source needs its own batch hash, in the same order.',
                count($sources),
                count($batchHashes),
            ));

            return null;
        }

        $lanes = [];
        $seen = [];

        foreach ($sources as $index => $source) {
            $sourceEnum = ChurchServiceSource::tryFrom($source);

            if (! $sourceEnum instanceof ChurchServiceSource) {
                $this->error("'{$source}' is not a valid --source= value.");

                return null;
            }

            if (isset($seen[$sourceEnum->value])) {
                $this->error("Source {$sourceEnum->value} is named twice; one batch certifies one lane.");

                return null;
            }

            $batchHash = $batchHashes[$index];

            if (preg_match('/\A[a-f0-9]{64}\z/', $batchHash) !== 1) {
                $this->error("'{$batchHash}' is not a valid --batch-hash= SHA-256 value.");

                return null;
            }

            $seen[$sourceEnum->value] = true;
            $lanes[] = [$sourceEnum, $batchHash];
        }

        return $lanes;
    }
}
