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
            $table->foreignId('preacher_id')->nullable()->after('preacher')
                ->constrained('preachers')->nullOnDelete();
            $table->string('preacher_source', 20)->nullable()->after('preacher_id');
            $table->float('preacher_confidence')->nullable()->after('preacher_source');
            $table->boolean('needs_preacher_review')->default(false)->after('preacher_confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->dropForeign(['preacher_id']);
            $table->dropColumn(['preacher_id', 'preacher_source', 'preacher_confidence', 'needs_preacher_review']);
        });
    }
};
