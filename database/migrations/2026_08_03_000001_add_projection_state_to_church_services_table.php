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
        Schema::table('church_services', function (Blueprint $table): void {
            $table->string('canonical_finalization', 20)->nullable()->after('reviewed_canonical_revision');
            $table->unsignedInteger('projection_policy_version')->nullable()->after('canonical_finalization');
        });

        // A completed review is durable evidence that a person settled this
        // service, so that much can be backfilled honestly. Machine-projected
        // services are deliberately left null: nothing recorded which policy
        // produced them, and inventing a version would make the exported
        // projection_policy a lie. Those rows re-declare themselves when they
        // are next re-projected.
        DB::table('church_services')
            ->whereNotNull('reviewed_canonical_revision')
            ->update(['canonical_finalization' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('church_services', function (Blueprint $table): void {
            $table->dropColumn(['canonical_finalization', 'projection_policy_version']);
        });
    }
};
