<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sermon\SermonPromotionBundleImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Temporary R8 one-shot. Delete this command and its tests after the R8 ledger has no
 * unresolved/promote entries and the production idempotency run passes.
 */
class ImportSermonPromotionBundleCommand extends Command
{
    protected $signature = 'sermons:import-promotion-bundle
                            {--path= : Private promotion bundle path under application storage}
                            {--verify-hashes : Stream every referenced asset and verify its SHA-256}
                            {--apply : Apply create entries after a clean preflight; dry-run is the default}';

    protected $description = 'Preflight or create missing legacy MP3 sermons from a private portable bundle';

    public function handle(SermonPromotionBundleImporter $importer): int
    {
        try {
            $path = $this->stringOption('path');

            if ($path === null) {
                throw new RuntimeException('The --path option is required.');
            }

            $result = $importer->import(
                path: $path,
                verifyHashes: (bool) $this->option('verify-hashes'),
                apply: (bool) $this->option('apply'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Local ID', 'Classification', 'Existing production ID', 'Reason'],
            array_map(
                static fn (array $entry): array => [
                    (string) $entry['local_id'],
                    $entry['classification'],
                    $entry['existing_sermon_id'] === null ? '' : (string) $entry['existing_sermon_id'],
                    $entry['reason'],
                ],
                $result['entries'],
            ),
        );

        $this->line("Already present: {$result['counts']['already_present']}");
        $this->line("Would create: {$result['counts']['create']}");
        $this->line("Conflicts: {$result['counts']['conflict']}");
        $this->line("Created: {$result['counts']['created']}");

        if ($result['counts']['conflict'] > 0) {
            $this->error('Promotion bundle has conflicts. No database changes were written.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('apply')) {
            $this->warn('Dry run only. Re-run with --apply after reviewing every classification.');

            return self::SUCCESS;
        }

        if (! $result['applied']) {
            $this->error('Promotion bundle was not applied.');

            return self::FAILURE;
        }

        $this->info('Promotion bundle applied successfully.');

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
