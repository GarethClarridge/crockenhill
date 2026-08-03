<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('church_service_review_sessions', function (Blueprint $table): void {
            $table->json('proposal_dispositions')->nullable()->after('included_proposal_ids');
            // A list, not a single rule: one service's proposals can fall into several
            // classes, each settled by its own authoring act.
            $table->json('decision_rules')->nullable()->after('proposal_dispositions');
        });
    }

    public function down(): void
    {
        Schema::table('church_service_review_sessions', function (Blueprint $table): void {
            $table->dropColumn(['proposal_dispositions', 'decision_rules']);
        });
    }
};
