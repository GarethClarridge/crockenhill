<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HistoricImportClassification;
use App\Models\ChurchService;
use App\Services\ChurchService\CurrentEraChurchServiceReprojection;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * One-shot PR16 current-era projection repair.
 *
 * Every existing service is in scope, including any the historic import already converged:
 * the repaired projector is pure, so a converged service simply reports already_present.
 *
 * Delete after the accepted current-era corpus diff, B13 proposal triage, exact auditor and
 * second no-op rehearsal prove that the repaired projection is stable in production.
 */
class ReprojectCurrentEraChurchServicesCommand extends Command
{
    protected $signature = 'service-tracking:reproject-current-era
        {--apply : Apply the repaired pure projection after reviewing the dry-run report}
        {--from-service-id= : Resume a stopped run from this church service id}
        {--report=private/historic-archive/current-era-reprojection.json : Private local-disk report path}';

    protected $description = 'Re-project current-era church services and report exact item-level differences';

    public function handle(CurrentEraChurchServiceReprojection $reprojection): int
    {
        try {
            $reportPath = $this->privateReportPath((string) $this->option('report'));
            $fromServiceId = $this->fromServiceId();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $services = [];
        $failure = null;
        $lastCompletedServiceId = null;

        ChurchService::query()
            ->when($fromServiceId !== null, fn (Builder $query): Builder => $query->where('id', '>=', $fromServiceId))
            ->orderBy('id')
            ->eachById(function (ChurchService $service) use ($reprojection, $apply, &$services, &$failure, &$lastCompletedServiceId): bool {
                $this->line("Re-projecting {$service->date->toDateString()} {$service->service->value}...");

                try {
                    $services[] = $reprojection->reproject($service, $apply);
                } catch (Throwable $exception) {
                    // Stop on the first hard failure, but keep the record of everything
                    // already written so a partial production run stays auditable and
                    // can be resumed from the service that stopped it.
                    $failure = [
                        'church_service_id' => $service->getKey(),
                        'identity' => "{$service->date->toDateString()}|{$service->service->value}",
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ];

                    return false;
                }

                $lastCompletedServiceId = $service->getKey();

                return true;
            });

        $report = [
            'format' => 'crockenhill-current-era-reprojection',
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'applied' => $apply,
            'summary' => [
                'services' => count($services),
                'already_present' => $this->classified($services, HistoricImportClassification::AlreadyPresent),
                'create' => $this->classified($services, HistoricImportClassification::Create),
                'safe_enrichment' => $this->classified($services, HistoricImportClassification::SafeEnrichment),
                'blocked_difference' => $this->classified($services, HistoricImportClassification::BlockedDifference),
                'conflict' => $this->classified($services, HistoricImportClassification::Conflict),
                'services_with_item_differences' => collect($services)
                    ->filter(fn (array $service): bool => $service['item_differences'] !== [])
                    ->count(),
                'services_with_residual_differences' => collect($services)
                    ->filter(fn (array $service): bool => $service['residual_item_differences'] !== []
                        || $service['residual_service_differences'] !== [])
                    ->count(),
                'b13_proposals_reopened' => collect($services)->sum('b13_proposals_reopened'),
                'b13_proposals_pending_reopening' => collect($services)->sum('b13_proposals_pending_reopening'),
            ],
            'failure' => $failure,
            'resume' => [
                'last_completed_service_id' => $lastCompletedServiceId,
                'resume_from_service_id' => $failure === null ? null : $failure['church_service_id'],
            ],
            'services' => $services,
        ];
        $report['gates'] = [
            'run_completed' => $failure === null,
            // A service reported as an exact match while carrying item-level differences
            // is the aggregate-equal/item-different failure the plan forbids.
            'differences_classified_item_level' => collect($services)->every(
                fn (array $service): bool => $service['classification'] !== HistoricImportClassification::AlreadyPresent->value
                    || ($service['item_differences'] === [] && $service['service_differences'] === []),
            ),
            'applied_projection_is_exact' => $report['summary']['services_with_residual_differences'] === 0,
            'false_acceptances_reopened' => ! $apply
                || $report['summary']['b13_proposals_pending_reopening'] === 0,
        ];
        $report['report_hash'] = CanonicalJson::hash($report);

        Storage::disk('local')->put($reportPath, CanonicalJson::encode($report).PHP_EOL);
        $this->info("Current-era re-projection report: storage/app/{$reportPath}");

        $failedGates = array_keys(array_filter($report['gates'], static fn (bool $passed): bool => ! $passed));

        if ($failedGates !== []) {
            $this->error('Re-projection gates failed: '.implode(', ', $failedGates).'.');

            if ($failure !== null) {
                $this->error("Stopped on {$failure['identity']}: {$failure['message']}");
                $this->comment("Resume with --from-service-id={$failure['church_service_id']} once the cause is fixed.");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $services
     */
    private function classified(array $services, HistoricImportClassification $classification): int
    {
        return collect($services)->where('classification', $classification->value)->count();
    }

    private function fromServiceId(): ?int
    {
        $option = $this->option('from-service-id');

        if ($option === null || $option === '') {
            return null;
        }

        if (! ctype_digit($option) || (int) $option < 1) {
            throw new InvalidArgumentException('The --from-service-id option must be a positive church service id.');
        }

        return (int) $option;
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
            throw new InvalidArgumentException('The re-projection report must be written below storage/app/private.');
        }

        return $normalized;
    }
}
