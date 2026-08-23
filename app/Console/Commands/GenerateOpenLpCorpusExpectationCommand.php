<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\OpenLpApprovedCorpus;
use App\Services\ChurchService\OpenLpCurationManifest;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * The OpenLP counterpart of `oos:generate-corpus-expectation` — IC5 step 2.
 *
 * Until this existed the census could only ever declare `email`, because
 * declaring `openlp` requires a manifest-derived statement of what the OpenLP
 * corpus was meant to contain and only the Email lane had a producer. See
 * {@see OpenLpApprovedCorpus} for why the expectation cannot be read back out of
 * the staged database, and for the two places the OpenLP lane's derivation
 * differs from Email's.
 *
 * Unlike the Email command this takes no corpus roots: an OpenLP manifest is
 * validated against a single raw archive directory.
 *
 * Delete this one-shot command when the historic import's acceptance index is
 * archived, with the rest of the IC8 retirement surface.
 */
class GenerateOpenLpCorpusExpectationCommand extends Command
{
    protected $signature = 'openlp:generate-corpus-expectation
                            {--manifest= : Path to the approved crockenhill-openlp-curation manifest}
                            {--path= : Directory holding the raw OpenLP .osz archives}
                            {--accepted-holds= : JSON list of {item_key, reason} holds the operator has ruled on}
                            {--output= : JSON output path; writes to storage/scratch when relative}';

    protected $description = 'Derive the approved historic corpus expectation from the OpenLP curation manifest';

    public function handle(OpenLpCurationManifest $manifest, OpenLpApprovedCorpus $corpus): int
    {
        $manifestPath = $this->stringOption('manifest');
        $rawDirectory = $this->stringOption('path');

        if ($manifestPath === null || $rawDirectory === null) {
            $this->error('Both --manifest= and --path= are required. The corpus has no default authority.');

            return self::FAILURE;
        }

        try {
            $plan = $manifest->plan($this->resolvePath($rawDirectory), $this->resolvePath($manifestPath));
            $expectation = $corpus->expectation($plan);
            $holds = $this->acceptedHolds();
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        if ($holds !== []) {
            $approvedItemKeys = array_column($expectation['approved_sources'], 'item_key');

            foreach ($holds as $hold) {
                if (! in_array($hold['item_key'], $approvedItemKeys, true)) {
                    $this->error(
                        "Accepted hold {$hold['item_key']} is not an approved entry in this manifest; ".
                        'it waives nothing and would hide a stale decision.'
                    );

                    return self::FAILURE;
                }
            }

            /**
             * Folded in before hashing, so an acceptance cannot be appended to a
             * produced artifact afterwards without invalidating it.
             */
            $expectation['accepted_holds'] = $holds;
            unset($expectation['expectation_hash']);
            $expectation['expectation_hash'] = CanonicalJson::hash($expectation);
        }

        $identities = count(array_unique(array_map(
            static fn (array $source): string => $source['identity']['date'].' '.$source['identity']['service'],
            $expectation['approved_sources'],
        )));

        try {
            $json = CanonicalJson::encodeReadable($expectation);
        } catch (Throwable $throwable) {
            $this->error("The corpus expectation could not be encoded: {$throwable->getMessage()}");

            return self::FAILURE;
        }

        $output = $this->stringOption('output');

        if ($output === null) {
            $this->line($json);

            return self::SUCCESS;
        }

        $path = str_starts_with($output, '/') ? $output : storage_path("scratch/{$output}");

        if (file_put_contents($path, $json.PHP_EOL) === false) {
            $this->error("Could not write the corpus expectation to {$path}.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Wrote %d approved source(s) covering %d identity(ies) from batch %s to %s.',
            count($expectation['approved_sources']),
            $identities,
            $expectation['batch_key'],
            $path,
        ));

        if ($holds !== []) {
            $this->line(sprintf('  %d accepted hold(s) folded into the expectation.', count($holds)));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{item_key:string, reason:string}>
     */
    private function acceptedHolds(): array
    {
        $path = $this->stringOption('accepted-holds');

        if ($path === null) {
            return [];
        }

        $contents = file_get_contents($this->resolvePath($path));

        if (! is_string($contents)) {
            throw new RuntimeException("Could not read the accepted-holds file: {$path}");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException('The accepted-holds file must contain a JSON list.');
        }

        $holds = [];

        foreach ($decoded as $hold) {
            if (! is_array($hold)
                || ! is_string($hold['item_key'] ?? null)
                || ! is_string($hold['reason'] ?? null)
                || trim($hold['reason']) === '') {
                throw new RuntimeException('Every accepted hold requires an item_key and a non-empty reason.');
            }

            $holds[] = ['item_key' => $hold['item_key'], 'reason' => trim($hold['reason'])];
        }

        usort($holds, static fn (array $left, array $right): int => $left['item_key'] <=> $right['item_key']);

        return $holds;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
