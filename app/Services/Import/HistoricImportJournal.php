<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\HistoricImportDisposition;
use App\Models\HistoricImportCheckpoint;
use App\Models\HistoricImportJournalEntry;
use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricImportJournal
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function append(
        HistoricImportOperation $operation,
        string $event,
        array $payload,
        ?HistoricImportCheckpoint $checkpoint = null,
        ?HistoricImportDisposition $disposition = null,
    ): HistoricImportJournalEntry {
        if ($event === '' || strlen($event) > 80) {
            throw new RuntimeException('Historic import journal event is invalid.');
        }

        if ($checkpoint !== null && $checkpoint->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException('Historic import journal checkpoint belongs to another operation.');
        }

        return DB::transaction(function () use ($operation, $event, $payload, $checkpoint, $disposition): HistoricImportJournalEntry {
            HistoricImportOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $previous = HistoricImportJournalEntry::query()
                ->where('historic_import_operation_id', $operation->id)
                ->orderByDesc('sequence')
                ->first();
            $sequence = $previous instanceof HistoricImportJournalEntry ? $previous->sequence + 1 : 1;
            $recordedAt = Carbon::now()->utc()->startOfSecond();
            $previousHash = $previous instanceof HistoricImportJournalEntry ? $previous->entry_hash : null;
            $entryHash = $this->hash(
                operationId: $operation->operation_id,
                checkpointKey: $checkpoint?->checkpoint_key,
                sequence: $sequence,
                event: $event,
                disposition: $disposition?->value,
                payload: $payload,
                previousHash: $previousHash,
                recordedAt: $recordedAt->toIso8601String(),
            );

            return HistoricImportJournalEntry::query()->create([
                'historic_import_operation_id' => $operation->id,
                'historic_import_checkpoint_id' => $checkpoint?->id,
                'sequence' => $sequence,
                'event' => $event,
                'disposition' => $disposition,
                'payload' => $payload,
                'previous_entry_hash' => $previousHash,
                'entry_hash' => $entryHash,
                'recorded_at' => $recordedAt,
            ]);
        });
    }

    public function verify(HistoricImportOperation $operation): void
    {
        $previousHash = null;
        $expectedSequence = 1;

        foreach ($operation->journalEntries()->with('checkpoint')->get() as $entry) {
            if ($entry->sequence !== $expectedSequence || $entry->previous_entry_hash !== $previousHash) {
                throw new RuntimeException('Historic import journal sequence or chain link is invalid.');
            }

            $expectedHash = $this->hash(
                operationId: $operation->operation_id,
                checkpointKey: $entry->checkpoint?->checkpoint_key,
                sequence: $entry->sequence,
                event: $entry->event,
                disposition: $entry->disposition?->value,
                payload: $entry->payload,
                previousHash: $entry->previous_entry_hash,
                recordedAt: $entry->recorded_at->utc()->toIso8601String(),
            );

            if (! hash_equals($expectedHash, $entry->entry_hash)) {
                throw new RuntimeException("Historic import journal entry {$entry->sequence} failed its hash check.");
            }

            $previousHash = $entry->entry_hash;
            $expectedSequence++;
        }
    }

    /** @param array<string, mixed> $payload */
    private function hash(
        string $operationId,
        ?string $checkpointKey,
        int $sequence,
        string $event,
        ?string $disposition,
        array $payload,
        ?string $previousHash,
        string $recordedAt,
    ): string {
        return CanonicalJson::hash([
            'contract_version' => 1,
            'operation_id' => $operationId,
            'checkpoint_key' => $checkpointKey,
            'sequence' => $sequence,
            'event' => $event,
            'disposition' => $disposition,
            'payload' => $payload,
            'previous_entry_hash' => $previousHash,
            'recorded_at' => $recordedAt,
        ]);
    }
}
