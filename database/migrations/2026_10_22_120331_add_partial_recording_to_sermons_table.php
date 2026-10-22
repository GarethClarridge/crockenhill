<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether only part of a talk was recorded, so its public page can say so. Set by hand in the
 * sermon editor: the recording's edge alone cannot tell, because most files that reach it hold
 * only the sermon on purpose (1065 starts during its reading and stops mid-sermon; operator,
 * 2026-10-22: "published, but ideally with a flag").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sermons', function (Blueprint $table): void {
            $table->boolean('partial_recording')->default(false)->after('show_points');
        });
    }

    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table): void {
            $table->dropColumn('partial_recording');
        });
    }
};
