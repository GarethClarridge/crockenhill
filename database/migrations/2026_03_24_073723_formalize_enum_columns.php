<?php

declare(strict_types=1);

use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
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
        Schema::table('church_services', function (Blueprint $table) {
            $table->enum('service', SermonService::values())
                ->change();
        });

        Schema::table('media_processing_logs', function (Blueprint $table) {
            $table->enum('extracted_service', SermonService::values())
                ->nullable()
                ->change();
        });

        Schema::table('sermons', function (Blueprint $table) {
            $table->enum('content_type', SermonContentType::values())
                ->default(SermonContentType::Sermon->value)
                ->change();

            $table->enum('preacher_source', PreacherSource::values())
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_services', function (Blueprint $table) {
            $table->string('service', 255)
                ->change();
        });

        Schema::table('media_processing_logs', function (Blueprint $table) {
            $table->string('extracted_service', 255)
                ->nullable()
                ->change();
        });

        Schema::table('sermons', function (Blueprint $table) {
            $table->string('content_type', 32)
                ->default('sermon')
                ->change();

            $table->string('preacher_source', 20)
                ->nullable()
                ->change();
        });
    }
};
