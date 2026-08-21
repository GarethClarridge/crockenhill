<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\OosApprovedCorpus;
use App\Services\Email\OosCurationManifest;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Produce the manifest-derived corpus expectation the §9.4.6 census reconciles
 * against, closing F1.
 *
 * The census previously took its corpus size from
 * `church.historic_corpus.expected_services` — a scalar an operator typed — and
 * its item-level membership from `services:generate-corpus-membership`, which
 * reads the staged database. Neither can show that an approved entry failed to
 * stage, so the completeness half of RG-A could not fail. This command derives
 * the expectation from the approved manifest and nothing else.
 *
 * Delete this one-shot command when the historic import's acceptance index is
 * archived, with the rest of the IC8 retirement surface.
 */
class GenerateOosCorpusExpectationCommand extends Command
{
    private const DefaultVerbatimRoot = 'scratch/oos-verbatim';

    private const DefaultFormattedRoot = 'scratch/oos';

    protected $signature = 'oos:generate-corpus-expectation
                            {--manifest= : Path to the approved crockenhill-oos-curation manifest}
                            {--verbatim= : Verbatim corpus root (default storage/scratch/oos-verbatim)}
                            {--formatted= : Formatted corpus root (default storage/scratch/oos)}
                            {--accepted-holds= : JSON list of {item_key, reason} holds the operator has ruled on}
                            {--output= : JSON output path; writes to storage/scratch when relative}';

    protected $description = 'Derive the approved historic corpus expectation from the OoS curation manifest';

    public function handle(OosCurationManifest $manifest, OosApprovedCorpus $corpus): int
    {
        $manifestPath = $this->stringOption('manifest');

        if ($manifestPath === null) {
            $this->error('An approved curation manifest is required with --manifest=. The corpus has no default authority.');

            return self::FAILURE;
        }

        try {
            $plan = $manifest->plan(
                $this->resolvePath($this->stringOption('verbatim') ?? storage_path(self::DefaultVerbatimRoot)),
                $this->resolvePath($this->stringOption('formatted') ?? storage_path(self::DefaultFormattedRoot)),
                $this->resolvePath($manifestPath),
            );
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

        // `encodeReadable()` throws rather than returning false, so the failure is caught, not tested.
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
