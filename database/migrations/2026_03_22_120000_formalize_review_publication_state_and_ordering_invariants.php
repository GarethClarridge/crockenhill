<?php

declare(strict_types=1);

use App\Data\ChurchServiceImportMetadata;
use App\Enums\ChurchServiceCanonicalConflictReason;
use App\Enums\ChurchServiceCanonicalConflictState;
use App\Enums\ChurchServiceReviewState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_POSITION_COLUMN = 'active_position';

    private const ACTIVE_POSITION_INDEX = 'church_service_items_active_position_unique';

    private const REVIEW_STATE_CHECK = 'church_services_review_state_check';

    private const CANONICAL_CONFLICT_STATE_CHECK = 'church_services_canonical_conflict_state_check';

    private const SERVICE_SECTION_TIMING_CHECK = 'service_sections_timing_invariants_check';

    private const SERVICE_SECTION_STATUS_CHECK = 'service_sections_status_publication_check';

    private const SERVICE_SECTION_PUBLICATION_LINK_CHECK = 'service_sections_publication_link_check';

    private const SERVICE_SECTION_PUBLICATION_MEDIA_CHECK = 'service_sections_publication_media_check';

    public function up(): void
    {
        $this->addChurchServiceReviewColumns();
        $this->backfillChurchServiceReviewColumns();
        $this->repairChurchServiceItemOrdering();
        $this->addChurchServiceItemActiveOrderingColumn();
        $this->repairServiceSectionState();
        $this->ensureServiceSectionPublishedSermonForeignKeySupportsChecks();
        $this->addChurchServiceReviewChecks();
        $this->addServiceSectionChecks();
    }

    public function down(): void
    {
        $this->dropServiceSectionChecks();
        $this->restoreServiceSectionPublishedSermonForeignKey();
        $this->dropChurchServiceReviewChecks();
        $this->dropChurchServiceItemActiveOrderingColumn();

        Schema::table('church_services', function (Blueprint $table): void {
            foreach ([
                'church_services_canonical_conflict_state_index',
                'church_services_review_state_index',
                'church_services_manual_reviewed_by_user_id_index',
            ] as $index) {
                if (Schema::hasIndex('church_services', $index)) {
                    $table->dropIndex($index);
                }
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('church_services', 'review_state') ? 'review_state' : null,
                Schema::hasColumn('church_services', 'manual_reviewed_at') ? 'manual_reviewed_at' : null,
                Schema::hasColumn('church_services', 'manual_reviewed_by_user_id') ? 'manual_reviewed_by_user_id' : null,
                Schema::hasColumn('church_services', 'manual_review_reopened_at') ? 'manual_review_reopened_at' : null,
                Schema::hasColumn('church_services', 'manual_review_reopened_by_source') ? 'manual_review_reopened_by_source' : null,
                Schema::hasColumn('church_services', 'canonical_conflict_state') ? 'canonical_conflict_state' : null,
                Schema::hasColumn('church_services', 'canonical_conflict_detected_at') ? 'canonical_conflict_detected_at' : null,
                Schema::hasColumn('church_services', 'canonical_conflict_incoming_source') ? 'canonical_conflict_incoming_source' : null,
                Schema::hasColumn('church_services', 'canonical_conflict_reviewed_previously') ? 'canonical_conflict_reviewed_previously' : null,
                Schema::hasColumn('church_services', 'canonical_conflict_canonical_changed') ? 'canonical_conflict_canonical_changed' : null,
                Schema::hasColumn('church_services', 'canonical_conflict_reason') ? 'canonical_conflict_reason' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function addChurchServiceReviewColumns(): void
    {
        Schema::table('church_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('church_services', 'review_state')) {
                $table->enum('review_state', array_map(
                    static fn (ChurchServiceReviewState $state): string => $state->value,
                    ChurchServiceReviewState::cases()
                ))->default(ChurchServiceReviewState::NOT_REVIEWED->value)->after('needs_review');
            }

            if (! Schema::hasColumn('church_services', 'manual_reviewed_at')) {
                $table->timestamp('manual_reviewed_at')->nullable()->after('review_state');
            }

            if (! Schema::hasColumn('church_services', 'manual_reviewed_by_user_id')) {
                $table->unsignedInteger('manual_reviewed_by_user_id')
                    ->nullable()
                    ->after('manual_reviewed_at');
            }

            if (! Schema::hasIndex('church_services', 'church_services_manual_reviewed_by_user_id_index')) {
                $table->index('manual_reviewed_by_user_id', 'church_services_manual_reviewed_by_user_id_index');
            }

            if (! Schema::hasColumn('church_services', 'manual_review_reopened_at')) {
                $table->timestamp('manual_review_reopened_at')->nullable()->after('manual_reviewed_by_user_id');
            }

            if (! Schema::hasColumn('church_services', 'manual_review_reopened_by_source')) {
                $table->string('manual_review_reopened_by_source')->nullable()->after('manual_review_reopened_at');
            }

            if (! Schema::hasColumn('church_services', 'canonical_conflict_state')) {
                $table->enum('canonical_conflict_state', array_map(
                    static fn (ChurchServiceCanonicalConflictState $state): string => $state->value,
                    ChurchServiceCanonicalConflictState::cases()
                ))->default(ChurchServiceCanonicalConflictState::NONE->value)->after('manual_review_reopened_by_source');
            }

            if (! Schema::hasColumn('church_services', 'canonical_conflict_detected_at')) {
                $table->timestamp('canonical_conflict_detected_at')->nullable()->after('canonical_conflict_state');
            }

            if (! Schema::hasColumn('church_services', 'canonical_conflict_incoming_source')) {
                $table->string('canonical_conflict_incoming_source')->nullable()->after('canonical_conflict_detected_at');
            }

            if (! Schema::hasColumn('church_services', 'canonical_conflict_reviewed_previously')) {
                $table->boolean('canonical_conflict_reviewed_previously')->nullable()->after('canonical_conflict_incoming_source');
            }

            if (! Schema::hasColumn('church_services', 'canonical_conflict_canonical_changed')) {
                $table->boolean('canonical_conflict_canonical_changed')->nullable()->after('canonical_conflict_reviewed_previously');
            }

            if (! Schema::hasColumn('church_services', 'canonical_conflict_reason')) {
                $table->enum('canonical_conflict_reason', array_map(
                    static fn (ChurchServiceCanonicalConflictReason $reason): string => $reason->value,
                    ChurchServiceCanonicalConflictReason::cases()
                ))->nullable()->after('canonical_conflict_canonical_changed');
            }

            if (! Schema::hasIndex('church_services', 'church_services_review_state_index')) {
                $table->index('review_state', 'church_services_review_state_index');
            }

            if (! Schema::hasIndex('church_services', 'church_services_canonical_conflict_state_index')) {
                $table->index('canonical_conflict_state', 'church_services_canonical_conflict_state_index');
            }
        });
    }

    private function backfillChurchServiceReviewColumns(): void
    {
        DB::table('church_services')
            ->select(['id', 'source', 'import_metadata'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $services): void {
                foreach ($services as $service) {
                    $importMetadata = json_decode((string) ($service->import_metadata ?? 'null'), true);
                    $normalized = $this->normalizeChurchServiceReviewState(
                        is_array($importMetadata) ? $importMetadata : [],
                        is_string($service->source ?? null) ? $service->source : null,
                    );

                    DB::table('church_services')
                        ->where('id', $service->id)
                        ->update($normalized);
                }
            });
    }

    private function repairChurchServiceItemOrdering(): void
    {
        DB::table('church_service_items')
            ->select('church_service_id')
            ->whereNull('deleted_at')
            ->groupBy('church_service_id')
            ->orderBy('church_service_id')
            ->chunk(200, function (Collection $services): void {
                foreach ($services as $service) {
                    $items = DB::table('church_service_items')
                        ->select(['id', 'position'])
                        ->where('church_service_id', $service->church_service_id)
                        ->whereNull('deleted_at')
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get();

                    $expectedPosition = 1;

                    foreach ($items as $item) {
                        if ((int) $item->position !== $expectedPosition) {
                            DB::table('church_service_items')
                                ->where('id', $item->id)
                                ->update(['position' => $expectedPosition]);
                        }

                        $expectedPosition++;
                    }
                }
            });
    }

    private function addChurchServiceItemActiveOrderingColumn(): void
    {
        if (! Schema::hasColumn('church_service_items', self::ACTIVE_POSITION_COLUMN)) {
            DB::statement(
                'ALTER TABLE church_service_items ADD COLUMN '.self::ACTIVE_POSITION_COLUMN
                .' INT GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN position ELSE NULL END) STORED'
            );
        }

        if (! Schema::hasIndex('church_service_items', self::ACTIVE_POSITION_INDEX)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::ACTIVE_POSITION_INDEX
                .' ON church_service_items (church_service_id, '.self::ACTIVE_POSITION_COLUMN.')'
            );
        }
    }

    private function dropChurchServiceItemActiveOrderingColumn(): void
    {
        if (Schema::hasIndex('church_service_items', self::ACTIVE_POSITION_INDEX)) {
            DB::statement('DROP INDEX '.self::ACTIVE_POSITION_INDEX.' ON church_service_items');
        }

        if (Schema::hasColumn('church_service_items', self::ACTIVE_POSITION_COLUMN)) {
            Schema::table('church_service_items', function (Blueprint $table): void {
                $table->dropColumn(self::ACTIVE_POSITION_COLUMN);
            });
        }
    }

    private function repairServiceSectionState(): void
    {
        DB::table('service_sections')
            ->orderBy('id')
            ->chunkById(200, function (Collection $sections): void {
                foreach ($sections as $section) {
                    $status = (string) $section->status;
                    $publicationStatus = (string) $section->publication_status;
                    $publishedSermonId = $section->published_sermon_id === null ? null : (int) $section->published_sermon_id;
                    $publishedAt = $section->published_at;
                    $extractedAt = $section->extracted_at;

                    $updates = [];

                    if ($status === ServiceSectionStatus::SKIPPED->value && $publicationStatus !== ServiceSectionPublicationStatus::NOT_APPLICABLE->value) {
                        $publicationStatus = ServiceSectionPublicationStatus::NOT_APPLICABLE->value;
                        $updates['publication_status'] = $publicationStatus;
                    }

                    if ($publicationStatus !== ServiceSectionPublicationStatus::PUBLISHED->value) {
                        if ($publishedSermonId !== null) {
                            $updates['published_sermon_id'] = null;
                        }

                        if ($publishedAt !== null) {
                            $updates['published_at'] = null;
                        }
                    } elseif ($publishedSermonId !== null && $publishedAt === null) {
                        $updates['published_at'] = $section->updated_at;
                    }

                    if (
                        in_array($publicationStatus, [
                            ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
                            ServiceSectionPublicationStatus::APPROVED->value,
                            ServiceSectionPublicationStatus::REJECTED->value,
                            ServiceSectionPublicationStatus::PUBLISHED->value,
                        ], true)
                        && $extractedAt === null
                        && is_string($section->extracted_video_path)
                        && $section->extracted_video_path !== ''
                        && is_string($section->extracted_audio_path)
                        && $section->extracted_audio_path !== ''
                    ) {
                        $updates['extracted_at'] = $section->updated_at;
                    }

                    if ($updates !== []) {
                        DB::table('service_sections')
                            ->where('id', $section->id)
                            ->update($updates);
                    }
                }
            });
    }

    private function addChurchServiceReviewChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        if (! $this->constraintExists('church_services', self::REVIEW_STATE_CHECK)) {
            DB::statement(
                'ALTER TABLE church_services ADD CONSTRAINT '.self::REVIEW_STATE_CHECK.' CHECK ('.
                "(review_state = 'not_reviewed' AND manual_reviewed_at IS NULL AND manual_review_reopened_at IS NULL AND manual_review_reopened_by_source IS NULL)".
                " OR (review_state = 'reviewed' AND manual_reviewed_at IS NOT NULL AND manual_review_reopened_at IS NULL AND manual_review_reopened_by_source IS NULL)".
                " OR (review_state = 'reopened' AND manual_reviewed_at IS NOT NULL AND manual_review_reopened_at IS NOT NULL AND manual_review_reopened_by_source IS NOT NULL)".
                ')'
            );
        }

        if (! $this->constraintExists('church_services', self::CANONICAL_CONFLICT_STATE_CHECK)) {
            DB::statement(
                'ALTER TABLE church_services ADD CONSTRAINT '.self::CANONICAL_CONFLICT_STATE_CHECK.' CHECK ('.
                "(canonical_conflict_state = 'none' AND canonical_conflict_detected_at IS NULL AND canonical_conflict_incoming_source IS NULL AND canonical_conflict_reviewed_previously IS NULL AND canonical_conflict_canonical_changed IS NULL AND canonical_conflict_reason IS NULL)".
                " OR (canonical_conflict_state = 'detected' AND canonical_conflict_detected_at IS NOT NULL AND canonical_conflict_incoming_source IS NOT NULL AND canonical_conflict_reason IS NOT NULL)".
                " OR (canonical_conflict_state = 'reopened' AND canonical_conflict_detected_at IS NOT NULL AND canonical_conflict_incoming_source IS NOT NULL AND canonical_conflict_reason IS NOT NULL)".
                ')'
            );
        }
    }

    private function dropChurchServiceReviewChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        foreach ([self::CANONICAL_CONFLICT_STATE_CHECK, self::REVIEW_STATE_CHECK] as $constraint) {
            if (! $this->constraintExists('church_services', $constraint)) {
                continue;
            }

            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE church_services DROP CHECK '.$constraint);

                continue;
            }

            DB::statement('ALTER TABLE church_services DROP CONSTRAINT '.$constraint);
        }
    }

    private function addServiceSectionChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        if (! $this->constraintExists('service_sections', self::SERVICE_SECTION_TIMING_CHECK)) {
            DB::statement(
                'ALTER TABLE service_sections ADD CONSTRAINT '.self::SERVICE_SECTION_TIMING_CHECK.' CHECK ('.
                'start_time >= 0 AND end_time > start_time AND duration >= 0 AND ABS((end_time - start_time) - duration) <= 0.050'.
                ')'
            );
        }

        if (! $this->constraintExists('service_sections', self::SERVICE_SECTION_STATUS_CHECK)) {
            DB::statement(
                'ALTER TABLE service_sections ADD CONSTRAINT '.self::SERVICE_SECTION_STATUS_CHECK.' CHECK ('.
                "(status <> 'skipped') OR publication_status = 'not_applicable'".
                ')'
            );
        }

        if (! $this->constraintExists('service_sections', self::SERVICE_SECTION_PUBLICATION_LINK_CHECK)) {
            DB::statement(
                'ALTER TABLE service_sections ADD CONSTRAINT '.self::SERVICE_SECTION_PUBLICATION_LINK_CHECK.' CHECK ('.
                "((publication_status = 'published' AND published_sermon_id IS NOT NULL AND published_at IS NOT NULL) ".
                "OR (publication_status <> 'published' AND published_sermon_id IS NULL AND published_at IS NULL))".
                ')'
            );
        }

        if (! $this->constraintExists('service_sections', self::SERVICE_SECTION_PUBLICATION_MEDIA_CHECK)) {
            DB::statement(
                'ALTER TABLE service_sections ADD CONSTRAINT '.self::SERVICE_SECTION_PUBLICATION_MEDIA_CHECK.' CHECK ('.
                "((publication_status IN ('approved', 'published') AND extracted_video_path IS NOT NULL AND extracted_audio_path IS NOT NULL AND extracted_at IS NOT NULL) ".
                "OR publication_status IN ('not_applicable', 'pending_approval', 'rejected'))".
                ')'
            );
        }
    }

    private function dropServiceSectionChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        foreach ([
            self::SERVICE_SECTION_PUBLICATION_MEDIA_CHECK,
            self::SERVICE_SECTION_PUBLICATION_LINK_CHECK,
            self::SERVICE_SECTION_STATUS_CHECK,
            self::SERVICE_SECTION_TIMING_CHECK,
        ] as $constraint) {
            if (! $this->constraintExists('service_sections', $constraint)) {
                continue;
            }

            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE service_sections DROP CHECK '.$constraint);

                continue;
            }

            DB::statement('ALTER TABLE service_sections DROP CONSTRAINT '.$constraint);
        }
    }

    private function ensureServiceSectionPublishedSermonForeignKeySupportsChecks(): void
    {
        if (! $this->foreignKeyExists('service_sections', 'service_sections_published_sermon_id_foreign')) {
            return;
        }

        DB::statement('ALTER TABLE service_sections DROP FOREIGN KEY service_sections_published_sermon_id_foreign');
        DB::statement(
            'ALTER TABLE service_sections ADD CONSTRAINT service_sections_published_sermon_id_foreign '.
            'FOREIGN KEY (published_sermon_id) REFERENCES sermons(id) ON DELETE RESTRICT'
        );
    }

    private function restoreServiceSectionPublishedSermonForeignKey(): void
    {
        if ($this->foreignKeyExists('service_sections', 'service_sections_published_sermon_id_foreign')) {
            DB::statement('ALTER TABLE service_sections DROP FOREIGN KEY service_sections_published_sermon_id_foreign');
        }

        DB::statement(
            'ALTER TABLE service_sections ADD CONSTRAINT service_sections_published_sermon_id_foreign '.
            'FOREIGN KEY (published_sermon_id) REFERENCES sermons(id) ON DELETE SET NULL'
        );
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     * @return array<string, mixed>
     */
    private function normalizeChurchServiceReviewState(array $importMetadata, ?string $fallbackIncomingSource = null): array
    {
        $metadata = ChurchServiceImportMetadata::fromArray($importMetadata);

        $reviewState = match (true) {
            is_string($metadata->manualReview?->reopenedAt) => ChurchServiceReviewState::REOPENED,
            is_string($metadata->manualReview?->reviewedAt) => ChurchServiceReviewState::REVIEWED,
            default => ChurchServiceReviewState::NOT_REVIEWED,
        };

        $canonicalConflict = $metadata->canonicalConflict;
        $reviewedAt = $this->parseTimestamp($metadata->manualReview?->reviewedAt);
        $detectedAt = $this->parseTimestamp($canonicalConflict?->detectedAt);
        $incomingSource = $this->normalizeOptionalString($canonicalConflict?->incomingSource)
            ?? $this->normalizeOptionalString($fallbackIncomingSource)
            ?? 'unknown';
        $canonicalConflictState = match (true) {
            $canonicalConflict === null => ChurchServiceCanonicalConflictState::NONE,
            ! $detectedAt instanceof Carbon => ChurchServiceCanonicalConflictState::NONE,
            $canonicalConflict->reviewReopened === true
                && $reviewedAt instanceof Carbon
                && $detectedAt instanceof Carbon
                && ! $reviewedAt->lt($detectedAt) => ChurchServiceCanonicalConflictState::NONE,
            $canonicalConflict->reviewReopened === true => ChurchServiceCanonicalConflictState::REOPENED,
            default => ChurchServiceCanonicalConflictState::DETECTED,
        };

        $canonicalConflictReason = $this->canonicalConflictReason(
            $canonicalConflict?->changes ?? [],
            $canonicalConflict?->conflicts ?? []
        );

        $canonicalConflictDetectedAt = $canonicalConflictState === ChurchServiceCanonicalConflictState::NONE
            ? null
            : $detectedAt?->toIso8601String();
        $canonicalConflictIncomingSource = $canonicalConflictState === ChurchServiceCanonicalConflictState::NONE
            ? null
            : $incomingSource;

        return [
            'review_state' => $reviewState->value,
            'manual_reviewed_at' => $metadata->manualReview?->reviewedAt,
            'manual_reviewed_by_user_id' => $metadata->manualReview?->reviewedByUserId,
            'manual_review_reopened_at' => $metadata->manualReview?->reopenedAt,
            'manual_review_reopened_by_source' => $metadata->manualReview?->reopenedBySource,
            'canonical_conflict_state' => $canonicalConflictState->value,
            'canonical_conflict_detected_at' => $canonicalConflictDetectedAt,
            'canonical_conflict_incoming_source' => $canonicalConflictIncomingSource,
            'canonical_conflict_reviewed_previously' => $canonicalConflictState === ChurchServiceCanonicalConflictState::NONE
                ? null
                : $canonicalConflict?->reviewedPreviously,
            'canonical_conflict_canonical_changed' => $canonicalConflictState === ChurchServiceCanonicalConflictState::NONE
                ? null
                : $canonicalConflict?->canonicalChanged,
            'canonical_conflict_reason' => $canonicalConflictState === ChurchServiceCanonicalConflictState::NONE
                ? null
                : $canonicalConflictReason->value,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    private function canonicalConflictReason(array $changes, array $conflicts): ChurchServiceCanonicalConflictReason
    {
        $hasChanges = $changes !== [];
        $hasConflicts = $conflicts !== [];

        return match (true) {
            $hasChanges && $hasConflicts => ChurchServiceCanonicalConflictReason::CANONICAL_CHANGED_WITH_CONFLICTS,
            $hasChanges => ChurchServiceCanonicalConflictReason::CANONICAL_CHANGED,
            $hasConflicts => ChurchServiceCanonicalConflictReason::CONFLICTS_ONLY,
            default => ChurchServiceCanonicalConflictReason::UNSPECIFIED,
        };
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $database = (string) DB::getDatabaseName();

        return DB::table('information_schema.table_constraints')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = (string) DB::getDatabaseName();

        return DB::table('information_schema.table_constraints')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
