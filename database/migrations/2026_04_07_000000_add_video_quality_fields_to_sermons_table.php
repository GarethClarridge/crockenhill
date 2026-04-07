<?php

declare(strict_types=1);

use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_INDEX = 'sermons_video_quality_status_index';

    public function up(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        $isSqlite = DB::getDriverName() === 'sqlite';

        Schema::table('sermons', function (Blueprint $table) use ($isSqlite): void {
            if (! Schema::hasColumn('sermons', 'video_quality_status')) {
                $column = $isSqlite
                    ? $table->string('video_quality_status', 32)
                    : $table->enum('video_quality_status', SermonVideoQualityStatus::values());

                $column
                    ->default(SermonVideoQualityStatus::Unassessed->value)
                    ->after('video_file_path');
            }

            if (! Schema::hasColumn('sermons', 'video_quality_reason')) {
                $table->string('video_quality_reason', 64)
                    ->nullable()
                    ->after('video_quality_status');
            }

            if (! Schema::hasColumn('sermons', 'video_visibility_override')) {
                $column = $isSqlite
                    ? $table->string('video_visibility_override', 32)
                    : $table->enum('video_visibility_override', SermonVideoVisibilityOverride::values());

                $column
                    ->default(SermonVideoVisibilityOverride::Default->value)
                    ->after('video_quality_reason');
            }

            if (! Schema::hasColumn('sermons', 'video_quality_assessed_at')) {
                $table->timestamp('video_quality_assessed_at')
                    ->nullable()
                    ->after('video_visibility_override');
            }

            if (! $this->indexExists('sermons', self::STATUS_INDEX)) {
                $table->index('video_quality_status', self::STATUS_INDEX);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        Schema::table('sermons', function (Blueprint $table): void {
            if ($this->indexExists('sermons', self::STATUS_INDEX)) {
                $table->dropIndex(self::STATUS_INDEX);
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('sermons', 'video_quality_assessed_at') ? 'video_quality_assessed_at' : null,
                Schema::hasColumn('sermons', 'video_visibility_override') ? 'video_visibility_override' : null,
                Schema::hasColumn('sermons', 'video_quality_reason') ? 'video_quality_reason' : null,
                Schema::hasColumn('sermons', 'video_quality_status') ? 'video_quality_status' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->contains('name', $indexName);
    }
};
