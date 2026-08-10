<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historic_import_operations', function (Blueprint $table): void {
            $table->char('runtime_fingerprint', 64)->nullable()->after('target_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('historic_import_operations', function (Blueprint $table): void {
            $table->dropColumn('runtime_fingerprint');
        });
    }
};
