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
            $table->string('title', 255)->change();
            $table->string('slug', 255)->change();
            $table->string('series', 255)->nullable()->change();
            $table->string('reference', 255)->nullable()->change();
            $table->string('preacher', 255)->default('Mark Drury')->change();
            $table->string('meta_description', 255)->nullable()->change();
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->string('slug', 255)->change();
            $table->string('day', 255)->change();
            $table->string('location', 255)->nullable()->change();
            $table->string('who', 255)->change();
            $table->string('leaders_phone', 255)->nullable()->change();
            $table->string('leaders_email', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->string('title', 75)->change();
            $table->string('slug', 75)->change();
            $table->string('series', 75)->nullable()->change();
            $table->string('reference', 75)->nullable()->change();
            $table->string('preacher', 75)->default('Mark Drury')->change();
            $table->string('meta_description', 160)->nullable()->change();
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->string('slug', 75)->change();
            $table->string('day', 75)->change();
            $table->string('location', 75)->nullable()->change();
            $table->string('who', 75)->change();
            $table->string('leaders_phone', 10)->nullable()->change();
            $table->string('leaders_email', 50)->nullable()->change();
        });
    }
};
