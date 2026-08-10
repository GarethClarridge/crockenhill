<?php

declare(strict_types=1);

use App\Enums\SermonPublicationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('song_videos', function (Blueprint $table): void {
            $table->string('publication_state', 24)
                ->default(SermonPublicationState::Published->value)
                ->after('is_featured')
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
        Schema::table('song_videos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('historic_import_operation_id');
            $table->dropIndex(['publication_state']);
            $table->dropColumn('publication_state');
        });
    }
};
