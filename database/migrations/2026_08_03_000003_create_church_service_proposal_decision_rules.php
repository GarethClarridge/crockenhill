<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_service_proposal_decision_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('class_key');
            $table->unsignedTinyInteger('match_tier')->nullable();
            $table->string('disposition', 20);
            $table->json('proposal_identities');
            $table->text('rationale');
            $table->unsignedInteger('reviewed_by_user_id');
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->index(['class_key', 'created_at'], 'proposal_rules_class_created_idx');
            $table->foreign('reviewed_by_user_id', 'proposal_rules_reviewer_fk')
                ->references('id')
                ->on('users');
        });

        Schema::table('church_service_merge_proposals', function (Blueprint $table): void {
            $table->foreignId('decision_rule_id')
                ->nullable()
                ->after('resolved_at')
                ->constrained('church_service_proposal_decision_rules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('church_service_merge_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('decision_rule_id');
        });

        Schema::dropIfExists('church_service_proposal_decision_rules');
    }
};
