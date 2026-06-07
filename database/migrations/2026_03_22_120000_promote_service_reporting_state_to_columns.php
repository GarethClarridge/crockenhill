<?php

declare(strict_types=1);

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{count:int, samples:array<int, int>}>
     */
    private array $audit = [];

    public function up(): void
    {
        $this->addChurchServiceItemColumns();
        $this->addServiceSectionColumns();
        $this->backfillChurchServiceItemSectionTypes();
        $this->backfillServiceSectionReportingState();
        $this->auditMissingServiceSectionItemReferences();
        $this->emitAuditSummary();
    }

    public function down(): void
    {
        if (Schema::hasTable('service_sections')) {
            Schema::table('service_sections', function (Blueprint $table): void {
                if (Schema::hasIndex('service_sections', 'service_sections_reporting_song_match_index')) {
                    $table->dropIndex('service_sections_reporting_song_match_index');
                }

                if (Schema::hasIndex('service_sections', 'service_sections_song_match_type_index')) {
                    $table->dropIndex('service_sections_song_match_type_index');
                }

                if (Schema::hasIndex('service_sections', 'service_sections_matched_item_id_index')) {
                    $table->dropIndex('service_sections_matched_item_id_index');
                }

                if (Schema::hasIndex('service_sections', 'service_sections_expected_item_id_index')) {
                    $table->dropIndex('service_sections_expected_item_id_index');
                }

                $columns = array_values(array_filter([
                    Schema::hasColumn('service_sections', 'song_match_type') ? 'song_match_type' : null,
                    Schema::hasColumn('service_sections', 'matched_item_id') ? 'matched_item_id' : null,
                    Schema::hasColumn('service_sections', 'expected_item_id') ? 'expected_item_id' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (! Schema::hasTable('church_service_items')) {
            return;
        }

        Schema::table('church_service_items', function (Blueprint $table): void {
            if (Schema::hasIndex('church_service_items', 'church_service_items_section_type_index')) {
                $table->dropIndex('church_service_items_section_type_index');
            }

            if (Schema::hasColumn('church_service_items', 'section_type')) {
                $table->dropColumn('section_type');
            }
        });
    }

    private function addChurchServiceItemColumns(): void
    {
        if (! Schema::hasTable('church_service_items')) {
            return;
        }

        Schema::table('church_service_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('church_service_items', 'section_type')) {
                $table->enum('section_type', $this->serviceSectionTypeValues())
                    ->nullable()
                    ->after('type');
            }

            if (! Schema::hasIndex('church_service_items', 'church_service_items_section_type_index')) {
                $table->index('section_type');
            }
        });
    }

    private function addServiceSectionColumns(): void
    {
        if (! Schema::hasTable('service_sections')) {
            return;
        }

        Schema::table('service_sections', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_sections', 'song_match_type')) {
                $table->enum('song_match_type', $this->songMatchTypeValues())
                    ->nullable()
                    ->after('metadata');
            }

            if (! Schema::hasColumn('service_sections', 'matched_item_id')) {
                $table->unsignedBigInteger('matched_item_id')
                    ->nullable()
                    ->after('song_match_type');
            }

            if (! Schema::hasColumn('service_sections', 'expected_item_id')) {
                $table->unsignedBigInteger('expected_item_id')
                    ->nullable()
                    ->after('matched_item_id');
            }

            if (! Schema::hasIndex('service_sections', 'service_sections_song_match_type_index')) {
                $table->index('song_match_type');
            }

            if (! Schema::hasIndex('service_sections', 'service_sections_reporting_song_match_index')) {
                $table->index(
                    ['media_processing_log_id', 'church_service_item_id', 'section_type', 'song_match_type'],
                    'service_sections_reporting_song_match_index'
                );
            }

            if (! Schema::hasIndex('service_sections', 'service_sections_matched_item_id_index')) {
                $table->index('matched_item_id');
            }

            if (! Schema::hasIndex('service_sections', 'service_sections_expected_item_id_index')) {
                $table->index('expected_item_id');
            }
        });
    }

    private function backfillChurchServiceItemSectionTypes(): void
    {
        if (! Schema::hasTable('church_service_items') || ! Schema::hasColumn('church_service_items', 'section_type')) {
            return;
        }

        DB::table('church_service_items')
            ->select(['id', 'type', 'title', 'metadata'])
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = $this->decodeJsonArray($row->metadata ?? null);
                    $metadataType = $metadata['section_type'] ?? null;

                    if (is_string($metadataType) && ServiceSectionType::tryFrom($metadataType) === null) {
                        $this->recordAudit('church_service_items.invalid_metadata_section_type', (int) $row->id);
                    }

                    DB::table('church_service_items')
                        ->where('id', $row->id)
                        ->update([
                            'section_type' => $this->resolveChurchServiceItemSectionType(
                                type: (string) ($row->type ?? ''),
                                title: (string) ($row->title ?? ''),
                                metadata: $metadata,
                            )->value,
                        ]);
                }
            });
    }

    private function backfillServiceSectionReportingState(): void
    {
        if (! Schema::hasTable('service_sections')) {
            return;
        }

        DB::table('service_sections')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = $this->decodeJsonArray($row->metadata ?? null);
                    $alignment = is_array($metadata['oos_alignment'] ?? null) ? $metadata['oos_alignment'] : [];

                    DB::table('service_sections')
                        ->where('id', $row->id)
                        ->update([
                            'song_match_type' => $this->resolveSongMatchType((int) $row->id, $alignment['song_match_type'] ?? null),
                            'matched_item_id' => $this->resolveItemId((int) $row->id, 'matched_item_id', $alignment['matched_item_id'] ?? null),
                            'expected_item_id' => $this->resolveItemId((int) $row->id, 'expected_item_id', $alignment['expected_item_id'] ?? null),
                        ]);
                }
            });
    }

    private function auditMissingServiceSectionItemReferences(): void
    {
        if (! Schema::hasTable('service_sections') || ! Schema::hasTable('church_service_items')) {
            return;
        }

        $this->recordMissingReferenceAudit(
            'service_sections.missing_matched_item_reference',
            column: 'matched_item_id',
        );

        $this->recordMissingReferenceAudit(
            'service_sections.missing_expected_item_reference',
            column: 'expected_item_id',
        );
    }

    private function recordMissingReferenceAudit(string $key, string $column): void
    {
        $missingQuery = DB::table('service_sections')
            ->leftJoin('church_service_items', 'church_service_items.id', '=', "service_sections.{$column}")
            ->whereNotNull("service_sections.{$column}")
            ->whereNull('church_service_items.id');

        $count = (clone $missingQuery)->count();

        if ($count === 0) {
            return;
        }

        $samples = (clone $missingQuery)
            ->orderBy('service_sections.id')
            ->limit(20)
            ->pluck('service_sections.id');

        foreach ($samples as $sample) {
            if (is_numeric($sample)) {
                $this->recordAudit($key, (int) $sample);
            }
        }

        $existing = $this->audit[$key]['count'] ?? 0;
        $this->audit[$key]['count'] = max($existing, $count);
        $this->audit[$key]['samples'] = $this->audit[$key]['samples'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveChurchServiceItemSectionType(
        string $type,
        string $title,
        array $metadata,
    ): ServiceSectionType {
        $metadataType = $metadata['section_type'] ?? $metadata['email_type'] ?? null;

        if (is_string($metadataType)) {
            $resolved = ServiceSectionType::tryFrom($metadataType);

            if ($resolved instanceof ServiceSectionType) {
                return $resolved;
            }
        }

        return match (strtolower($type)) {
            'songs' => ServiceSectionType::Song,
            'bibles' => ServiceSectionType::BibleReading,
            default => ServiceSectionType::inferFromTitle($title),
        };
    }

    private function resolveSongMatchType(int $sectionId, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $this->recordAudit('service_sections.invalid_song_match_type', $sectionId);

            return null;
        }

        $resolved = ServiceSectionSongMatchType::tryFrom($value);

        if ($resolved instanceof ServiceSectionSongMatchType) {
            return $resolved->value;
        }

        $this->recordAudit('service_sections.invalid_song_match_type', $sectionId);

        return null;
    }

    private function resolveItemId(int $sectionId, string $field, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        $this->recordAudit("service_sections.invalid_{$field}", $sectionId);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function recordAudit(string $key, int $rowId): void
    {
        $current = $this->audit[$key] ?? ['count' => 0, 'samples' => []];
        $current['count']++;

        if (count($current['samples']) < 20 && ! in_array($rowId, $current['samples'], true)) {
            $current['samples'][] = $rowId;
        }

        $this->audit[$key] = $current;
    }

    private function emitAuditSummary(): void
    {
        if ($this->audit === []) {
            return;
        }

        foreach ($this->audit as $key => $summary) {
            $samples = $summary['samples'] === [] ? 'none' : implode(', ', $summary['samples']);

            echo sprintf(
                '[TD-017A] %s rows=%d sample_ids=%s%s',
                $key,
                $summary['count'],
                $samples,
                PHP_EOL,
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function serviceSectionTypeValues(): array
    {
        return array_map(
            static fn (ServiceSectionType $type): string => $type->value,
            ServiceSectionType::cases(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function songMatchTypeValues(): array
    {
        return array_map(
            static fn (ServiceSectionSongMatchType $type): string => $type->value,
            ServiceSectionSongMatchType::cases(),
        );
    }
};
