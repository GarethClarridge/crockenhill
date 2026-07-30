<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChurchService;
use App\Services\ChurchService\ChurchServiceConvergenceBackfillService;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * One-shot R8 WP6 convergence backfill.
 *
 * Delete after the production backfill, private parity report acceptance and one normal weekly
 * Email/OpenLP/Livestream cycle complete without unexplained drift.
 */
class BackfillChurchServiceConvergenceCommand extends Command
{
    protected $signature = 'service-tracking:backfill-convergence
        {--shadow-only : Build normalized evidence and report parity without changing canonical service/item rows}
        {--report=private/r8-convergence/wp6-parity.json : Private local-disk report path}';

    protected $description = 'Backfill normalized church-service evidence and emit the R8 WP6 shadow parity report';

    public function __construct(
        private ChurchServiceConvergenceBackfillService $backfillService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reportPath = $this->privateReportPath((string) $this->option('report'));
        $shadowOnly = (bool) $this->option('shadow-only');
        $services = [];

        ChurchService::query()
            ->orderBy('id')
            ->eachById(function (ChurchService $service) use (&$services, $shadowOnly): void {
                $this->info("Processing church service {$service->id}...");
                $services[] = $this->backfillService->backfill($service, $shadowOnly);
            });

        $report = [
            'format' => 'crockenhill-r8-wp6-parity',
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'shadow_only' => $shadowOnly,
            'summary' => [
                'services' => count($services),
                'expected_proposals' => collect($services)->sum('expected_proposals'),
                'normalized_proposals' => collect($services)->sum('normalized_proposals'),
                'expected_assertions' => collect($services)->sum('expected_assertions'),
                'normalized_assertions' => collect($services)->sum('normalized_assertions'),
                'duplicate_source_records' => collect($services)->sum('duplicate_source_records'),
                'services_with_differences' => collect($services)
                    ->filter(fn (array $service): bool => $service['differences'] !== [])
                    ->count(),
            ],
            'services' => $services,
        ];
        $report['gates'] = [
            'pending_proposals_preserved' => $report['summary']['expected_proposals'] === $report['summary']['normalized_proposals'],
            'source_assertions_preserved' => $report['summary']['expected_assertions'] === $report['summary']['normalized_assertions'],
            'no_duplicate_source_records' => $report['summary']['duplicate_source_records'] === 0,
            'all_differences_explained' => collect($services)
                ->flatMap(fn (array $service): array => $service['differences'])
                ->every(fn (array $difference): bool => filled($difference['explanation'] ?? null)),
        ];
        $report['report_hash'] = CanonicalJson::hash($report);

        Storage::disk('local')->put($reportPath, CanonicalJson::encode($report).PHP_EOL);

        $this->newLine();
        $this->comment("Processed {$report['summary']['services']} church services.");
        $this->comment("Parity report: storage/app/{$reportPath}");
        $this->table(
            ['Expected proposals', 'Normalized proposals', 'Expected assertions', 'Normalized assertions', 'Duplicates', 'Differences'],
            [[
                $report['summary']['expected_proposals'],
                $report['summary']['normalized_proposals'],
                $report['summary']['expected_assertions'],
                $report['summary']['normalized_assertions'],
                $report['summary']['duplicate_source_records'],
                $report['summary']['services_with_differences'],
            ]],
        );

        return self::SUCCESS;
    }

    private function privateReportPath(string $path): string
    {
        $normalized = ltrim($path, '/');

        if (
            $path === ''
            || $path !== $normalized
            || str_contains($normalized, '..')
            || ! str_starts_with($normalized, 'private/')
        ) {
            throw new InvalidArgumentException('The parity report must be written below storage/app/private.');
        }

        return $normalized;
    }
}
