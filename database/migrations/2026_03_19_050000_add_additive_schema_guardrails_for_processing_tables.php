<?php

declare(strict_types=1);

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('livestream_segments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('segment_index')->change();
            $table->index(
                ['media_processing_log_id', 'segment_order', 'start_time'],
                'livestream_segments_log_order_index'
            );
            $table->index(
                ['media_processing_log_id', 'classification', 'start_time'],
                'livestream_segments_log_classification_time_index'
            );
        });

        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->index(
                ['processing_type', 'status', 'current_step', 'updated_at'],
                'media_processing_logs_review_queue_index'
            );
        });

        Schema::table('speaker_samples', function (Blueprint $table): void {
            $table->index(
                ['speaker_profile_id', 'approved'],
                'speaker_samples_profile_approved_index'
            );
        });

        Schema::table('service_sections', function (Blueprint $table): void {
            $table->index(
                ['media_processing_log_id', 'section_type', 'section_order', 'start_time'],
                'service_sections_log_type_order_index'
            );
            $table->index(
                ['publication_status', 'updated_at'],
                'service_sections_publication_status_updated_at_index'
            );
        });

        $this->ensureOnlyAllowedStatuses();
        $this->ensureOnlyAllowedPublicationStatuses();

        DB::statement(sprintf(
            'ALTER TABLE service_sections MODIFY status ENUM(%s) NOT NULL',
            $this->quotedEnumValues(array_map(
                static fn (ServiceSectionStatus $status): string => $status->value,
                ServiceSectionStatus::cases()
            ))
        ));

        DB::statement(sprintf(
            'ALTER TABLE service_sections MODIFY publication_status ENUM(%s) NOT NULL DEFAULT %s',
            $this->quotedEnumValues(array_map(
                static fn (ServiceSectionPublicationStatus $status): string => $status->value,
                ServiceSectionPublicationStatus::cases()
            )),
            $this->quote(ServiceSectionPublicationStatus::NotApplicable->value)
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE service_sections MODIFY status VARCHAR(255) NOT NULL');
        DB::statement(
            'ALTER TABLE service_sections MODIFY publication_status VARCHAR(255) NOT NULL DEFAULT '
            .$this->quote(ServiceSectionPublicationStatus::NotApplicable->value)
        );

        Schema::table('service_sections', function (Blueprint $table): void {
            $table->dropIndex('service_sections_log_type_order_index');
            $table->dropIndex('service_sections_publication_status_updated_at_index');
        });

        Schema::table('speaker_samples', function (Blueprint $table): void {
            $table->dropIndex('speaker_samples_profile_approved_index');
        });

        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropIndex('media_processing_logs_review_queue_index');
        });

        Schema::table('livestream_segments', function (Blueprint $table): void {
            $table->dropIndex('livestream_segments_log_order_index');
            $table->dropIndex('livestream_segments_log_classification_time_index');
            $table->unsignedTinyInteger('segment_index')->change();
        });
    }

    private function ensureOnlyAllowedStatuses(): void
    {
        $allowedStatuses = array_map(
            static fn (ServiceSectionStatus $status): string => $status->value,
            ServiceSectionStatus::cases()
        );

        $invalidCount = DB::table('service_sections')
            ->where(function ($query) use ($allowedStatuses): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', $allowedStatuses);
            })
            ->count();

        if ($invalidCount > 0) {
            throw new RuntimeException(
                'Refusing to constrain service_sections.status while invalid values still exist. '
                .'Run "php artisan schema:audit-guardrails" and backfill the reported rows first.'
            );
        }
    }

    private function ensureOnlyAllowedPublicationStatuses(): void
    {
        $allowedStatuses = array_map(
            static fn (ServiceSectionPublicationStatus $status): string => $status->value,
            ServiceSectionPublicationStatus::cases()
        );

        $invalidCount = DB::table('service_sections')
            ->where(function ($query) use ($allowedStatuses): void {
                $query->whereNull('publication_status')
                    ->orWhereNotIn('publication_status', $allowedStatuses);
            })
            ->count();

        if ($invalidCount > 0) {
            throw new RuntimeException(
                'Refusing to constrain service_sections.publication_status while invalid values still exist. '
                .'Run "php artisan schema:audit-guardrails" and backfill the reported rows first.'
            );
        }
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quotedEnumValues(array $values): string
    {
        return implode(', ', array_map($this->quote(...), $values));
    }

    private function quote(string $value): string
    {
        return DB::getPdo()->quote($value);
    }
};
