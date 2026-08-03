<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertExistingLineagesHaveOneLeaf();

        Schema::table('church_service_source_records', function (Blueprint $table): void {
            $table->index(
                ['church_service_id', 'source', 'source_key'],
                'church_service_source_records_lineage_lookup_index',
            );
            $table->unique('supersedes_id', 'church_service_source_records_one_successor_unique');

            // The predecessor link is the only record of which revision is current.
            // ON DELETE SET NULL would orphan a successor into a second active
            // leaf, which projection then refuses for good. Cascading instead
            // removes the revisions that depended on the deleted one, so a lineage
            // keeps exactly one leaf by construction — and a deleted church
            // service still takes its whole chain with it.
            $table->dropForeign('church_service_source_records_supersedes_id_foreign');
            $table->foreign('supersedes_id')
                ->references('id')
                ->on('church_service_source_records')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('church_service_source_records', function (Blueprint $table): void {
            $table->dropForeign('church_service_source_records_supersedes_id_foreign');
            $table->foreign('supersedes_id')
                ->references('id')
                ->on('church_service_source_records')
                ->nullOnDelete();

            $table->dropUnique('church_service_source_records_one_successor_unique');
            $table->dropIndex('church_service_source_records_lineage_lookup_index');
        });
    }

    private function assertExistingLineagesHaveOneLeaf(): void
    {
        $records = DB::table('church_service_source_records')
            ->orderBy('id')
            ->get(['id', 'church_service_id', 'source', 'source_key', 'supersedes_id']);
        $recordsById = $records->keyBy('id');
        $lineages = [];
        $successorsByPredecessor = [];
        $issues = [];

        foreach ($records as $record) {
            $lineageKey = "{$record->church_service_id}|{$record->source}|{$record->source_key}";
            $lineages[$lineageKey][$record->id] = $record;

            if ($record->supersedes_id === null) {
                continue;
            }

            $predecessor = $recordsById->get($record->supersedes_id);

            if ($predecessor === null) {
                $issues[] = "revision {$record->id} supersedes missing revision {$record->supersedes_id}";

                continue;
            }

            $predecessorLineageKey = "{$predecessor->church_service_id}|{$predecessor->source}|{$predecessor->source_key}";

            if ($predecessorLineageKey !== $lineageKey) {
                $issues[] = "revision {$record->id} supersedes a record in another source lineage";

                continue;
            }

            $successorsByPredecessor[$predecessor->id][] = $record->id;
        }

        foreach ($successorsByPredecessor as $predecessorId => $successors) {
            if (count($successors) > 1) {
                $issues[] = "revision {$predecessorId} has multiple successors";
            }
        }

        foreach ($lineages as $lineageKey => $recordsByLineage) {
            $leaves = array_filter(
                $recordsByLineage,
                static fn (object $record): bool => ! isset($successorsByPredecessor[$record->id]),
            );

            if (count($leaves) !== 1) {
                $issues[] = "source lineage {$lineageKey} has ".count($leaves).' active leaves';
            }
        }

        if ($issues === []) {
            return;
        }

        throw new RuntimeException(
            'Cannot add source-revision lineage constraints while existing data is invalid. '
            .'Run service-tracking:audit-source-revision-lineages and repair the reported records first: '
            .implode('; ', array_slice($issues, 0, 10)).'.',
        );
    }
};
