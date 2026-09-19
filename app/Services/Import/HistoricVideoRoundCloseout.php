<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportJournalEntry;
use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HistoricVideoRoundCloseout
{
    public function __construct(
        private readonly HistoricVideoRoundEvidence $evidence,
        private readonly HistoricImportJournal $journal,
    ) {}

    /** @param array<string, mixed> $cover */
    public function complete(HistoricImportOperation $operation, array $cover): HistoricImportOperation
    {
        $verified = $this->evidence->verify($cover, $operation);
        $digest = CanonicalJson::hash($verified);

        return DB::transaction(function () use ($operation, $verified, $digest): HistoricImportOperation {
            $locked = HistoricImportOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->journalEntries()->where('event', 'video_round_closeout_complete')->first();

            if ($existing instanceof HistoricImportJournalEntry) {
                if (($existing->payload['cover_digest'] ?? null) !== $digest) {
                    throw new RuntimeException('Historic video round closeout cover digest has drifted after completion.');
                }

                if ($locked->state !== HistoricImportOperationState::Complete) {
                    throw new RuntimeException('Historic video round closeout journal and operation state disagree.');
                }

                return $locked;
            }

            if ($locked->state !== HistoricImportOperationState::Planned) {
                throw new RuntimeException('Historic video round closeout requires the planned legacy operation.');
            }

            $this->evidence->verify($verified, $locked);
            $locked->transitionTo(HistoricImportOperationState::RoundCloseoutRequired);
            $reportDigests = [];
            foreach ($verified['reports'] as $key => $report) {
                if (is_string($key) && is_array($report) && is_string($report['sha256'] ?? null)) {
                    $reportDigests[$key] = $report['sha256'];
                }
            }
            $this->journal->append($locked, 'video_round_evidence_verified', [
                'cover_digest' => $digest,
                'item_count' => count($verified['items']),
                'report_digests' => $reportDigests,
            ]);
            $this->journal->append($locked, 'video_round_closeout_complete', [
                'cover_digest' => $digest,
                'backup_receipt' => $verified['backup_receipt'],
            ]);
            $locked->transitionTo(HistoricImportOperationState::Complete);

            return $locked->fresh() ?? $locked;
        });
    }
}
