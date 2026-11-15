<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sermons', function (Blueprint $table): void {
            $table->json('media_replacement_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table): void {
            $table->dropColumn('media_replacement_history');
        });
    }
};
