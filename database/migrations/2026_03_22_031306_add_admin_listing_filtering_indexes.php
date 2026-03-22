<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! $this->indexExists('users', 'users_is_admin_index')) {
                $table->index('is_admin', 'users_is_admin_index');
            }
        });

        Schema::table('preachers', function (Blueprint $table) {
            if (! $this->indexExists('preachers', 'preachers_is_active_index')) {
                $table->index('is_active', 'preachers_is_active_index');
            }
        });

        Schema::table('meetings', function (Blueprint $table) {
            if (! $this->indexExists('meetings', 'meetings_is_recurring_index')) {
                $table->index('is_recurring', 'meetings_is_recurring_index');
            }
        });

        Schema::table('church_services', function (Blueprint $table) {
            if (! $this->indexExists('church_services', 'church_services_needs_review_index')) {
                $table->index('needs_review', 'church_services_needs_review_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_admin']);
        });

        Schema::table('preachers', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['is_recurring']);
        });

        Schema::table('church_services', function (Blueprint $table) {
            $table->dropIndex(['needs_review']);
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return Schema::hasIndex($table, $indexName);
    }
};
