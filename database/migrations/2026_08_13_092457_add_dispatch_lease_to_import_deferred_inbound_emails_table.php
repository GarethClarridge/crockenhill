<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HIR6. `dispatched` recorded a queue handoff, not an outcome, and closeout
 * accepted it as terminal — so an operation could complete while an order of
 * service that arrived during the freeze was still queued, still running, or
 * about to fail permanently.
 *
 * The claim/lease columns below make the intermediate `dispatching` state
 * durable, so a crash between claiming a row and dispatching its job is
 * recoverable without a second import, and a drain can tell an owned claim from
 * an abandoned one.
 *
 * Every column is nullable and every default is inert, so this deploys ahead of
 * the code that writes them. No data repair happens here: existing `dispatched`
 * rows are audited by an operator, never guessed at by a migration.
 *
 * Delete alongside the deferred-inbound outbox once the exact production import
 * and its retention window have closed (G9/WP10), using a later contract
 * release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_deferred_inbound_emails', function (Blueprint $table): void {
            $table->uuid('dispatch_token')->nullable()->after('state');
            $table->timestamp('dispatch_claimed_at')->nullable()->after('dispatch_token');
            $table->timestamp('lease_expires_at')->nullable()->after('dispatch_claimed_at');
            $table->timestamp('last_failed_at')->nullable()->after('processed_at');
            /** Bounded so a stack trace or provider error body cannot fill the row. */
            $table->string('last_error', 500)->nullable()->after('last_failed_at');
            $table->unsignedInteger('failure_count')->default(0)->after('last_error');

            /** The drain's claim query: this operation's oldest claimable row. */
            $table->index(
                ['operation_id', 'state', 'lease_expires_at', 'id'],
                'import_deferred_operation_lease_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('import_deferred_inbound_emails', function (Blueprint $table): void {
            $table->dropIndex('import_deferred_operation_lease_index');
            $table->dropColumn([
                'dispatch_token',
                'dispatch_claimed_at',
                'lease_expires_at',
                'last_failed_at',
                'last_error',
                'failure_count',
            ]);
        });
    }
};
