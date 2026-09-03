<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a service was, when that explains why it does not look like an ordinary
 * Sunday — most often why it carried no sermon (D2/D3, 2026-09-03).
 *
 * Two columns, not one. `occasion` is a proposal the detector may write
 * unattended; `occasion_confirmed_at` is the operator's approval, and only the
 * second one licenses public rendering. Keeping them apart is what upholds the
 * standing rule that no unconfirmed model output reaches a public surface — the
 * same reason the service `summary` is stored but never rendered there.
 *
 * The value is validated as an enum in the application
 * ({@see \App\Enums\ServiceOccasion}) rather than by a database enum type, which
 * matches every other enumerated column on this table and keeps adding a case a
 * code change rather than a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('church_services', function (Blueprint $table): void {
            $table->string('occasion')->nullable()->after('summary');
            $table->timestamp('occasion_confirmed_at')->nullable()->after('occasion');
        });
    }

    public function down(): void
    {
        Schema::table('church_services', function (Blueprint $table): void {
            $table->dropColumn(['occasion', 'occasion_confirmed_at']);
        });
    }
};
