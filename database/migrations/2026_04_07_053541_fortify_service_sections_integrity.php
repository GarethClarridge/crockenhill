<?php

declare(strict_types=1);

use App\Enums\ServiceSectionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PUBLICATION_MEDIA_CHECK = 'service_sections_publication_media_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('service_sections')) {
            return;
        }

        $isMysql = DB::getDriverName() === 'mysql';

        // 1. Data Cleanup: Ensure section_type values are valid before changing to ENUM
        $validTypes = ServiceSectionType::values();
        DB::table('service_sections')
            ->whereNotIn('section_type', $validTypes)
            ->update(['section_type' => ServiceSectionType::Other->value]);

        // 2. Formalize section_type as ENUM (MySQL only)
        if ($isMysql) {
            Schema::table('service_sections', function (Blueprint $table) use ($validTypes): void {
                $table->enum('section_type', $validTypes)
                    ->nullable(false)
                    ->change();
            });
        }

        // 3. Fortify publication media check to include extracted_audio_path for non-song sections.
        // Song sections only produce video (no audio), so audio is only required when section_type != 'song'.
        if ($isMysql || DB::getDriverName() === 'pgsql') {
            $this->dropConstraintIfExists('service_sections', self::PUBLICATION_MEDIA_CHECK);

            $check = "((publication_status IN ('approved', 'published') AND extracted_video_path IS NOT NULL AND extracted_at IS NOT NULL AND (section_type = 'song' OR extracted_audio_path IS NOT NULL)) OR publication_status IN ('not_applicable', 'pending_approval', 'rejected'))";

            DB::statement(sprintf(
                'ALTER TABLE service_sections ADD CONSTRAINT %s CHECK (%s)',
                self::PUBLICATION_MEDIA_CHECK,
                $check
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('service_sections')) {
            return;
        }

        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('service_sections', function (Blueprint $table): void {
                $table->string('section_type', 255)->nullable(false)->change();
            });
        }

        if ($isMysql || DB::getDriverName() === 'pgsql') {
            $this->dropConstraintIfExists('service_sections', self::PUBLICATION_MEDIA_CHECK);

            // Restore the previous constraint (audio optional for all section types).
            $check = "((publication_status IN ('approved', 'published') AND extracted_video_path IS NOT NULL AND extracted_at IS NOT NULL) OR publication_status IN ('not_applicable', 'pending_approval', 'rejected'))";

            DB::statement(sprintf(
                'ALTER TABLE service_sections ADD CONSTRAINT %s CHECK (%s)',
                self::PUBLICATION_MEDIA_CHECK,
                $check
            ));
        }
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        if ($this->constraintExists($table, $constraint)) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $constraint));
            } else {
                DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $constraint));
            }
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();
    }
};
