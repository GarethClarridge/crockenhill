<?php

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
            $table->foreignId('scripture_passage_id')
                ->nullable()
                ->after('reference')
                ->constrained('scripture_passages')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->dropForeign(['scripture_passage_id']);
            $table->dropColumn('scripture_passage_id');
        });
    }
};
