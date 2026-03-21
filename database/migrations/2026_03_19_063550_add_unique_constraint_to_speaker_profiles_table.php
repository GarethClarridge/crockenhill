<?php

declare(strict_types=1);

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
        if ($this->hasIndex('speaker_profiles', 'speaker_profiles_preacher_provider_version_unique')) {
            return;
        }

        $duplicateGroups = DB::table('speaker_profiles')
            ->selectRaw('preacher_id, provider, model_version, COUNT(*) as duplicate_count')
            ->groupBy('preacher_id', 'provider', 'model_version')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicateGroups > 0) {
            throw new RuntimeException(
                'Refusing to add speaker profile uniqueness while duplicate identity rows still exist. '
                .'Run "php artisan schema:audit-guardrails" and resolve the duplicates first.'
            );
        }

        Schema::table('speaker_profiles', function (Blueprint $table): void {
            $table->unique(['preacher_id', 'provider', 'model_version'], 'speaker_profiles_preacher_provider_version_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->hasIndex('speaker_profiles', 'speaker_profiles_preacher_provider_version_unique')) {
            return;
        }

        Schema::table('speaker_profiles', function (Blueprint $table): void {
            $table->dropUnique('speaker_profiles_preacher_provider_version_unique');
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
