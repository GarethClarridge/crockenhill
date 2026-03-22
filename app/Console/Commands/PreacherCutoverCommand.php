<?php

namespace App\Console\Commands;

use App\Services\PreacherCutoverService;
use Illuminate\Console\Command;

class PreacherCutoverCommand extends Command
{
    protected $signature = 'preachers:cutover
        {--dry-run : Preview changes without executing}
        {--alias-file= : Path to optional JSON alias mapping file}';

    protected $description = 'Backfill canonical Preacher records from legacy sermon preacher strings';

    public function handle(PreacherCutoverService $preacherCutoverService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        $result = $preacherCutoverService->run($dryRun, $this->loadAliasMap());
        $summary = $result['summary'];

        // Summary
        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Distinct preacher strings', $summary['distinct_names']],
                ['Preachers created', $summary['preachers_created']],
                ['Aliases created', $summary['aliases_created']],
                ['Sermons linked', $summary['sermons_linked']],
                ['Sermons defaulted to Visiting Speaker', $summary['sermons_defaulted']],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function loadAliasMap(): array
    {
        $aliasFile = $this->option('alias-file');

        if (! $aliasFile || ! file_exists($aliasFile)) {
            return [];
        }

        $contents = file_get_contents($aliasFile);

        if ($contents === false) {
            $this->warn("Could not read alias file: {$aliasFile}");

            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $this->warn("Invalid JSON in alias file: {$aliasFile}");

            return [];
        }

        // Normalize keys to lowercase
        $map = [];
        foreach ($decoded as $variant => $canonical) {
            $map[strtolower(trim($variant))] = $canonical;
        }

        return $map;
    }
}
