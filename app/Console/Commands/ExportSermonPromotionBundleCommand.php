<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sermon\SermonPromotionBundleExporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Temporary R8 one-shot. Delete this command and its tests after the R8 ledger has no
 * unresolved/promote entries and the production idempotency run passes.
 */
class ExportSermonPromotionBundleCommand extends Command
{
    protected $signature = 'sermons:export-promotion-bundle
                            {--ids= : Comma-separated local sermon IDs approved by the private R8 ledger}
                            {--output= : Private JSON output path under application storage}';

    protected $description = 'Export selected legacy MP3 sermons as a private, portable, create-only promotion bundle';

    public function handle(SermonPromotionBundleExporter $exporter): int
    {
        try {
            $sermonIds = $this->sermonIds();
            $output = $this->stringOption('output');

            if ($output === null) {
                throw new RuntimeException('The --output option is required.');
            }

            $result = $exporter->export($sermonIds, $output);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Sermon promotion bundle exported.');
        $this->line("Sermons: {$result['sermon_count']}");
        $this->line("Private path: {$result['path']}");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function sermonIds(): array
    {
        $ids = $this->stringOption('ids');

        if ($ids === null) {
            throw new RuntimeException('The --ids option is required.');
        }

        $parts = preg_split('/\s*,\s*/', $ids);

        if (! is_array($parts) || $parts === []) {
            throw new RuntimeException('The --ids option must contain comma-separated positive integers.');
        }

        $sermonIds = [];

        foreach ($parts as $part) {
            if ($part === '' || ! ctype_digit($part) || (int) $part < 1) {
                throw new RuntimeException('The --ids option must contain comma-separated positive integers.');
            }

            $sermonIds[] = (int) $part;
        }

        return array_values(array_unique($sermonIds));
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
