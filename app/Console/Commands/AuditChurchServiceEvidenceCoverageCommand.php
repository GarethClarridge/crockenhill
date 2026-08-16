<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCorpusCompleteness;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only evidence-coverage audit for §12.4 of the historic archive readiness
 * remediation plan.
 *
 * §12.4 brings current-era re-projection into scope on the argument that every
 * service projected before the WP1/WP2 repair carries the same defects the
 * historic import exists to avoid. The local dry run then found 407 of 408
 * services with *no normalized source evidence at all*, which the repaired
 * projector can only refuse — there is nothing to re-project. The plan records
 * that the local database is not a production copy and requires the production
 * counts before §12.4 is scheduled. Nothing could report them, so this exists.
 *
 * Two outcomes it distinguishes:
 *
 * - Production is mostly evidenced: §12.4 stands as written.
 * - Production resembles local: §12.4 is close to a no-op, and the real question
 *   is what happens to services holding canonical items derived from no retained
 *   evidence. Success criterion 1 presumes they do not exist.
 *
 * Deliberately always exits SUCCESS when the queries complete. Unevidenced
 * services are the finding this command exists to *measure*; §12.4 leaves their
 * disposition explicitly open (back-fill, exclude from the audit standard, or
 * accept as legacy), so failing the run here would prejudge a maintainer
 * decision and would go red on every production invocation besides.
 *
 * Output lands in the public Actions log, so it prints counts only. No date,
 * service id, source key or filename reaches stdout unless --details is passed
 * on the server.
 */
class AuditChurchServiceEvidenceCoverageCommand extends Command
{
    protected $signature = 'audit:service-evidence-coverage
        {--json : Emit the full audit report as JSON}
        {--details : List the ids of services holding canonical items with no retained evidence. Identifies rows, so keep this off when the output leaves the server (e.g. public CI logs)}';

    protected $description = 'Read-only audit of how much of the church-service corpus is described by retained source evidence';

    public function __construct(private readonly ChurchServiceCorpusCompleteness $completeness)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = [
            'services' => $this->serviceCounts(),
            'items' => $this->itemCounts(),
            'source_records' => $this->sourceRecordCounts(),
            'proposals' => $this->proposalCounts(),
            'projection' => $this->completeness->evidence(),
        ];

        if ((bool) $this->option('details')) {
            $report['unevidenced_service_ids'] = $this->unevidencedServicesWithItems()
                ->orderBy('id')
                ->pluck('id')
                ->all();
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderTables($report);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     total: int,
     *     with_any_source_record: int,
     *     with_non_manual_source_record: int,
     *     with_manual_source_records_only: int,
     *     without_any_source_record: int,
     *     unevidenced_with_canonical_items: int,
     *     non_manual_coverage_percent: float|null,
     * }
     */
    private function serviceCounts(): array
    {
        $total = ChurchService::query()->count();

        $withAny = ChurchService::query()
            ->whereHas('sourceRecords')
            ->count();

        $withNonManual = ChurchService::query()
            ->whereHas('sourceRecords', fn (Builder $query): Builder => $query->where('source', '!=', ChurchServiceSource::Manual->value))
            ->count();

        return [
            'total' => $total,
            'with_any_source_record' => $withAny,
            'with_non_manual_source_record' => $withNonManual,
            // A Manual revision is an authority record, not an assertion of what a
            // source said, so a Manual-only service is unevidenced for projection
            // purposes even though it is not empty.
            'with_manual_source_records_only' => $withAny - $withNonManual,
            'without_any_source_record' => $total - $withAny,
            'unevidenced_with_canonical_items' => $this->unevidencedServicesWithItems()->count(),
            'non_manual_coverage_percent' => $total === 0
                ? null
                : round($withNonManual / $total * 100, 1),
        ];
    }

    /** @return array{total: int, on_unevidenced_services: int} */
    private function itemCounts(): array
    {
        return [
            'total' => ChurchServiceItem::query()->count(),
            // The sharp number: canonical rows that no retained evidence describes,
            // so they can never be re-derived, audited or converged from sources.
            'on_unevidenced_services' => ChurchServiceItem::query()
                ->whereHas('churchService', fn (Builder $query): Builder => $query->whereDoesntHave('sourceRecords'))
                ->count(),
        ];
    }

    /** @return array<string, int> */
    private function sourceRecordCounts(): array
    {
        $counts = [];

        foreach (ChurchServiceSource::cases() as $source) {
            $counts[$source->value] = ChurchServiceSourceRecord::query()
                ->where('source', $source->value)
                ->count();
        }

        $counts['total'] = ChurchServiceSourceRecord::query()->count();

        return $counts;
    }

    /**
     * @return array{
     *     total: int,
     *     by_status: array<string, int>,
     *     with_resolver: int,
     *     resolved_without_resolver: int,
     *     with_decision_rule: int,
     * }
     */
    private function proposalCounts(): array
    {
        $byStatus = [];

        foreach (ChurchServiceProposalStatus::cases() as $status) {
            $byStatus[$status->value] = ChurchServiceMergeProposal::query()
                ->where('status', $status->value)
                ->count();
        }

        return [
            'total' => ChurchServiceMergeProposal::query()->count(),
            'by_status' => $byStatus,
            // §12.4's third required count, and the B13 triage set: dispositions
            // attributed to a resolver, some of which no human actually made.
            'with_resolver' => ChurchServiceMergeProposal::query()
                ->whereNotNull('resolved_by_user_id')
                ->count(),
            // The converse anomaly: a terminal status with nobody attached to it.
            'resolved_without_resolver' => ChurchServiceMergeProposal::query()
                ->whereIn('status', $this->resolvedStatuses())
                ->whereNull('resolved_by_user_id')
                ->count(),
            // PR9's rule-level dispositions are legitimately shared across a class,
            // so they are counted apart from the per-proposal ones.
            'with_decision_rule' => ChurchServiceMergeProposal::query()
                ->whereNotNull('decision_rule_id')
                ->count(),
        ];
    }

    /** @return Builder<ChurchService> */
    private function unevidencedServicesWithItems(): Builder
    {
        return ChurchService::query()
            ->whereDoesntHave('sourceRecords')
            ->whereHas('items');
    }

    /** @return list<string> */
    private function resolvedStatuses(): array
    {
        return [
            ChurchServiceProposalStatus::Accepted->value,
            ChurchServiceProposalStatus::Rejected->value,
            ChurchServiceProposalStatus::Replaced->value,
        ];
    }

    /** @param array<string, mixed> $report */
    private function renderTables(array $report): void
    {
        /** @var array<string, mixed> $services */
        $services = $report['services'];
        /** @var array<string, int> $items */
        $items = $report['items'];
        /** @var array<string, int> $sourceRecords */
        $sourceRecords = $report['source_records'];
        /** @var array{total: int, by_status: array<string, int>, with_resolver: int, resolved_without_resolver: int, with_decision_rule: int} $proposals */
        $proposals = $report['proposals'];
        /**
         * Corpus evidence has never been scalars alone — it already carried the
         * per-source counts, declared kinds and membership certificate, and now the
         * manifest reconciliation too.
         *
         * @var array<string, mixed> $projection
         */
        $projection = $report['projection'];

        $coverage = $services['non_manual_coverage_percent'];

        $this->table(['Church services', 'Count'], [
            ['total', (string) $services['total']],
            ['with any source record', (string) $services['with_any_source_record']],
            ['  └ with a non-Manual source record', (string) $services['with_non_manual_source_record']],
            ['  └ Manual source records only', (string) $services['with_manual_source_records_only']],
            ['with no source record at all', (string) $services['without_any_source_record']],
            ['  └ holding canonical items anyway', (string) $services['unevidenced_with_canonical_items']],
            ['non-Manual evidence coverage', $coverage === null ? 'n/a' : $coverage.'%'],
        ]);

        $this->table(['Church service items', 'Count'], [
            ['total', (string) $items['total']],
            ['on services with no retained evidence', (string) $items['on_unevidenced_services']],
        ]);

        $this->table(
            ['Source records by kind', 'Count'],
            array_map(
                fn (string $kind): array => [$kind, (string) $sourceRecords[$kind]],
                array_keys($sourceRecords),
            ),
        );

        $statusRows = array_map(
            fn (string $status): array => ['  └ '.$status, (string) $proposals['by_status'][$status]],
            array_keys($proposals['by_status']),
        );

        $this->table(['Merge proposals', 'Count'], [
            ['total', (string) $proposals['total']],
            ...$statusRows,
            ['carrying a resolver', (string) $proposals['with_resolver']],
            ['resolved with no resolver', (string) $proposals['resolved_without_resolver']],
            ['carrying a decision rule', (string) $proposals['with_decision_rule']],
        ]);

        $this->table(['Projection coverage', 'Count'], [
            ['approved manifest size', $this->formatNullableCount($this->nullableCount($projection, 'expected_services'))],
            ['  └ derived from', is_string($projection['expected_services_source'] ?? null) ? $projection['expected_services_source'] : 'none'],
            ['staged services', (string) $this->count($projection, 'staged_services')],
            ['projected at current policy version', (string) $this->count($projection, 'projected_services')],
            ['stale projections', (string) $this->count($projection, 'stale_projection_services')],
            ['unstaged against the manifest', $this->formatNullableCount($this->nullableCount($projection, 'unstaged_services'))],
            ['policy version', (string) $this->count($projection, 'policy_version')],
        ]);

        $expectation = $projection['expectation'] ?? null;
        $this->reportExpectation(is_array($expectation) ? $expectation : []);

        if ($services['without_any_source_record'] > 0) {
            $this->comment('Services with no retained evidence cannot be re-projected, only refused. See §12.4 of the historic archive readiness remediation plan before scheduling current-era re-projection.');
        }

        if (! (bool) $this->option('details') && $services['unevidenced_with_canonical_items'] > 0) {
            $this->comment('Re-run with --details on the server to list the affected service ids. Ids are never printed without it.');
        }
    }

    /**
     * The manifest-derived reconciliation (F1). The counts above cannot show that an
     * approved entry never staged; this can, so RG-A's "exact membership against the
     * approved manifest" has a line in the round's audit report.
     *
     * @param  array<string, mixed>  $expectation
     */
    private function reportExpectation(array $expectation): void
    {
        if (! ($expectation['approved'] ?? false)) {
            $this->comment('No manifest-derived corpus expectation is configured, so nothing states what the corpus was meant to contain. Produce one with oos:generate-corpus-expectation.');

            return;
        }

        $this->table(['Manifest reconciliation', 'Count'], [
            ['approved sources', (string) $expectation['expected_sources']],
            ['approved identities', (string) $expectation['expected_services']],
            ['staged identities', (string) $expectation['staged_identities']],
            ['approved sources not staged', (string) count($expectation['unstaged_sources'])],
            ['approved identities not staged', (string) count($expectation['unstaged_identities'])],
            ['beyond manifest, explained', (string) count($expectation['explained_beyond_manifest'])],
            ['beyond manifest, unexplained', (string) count($expectation['unexplained_identities'])],
            ['accepted holds', (string) count($expectation['accepted_holds'])],
        ]);
    }

    private function formatNullableCount(?int $count): string
    {
        return $count === null ? 'not declared' : (string) $count;
    }

    /** @param array<string, mixed> $projection */
    private function count(array $projection, string $key): int
    {
        return $this->nullableCount($projection, $key) ?? 0;
    }

    /** @param array<string, mixed> $projection */
    private function nullableCount(array $projection, string $key): ?int
    {
        $value = $projection[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
