<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ChurchServiceSourceRevisionLineageInspector;
use Illuminate\Console\Command;

/**
 * Temporary WP1 deploy preflight. Delete this command and its tests after the source-revision
 * corpus audit has passed in production and the lineage constraint migration has been deployed.
 */
class AuditChurchServiceSourceRevisionLineagesCommand extends Command
{
    protected $signature = 'service-tracking:audit-source-revision-lineages';

    protected $description = 'Report source-revision lineages that cannot safely receive the unique-leaf constraint';

    public function handle(ChurchServiceSourceRevisionLineageInspector $inspector): int
    {
        $issues = $inspector->issues();

        if ($issues === []) {
            $this->info('All source-revision lineages have exactly one active leaf.');

            return self::SUCCESS;
        }

        $this->error('Source-revision lineage audit failed. Resolve these records before deploying the lineage constraint:');

        foreach ($issues as $issue) {
            $this->line("- {$issue}");
        }

        return self::FAILURE;
    }
}
