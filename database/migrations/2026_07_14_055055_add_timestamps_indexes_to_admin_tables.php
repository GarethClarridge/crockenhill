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
        Schema::table('sermons', function (Blueprint $table) {
            $table->index('updated_at');
            $table->index('created_at');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->index('updated_at');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->index('updated_at');
            $table->index('created_at');
        });

        Schema::table('preachers', function (Blueprint $table) {
            $table->index('updated_at');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('preachers', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });

        Schema::table('sermons', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
            $table->dropIndex(['created_at']);
        });
    }
};
