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
        Schema::table('speaker_profiles', function (Blueprint $table): void {
            $table->unique(['preacher_id', 'provider', 'model_version'], 'speaker_profiles_preacher_provider_version_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('speaker_profiles', function (Blueprint $table): void {
            $table->dropUnique('speaker_profiles_preacher_provider_version_unique');
        });
    }
};
