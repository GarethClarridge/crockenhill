<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Restamp the self-hash of a private evidence artifact written before the encoder defect was fixed.
 *
 * Artifacts that carry a hash taken over their own contents were written with `json_encode()` flags
 * that omitted `JSON_PRESERVE_ZERO_FRACTION`, while {@see CanonicalJson::hash()} includes it. Any
 * float landing on a whole number — a rate over a perfect population, `1.0` — was therefore
 * persisted as `1`, re-read as an `int`, and could never reproduce the hash recorded beside it.
 * Corpus artifacts escaped only because they carry no computed rates.
 *
 * This command exists because the fix alone is not enough: enforcing verification refuses every
 * artifact produced before it, including the accepted Delivery 6 evidence, and no rerun can recover
 * the original bytes. Restamping is therefore a **one-time re-baseline forced by an encoder defect,
 * not a fresh attestation**: it re-derives the hash from whatever the file now holds and cannot
 * distinguish encoder loss from an edit. What it does preserve is every *other* binding — the
 * corpus hash, the parser surface hash and the per-source input hashes are untouched and still
 * checked by the scorer, and a rescore reproducing the previously reported metrics is the
 * independent evidence that the content itself did not move.
 *
 * Delete this alongside the rest of the evaluation tooling at historic-import IC8 closeout. It must
 * never be used to bless an artifact whose contents were deliberately changed.
 */
class RebaselineHashedEvidenceArtifactCommand extends Command
{
    protected $signature = 'evidence:rebaseline-hash
        {--path=* : Private artifact to restamp}
        {--key= : Self-hash key to recompute (evidence_hash, score_hash, corpus_hash)}
        {--apply : Write the restamped artifact; without this the command only reports}';

    protected $description = 'Recompute the self-hash of a private evidence artifact written before the encoder fix';

    public function handle(): int
    {
        $paths = $this->option('path');
        $key = $this->option('key');

        if ($paths === []) {
            $this->error('At least one --path is required.');

            return self::FAILURE;
        }

        if (! is_string($key) || $key === '') {
            $this->error('A --key naming the self-hash field is required.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $changed = 0;

        foreach ($paths as $path) {
            try {
                $changed += $this->rebaseline((string) $path, (string) $key, $apply) ? 1 : 0;
            } catch (Throwable $exception) {
                $this->error("{$path}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->line($apply
            ? "Restamped {$changed} artifact(s)."
            : "{$changed} artifact(s) would be restamped. Re-run with --apply to write them.");

        return self::SUCCESS;
    }

    private function rebaseline(string $path, string $key, bool $apply): bool
    {
        if (! is_file($path)) {
            throw new RuntimeException('No such artifact.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Artifact is not a JSON object.');
        }

        /** @var mixed $recorded */
        $recorded = $decoded[$key] ?? null;

        if (! is_string($recorded)) {
            throw new RuntimeException("Artifact carries no string {$key}.");
        }

        $withoutHash = $decoded;
        unset($withoutHash[$key]);
        $recomputed = CanonicalJson::hash($withoutHash);
        $name = basename($path);

        if (hash_equals($recorded, $recomputed)) {
            $this->line("  {$name}: already verifies, left alone.");

            return false;
        }

        $this->line("  {$name}: {$recorded} -> {$recomputed}");

        if (! $apply) {
            return true;
        }

        $decoded[$key] = $recomputed;

        // Written through the same helper the fixed writers now use, so the restamped file is one
        // this hash can actually be checked against on the next read.
        if (file_put_contents($path, CanonicalJson::encodeReadable($decoded).PHP_EOL) === false) {
            throw new RuntimeException('Could not write the restamped artifact.');
        }

        if (! chmod($path, 0600)) {
            throw new RuntimeException('Could not restore private permissions on the restamped artifact.');
        }

        return true;
    }
}
