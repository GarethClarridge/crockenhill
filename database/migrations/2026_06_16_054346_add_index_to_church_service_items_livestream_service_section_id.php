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
        Schema::table('church_service_items', function (Blueprint $table) {
            $table->index('livestream_service_section_id', 'church_service_items_livestream_service_section_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_service_items', function (Blueprint $table) {
            $table->dropIndex('church_service_items_livestream_service_section_id_index');
        });
    }
};
