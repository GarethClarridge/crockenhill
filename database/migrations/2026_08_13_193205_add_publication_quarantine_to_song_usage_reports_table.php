<?php

declare(strict_types=1);

use App\Enums\SermonPublicationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F61's read-side answer for the date-only hymn lane: imported usage is evidence first and
     * public history only after a signed release, exactly as sermons and song videos are.
     *
     * The default deliberately differs from those two tables. They carry plenty of legitimately
     * public non-historic rows, so defaulting them to `published` preserved the status quo. Every
     * `song_usage_reports` row comes from the historic hymn importer and nothing else writes to
     * this table, so `published` would default precisely the rows F61 says must be held.
     */
    public function up(): void
    {
        Schema::table('song_usage_reports', function (Blueprint $table): void {
            $table->string('publication_state', 24)
                ->default(SermonPublicationState::Quarantined->value)
                ->after('metadata')
                ->index();
            $table->foreignId('historic_import_operation_id')
                ->nullable()
                ->after('publication_state')
                ->constrained('historic_import_operations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('song_usage_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('historic_import_operation_id');
            $table->dropIndex(['publication_state']);
            $table->dropColumn('publication_state');
        });
    }
};
