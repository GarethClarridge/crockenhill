<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Enums\SermonPublicationState;
use App\Models\HistoricImportArtifact;
use App\Models\HistoricImportOperation;
use App\Models\SongUsageReport;
use App\Services\Import\HistoricImportArtifactWriter;
use App\Services\Import\ImportIngressGate;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The date-only hymn lane's half of exact closeout.
 *
 * F61 asked for the lane to contribute per-item outcomes rather than a total, and F53 already
 * settled why a scalar cannot do it: a count cannot say *which* rows survived, so a lost row
 * offset by an unrelated one still passes. The import writes an operation-owned artifact naming
 * every fingerprint it wrote; this re-derives the same membership from the rows the database
 * holds now and refuses on any difference.
 *
 * Deliberately shaped like {@see ImportIngressGate}'s deferred-inbound
 * reconciliation, because closeout must not grow a second, weaker definition of "finished" —
 * the lane owns its completion rule and the evidence document quotes it.
 */
final class HistoricSongUsageCloseout
{
    public const string ArtifactKey = 'song-usage-import';

    public function __construct(
        private readonly HistoricImportArtifactWriter $artifacts,
    ) {}

    /**
     * Exact publication-state counts for the operation's own rows.
     *
     * @return array<string, int>
     */
    public function stateCounts(string $operationId): array
    {
        $counts = [];

        foreach (SermonPublicationState::cases() as $state) {
            $counts[$state->value] = $this->reports($operationId)
                ->where('publication_state', $state)
                ->count();
        }

        return $counts;
    }

    /**
     * The persisted rows, named rather than counted, in a stable order.
     *
     * @return list<string>
     */
    public function membership(string $operationId): array
    {
        /** @var list<string> $fingerprints */
        $fingerprints = $this->reports($operationId)
            ->orderBy('source_fingerprint')
            ->pluck('source_fingerprint')
            ->values()
            ->all();

        return $fingerprints;
    }

    public function membershipDigest(string $operationId): string
    {
        return CanonicalJson::hash($this->membership($operationId));
    }

    /**
     * Refuse closeout unless every row the import reported is still present.
     *
     * The artifact is the authority for what was approved and written; the database is the
     * authority for what is there now. Closeout is the moment those two must agree exactly.
     */
    public function assertReconciled(string $operationId): void
    {
        $operation = HistoricImportOperation::query()->where('operation_id', $operationId)->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException('Historic song usage closeout names an operation that does not exist.');
        }

        $artifact = $operation->artifacts()
            ->where('artifact_key', 'like', self::ArtifactKey.'-%')
            ->latest('id')
            ->first();
        $observed = $this->membership($operationId);

        if (! $artifact instanceof HistoricImportArtifact) {
            /**
             * An operation that never ran the hymn lane has nothing to reconcile. One that holds
             * rows without a report does: those rows arrived outside the reported membership.
             */
            if ($observed === []) {
                return;
            }

            throw new RuntimeException(
                'The historic song usage lane holds rows with no import report artifact, so its membership cannot be reconciled.',
            );
        }

        $reported = $this->reportedMembership($artifact);

        if ($reported !== $observed) {
            throw new RuntimeException(sprintf(
                'Historic song usage membership does not reconcile: the import report names %d rows, the database holds %d.',
                count($reported),
                count($observed),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function reportedMembership(HistoricImportArtifact $artifact): array
    {
        $report = json_decode(
            $this->artifacts->read($artifact),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($report) || ! is_array($report['membership'] ?? null)) {
            throw new RuntimeException('The historic song usage import report artifact names no row membership.');
        }

        $fingerprints = [];

        foreach ($report['membership'] as $row) {
            if (! is_array($row) || ! is_string($row['fingerprint'] ?? null)) {
                throw new RuntimeException('The historic song usage import report artifact has an invalid membership row.');
            }

            $fingerprints[] = $row['fingerprint'];
        }

        sort($fingerprints, SORT_STRING);

        return $fingerprints;
    }

    /** @return Builder<SongUsageReport> */
    private function reports(string $operationId): Builder
    {
        return SongUsageReport::query()
            ->whereHas(
                'historicImportOperation',
                fn ($query) => $query->where('operation_id', $operationId),
            );
    }
}
