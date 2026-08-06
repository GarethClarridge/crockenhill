<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §15.2's import-ingress lock.
 *
 * This is a table rather than a cache entry on purpose. The lock's whole job is
 * to hold new work out of the pipeline for the duration of a production import
 * window; a cache-backed flag disappears on a Redis restart or a `cache:clear`
 * during the window, and ingress would silently reopen mid-operation with
 * nothing to show it had. It also has to be auditable after the fact, which the
 * released-at history gives for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_ingress_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_id')->unique();
            $table->string('reason');
            $table->string('blocked_by')->nullable();
            $table->timestamp('blocked_at');
            $table->timestamp('released_at')->nullable();

            /**
             * Set to 1 while the lock is held and NULL once released. MySQL
             * permits many NULLs in a unique index and only one non-NULL, so
             * this makes "at most one active lock" a database guarantee rather
             * than a check some future caller can race past.
             */
            $table->unsignedTinyInteger('is_active')->nullable();
            $table->timestamps();

            $table->unique('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_ingress_locks');
    }
};
