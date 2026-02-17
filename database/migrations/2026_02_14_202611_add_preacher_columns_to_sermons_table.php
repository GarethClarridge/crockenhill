<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix any zero-date timestamps from old records that MySQL strict mode rejects
        // when adding foreign key constraints. Temporarily disable NO_ZERO_DATE so the
        // zero-date literal in the WHERE clause is accepted even in strict-mode environments.
        // Use a fallback timestamp rather than NULL because created_at/updated_at are NOT NULL.
        DB::statement("SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE,', ''), ',NO_ZERO_DATE', '')");
        DB::statement("UPDATE sermons SET created_at = NOW() WHERE created_at = '0000-00-00 00:00:00'");
        DB::statement("UPDATE sermons SET updated_at = NOW() WHERE updated_at = '0000-00-00 00:00:00'");
        DB::statement('SET SESSION sql_mode = @@GLOBAL.sql_mode');

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
