<?php

declare(strict_types=1);

use App\Support\ChurchServiceSourceKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $records = DB::table('church_service_source_records')
            ->orderBy('id')
            ->get(['id', 'source', 'source_key']);
        $rawKeysByIdentity = [];

        foreach ($records as $record) {
            $canonical = ChurchServiceSourceKey::canonical($record->source_key);
            $identity = "{$record->source}\0".ChurchServiceSourceKey::identity($canonical);
            $rawKeysByIdentity[$identity][$record->source_key] = true;
        }

        $ambiguous = array_filter(
            $rawKeysByIdentity,
            static fn (array $rawKeys): bool => count($rawKeys) > 1,
        );

        if ($ambiguous !== []) {
            throw new RuntimeException(
                'Cannot add portable source-key identity while canonical-equivalent legacy keys exist. '
                .'Run the source-lineage audit and adjudicate the reported variants first.',
            );
        }

        Schema::table('church_service_source_records', function (Blueprint $table): void {
            $table->char('source_key_hash', 64)
                ->charset('ascii')
                ->collation('ascii_bin')
                ->nullable()
                ->after('source_key');
        });

        foreach ($records as $record) {
            $canonical = ChurchServiceSourceKey::canonical($record->source_key);

            DB::table('church_service_source_records')
                ->where('id', $record->id)
                ->update([
                    'source_key' => $canonical,
                    'source_key_hash' => ChurchServiceSourceKey::identity($canonical),
                ]);
        }

        Schema::table('church_service_source_records', function (Blueprint $table): void {
            $table->dropUnique('church_service_source_records_revision_unique');
            $table->dropIndex('church_service_source_records_lineage_lookup_index');
            $table->char('source_key_hash', 64)
                ->charset('ascii')
                ->collation('ascii_bin')
                ->nullable(false)
                ->change();
            $table->unique(
                ['source', 'source_key_hash', 'revision_hash'],
                'church_service_source_records_revision_unique',
            );
            $table->index(
                ['church_service_id', 'source', 'source_key_hash'],
                'church_service_source_records_lineage_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('church_service_source_records', function (Blueprint $table): void {
            $table->dropUnique('church_service_source_records_revision_unique');
            $table->dropIndex('church_service_source_records_lineage_lookup_index');
            $table->unique(
                ['source', 'source_key', 'revision_hash'],
                'church_service_source_records_revision_unique',
            );
            $table->index(
                ['church_service_id', 'source', 'source_key'],
                'church_service_source_records_lineage_lookup_index',
            );
            $table->dropColumn('source_key_hash');
        });
    }
};
