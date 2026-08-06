<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ChurchServiceSourceRevisionLineageInspector;
use Illuminate\Console\Command;

/**
 * Temporary WP1 deploy preflight. Delete this command and its tests after the source-revision
 * corpus audit has passed in production and the lineage constraint migration has been deployed.
 *
 * Whitelisted into .github/workflows/production-audit.yml so that "must pass in production before
 * the constraint deploys" is something an operator can actually check. That log is public, so the
 * default output is counts per defect kind. The repair text names revision ids and lineage keys —
 * and a lineage key embeds `source_key`, an email message id or an archive filename — so it is
 * behind --details, to be run on the server.
 */
class AuditChurchServiceSourceRevisionLineagesCommand extends Command
{
    protected $signature = 'service-tracking:audit-source-revision-lineages
        {--details : Print the repair text for each defect. Names revision ids and lineage keys, so keep this off when the output leaves the server (e.g. public CI logs)}';

    protected $description = 'Report source-revision lineages that cannot safely receive the unique-leaf constraint';

    public function handle(ChurchServiceSourceRevisionLineageInspector $inspector): int
    {
        $counts = $inspector->issueCounts();
        $total = array_sum($counts);

        if ($total === 0) {
            $this->info('All source-revision lineages have exactly one active leaf.');

            return self::SUCCESS;
        }

        $this->error('Source-revision lineage audit failed. Resolve these records before deploying the lineage constraint:');

        $this->table(
            ['Defect', 'Count'],
            array_map(
                fn (string $kind): array => [$kind, (string) $counts[$kind]],
                array_keys($counts),
            ),
        );

        if (! (bool) $this->option('details')) {
            $this->comment('Re-run with --details on the server to see which revisions are affected. Revision ids and lineage keys are never printed without it.');

            return self::FAILURE;
        }

        foreach ($inspector->issues() as $issue) {
            $this->line("- {$issue}");
        }

        return self::FAILURE;
    }
}
