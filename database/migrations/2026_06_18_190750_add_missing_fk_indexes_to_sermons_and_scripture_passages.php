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
            if (! Schema::hasIndex('sermons', 'sermons_scripture_passage_id_index') && ! Schema::hasIndex('sermons', 'sermons_scripture_passage_id_foreign')) {
                $table->index('scripture_passage_id');
            }
        });

        Schema::table('scripture_passages', function (Blueprint $table) {
            if (! Schema::hasIndex('scripture_passages', 'scripture_passages_bible_id_index')) {
                $table->index('bible_id');
            }
        });

        Schema::table('speaker_profiles', function (Blueprint $table) {
            if (! Schema::hasIndex('speaker_profiles', 'speaker_profiles_preacher_id_index') && ! Schema::hasIndex('speaker_profiles', 'speaker_profiles_preacher_id_foreign')) {
                $table->index('preacher_id');
            }
        });

        Schema::table('speaker_samples', function (Blueprint $table) {
            if (! Schema::hasIndex('speaker_samples', 'speaker_samples_speaker_profile_id_index') && ! Schema::hasIndex('speaker_samples', 'speaker_samples_speaker_profile_id_foreign')) {
                $table->index('speaker_profile_id');
            }
            if (! Schema::hasIndex('speaker_samples', 'speaker_samples_sermon_id_index') && ! Schema::hasIndex('speaker_samples', 'speaker_samples_sermon_id_foreign')) {
                $table->index('sermon_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            if (Schema::hasIndex('sermons', 'sermons_scripture_passage_id_index')) {
                $table->dropIndex(['scripture_passage_id']);
            }
        });

        Schema::table('scripture_passages', function (Blueprint $table) {
            if (Schema::hasIndex('scripture_passages', 'scripture_passages_bible_id_index')) {
                $table->dropIndex(['bible_id']);
            }
        });

        Schema::table('speaker_profiles', function (Blueprint $table) {
            if (Schema::hasIndex('speaker_profiles', 'speaker_profiles_preacher_id_index')) {
                $table->dropIndex(['preacher_id']);
            }
        });

        Schema::table('speaker_samples', function (Blueprint $table) {
            if (Schema::hasIndex('speaker_samples', 'speaker_samples_speaker_profile_id_index')) {
                $table->dropIndex(['speaker_profile_id']);
            }
            if (Schema::hasIndex('speaker_samples', 'speaker_samples_sermon_id_index')) {
                $table->dropIndex(['sermon_id']);
            }
        });
    }
};
