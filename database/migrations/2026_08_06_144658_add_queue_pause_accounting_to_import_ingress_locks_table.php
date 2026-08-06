<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §15.2's fourth ingress requirement: when Horizon is paused globally rather than
 * by affected queue, the delay imposed on unrelated background work has to be
 * recorded rather than assumed to be nil.
 *
 * It lives on the lock row because the lock already is the window's record: it
 * carries the operation id and the blocked/released timestamps the closeout
 * report is built from, and a delay figure separated from the window it belongs
 * to is not evidence of anything. JSON rather than columns because the shape is
 * derived from whatever Horizon supervisors exist at the time — pinning it to
 * columns would date the record to today's supervisor layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_ingress_locks', function (Blueprint $table): void {
            $table->json('queue_pause_accounting')->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_ingress_locks', function (Blueprint $table): void {
            $table->dropColumn('queue_pause_accounting');
        });
    }
};
