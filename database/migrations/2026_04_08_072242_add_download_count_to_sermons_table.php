<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DOWNLOAD_COUNT_CHECK = 'sermons_download_count_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->unsignedInteger('download_count')->default(0)->after('scripture_passage_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE sermons ADD CONSTRAINT %s CHECK (download_count >= 0)',
                self::DOWNLOAD_COUNT_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::DOWNLOAD_COUNT_CHECK));
        }

        Schema::table('sermons', function (Blueprint $table) {
            $table->dropColumn('download_count');
        });
    }
};
