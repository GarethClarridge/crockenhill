<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sermons', function (Blueprint $table): void {
            $table->string('content_type', 32)
                ->default('sermon')
                ->after('service');
            $table->index('content_type');
        });

        DB::table('sermons')
            ->whereIn('id', function ($query): void {
                $query->select('published_sermon_id')
                    ->from('service_sections')
                    ->where('section_type', 'childrens_talk')
                    ->whereNotNull('published_sermon_id');
            })
            ->update(['content_type' => 'childrens_talk']);
    }

    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table): void {
            $table->dropIndex(['content_type']);
            $table->dropColumn('content_type');
        });
    }
};
