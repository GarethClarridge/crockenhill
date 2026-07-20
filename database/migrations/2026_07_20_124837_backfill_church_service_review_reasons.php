<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('church_services')
            ->where('needs_review', true)
            ->where('canonical_conflict_state', 'reopened')
            ->where('canonical_conflict_reason', 'canonical_changed')
            ->update(['review_reason' => 'Service items changed after manual review.']);

        DB::table('church_services')
            ->where('needs_review', true)
            ->where('canonical_conflict_state', 'reopened')
            ->where('canonical_conflict_reason', 'canonical_changed_with_conflicts')
            ->update(['review_reason' => 'Service items changed after manual review and incoming data conflicted with existing items.']);

        DB::table('church_services')
            ->where('needs_review', true)
            ->whereIn('canonical_conflict_reason', ['conflicts_only', 'canonical_changed_with_conflicts'])
            ->whereNull('review_reason')
            ->update(['review_reason' => 'Incoming service data conflicted with existing items.']);

        DB::table('church_services')
            ->whereNotNull('import_metadata')
            ->update([
                'import_metadata' => DB::raw("JSON_REMOVE(import_metadata, '$.canonical_conflict')"),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('church_services')->update(['review_reason' => null]);
    }
};
