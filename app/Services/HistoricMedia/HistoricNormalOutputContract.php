<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use RuntimeException;

class HistoricNormalOutputContract
{
    /**
     * Version 4 reclassified publication scripture filters from portable to
     * deterministically rebuilt. Version 5 (HIR3) made
     * `scripture_passage_outcome` required, so an export can no longer omit the
     * settlement and let the apply read the omission as approval.
     */
    public const int VERSION = 5;

    private const array ALLOWED_PRESENCE = ['required', 'nullable'];

    private const array ALLOWED_TRANSPORT = [
        'portable',
        'deterministically_rebuilt',
        'ephemeral',
    ];

    private const array ALLOWED_REMAP = [
        'none',
        'natural_key',
        'asset_path',
        'local_foreign_key',
    ];

    /** @var array<string, mixed>|null */
    private ?array $cachedMatrix = null;

    /**
     * @return array<string, mixed>
     */
    public function matrix(): array
    {
        return $this->cachedMatrix ??= [
            'version' => self::VERSION,
            'dimensions' => [
                'presence' => self::ALLOWED_PRESENCE,
                'transport' => self::ALLOWED_TRANSPORT,
                'remap' => self::ALLOWED_REMAP,
            ],
            'entities' => [
                'media_graph' => [
                    'fields' => [
                        'processing_key' => $this->field('required', 'portable', 'none'),
                        'metadata' => $this->field('required', 'portable', 'none'),
                        'logical_hash' => $this->field('required', 'deterministically_rebuilt', 'none'),
                    ],
                    'relationships' => [
                        'run' => $this->field('required', 'portable', 'none'),
                        'steps' => $this->field('required', 'portable', 'local_foreign_key'),
                        'segments' => $this->field('required', 'portable', 'local_foreign_key'),
                        'sections' => $this->field('required', 'portable', 'local_foreign_key'),
                        'publications' => $this->field('required', 'portable', 'natural_key'),
                        'song_videos' => $this->field('required', 'portable', 'natural_key'),
                    ],
                ],
                'run' => [
                    'fields' => [
                        'processing_id' => $this->field('required', 'portable', 'none'),
                        'processing_type' => $this->field('required', 'portable', 'none'),
                        'status' => $this->field('required', 'portable', 'none'),
                        'current_step' => $this->field('nullable', 'portable', 'none'),
                        'error_message' => $this->field('nullable', 'portable', 'none'),
                        'original_filename' => $this->field('required', 'portable', 'none'),
                        'file_hash' => $this->field('nullable', 'portable', 'none'),
                        'file_size' => $this->field('nullable', 'portable', 'none'),
                        'duration' => $this->field('nullable', 'portable', 'none'),
                        'extracted_date' => $this->field('nullable', 'portable', 'none'),
                        'extracted_service' => $this->field('nullable', 'portable', 'none'),
                        'audio_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'video_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'transcript_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'rms_log_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'sermon_start_time' => $this->field('nullable', 'portable', 'none'),
                        'sermon_end_time' => $this->field('nullable', 'portable', 'none'),
                        'threshold_method' => $this->field('nullable', 'portable', 'none'),
                        'adaptive_threshold' => $this->field('nullable', 'portable', 'none'),
                        'rms_stats' => $this->field('nullable', 'portable', 'none'),
                        'started_at' => $this->field('nullable', 'portable', 'none'),
                        'completed_at' => $this->field('nullable', 'portable', 'none'),
                        'is_degraded_completion' => $this->field('required', 'portable', 'none'),
                    ],
                ],
                'steps' => [
                    'fields' => [
                        'step' => $this->field('required', 'portable', 'none'),
                        'status' => $this->field('required', 'portable', 'none'),
                        'message' => $this->field('nullable', 'portable', 'none'),
                        'started_at' => $this->field('nullable', 'portable', 'none'),
                        'completed_at' => $this->field('nullable', 'portable', 'none'),
                    ],
                ],
                'segments' => [
                    'fields' => [
                        'segment_key' => $this->field('required', 'portable', 'natural_key'),
                        'segment_index' => $this->field('required', 'portable', 'none'),
                        'start_time' => $this->field('required', 'portable', 'none'),
                        'end_time' => $this->field('required', 'portable', 'none'),
                        'duration' => $this->field('required', 'portable', 'none'),
                        'classification' => $this->field('required', 'portable', 'none'),
                        'avg_rms' => $this->field('nullable', 'portable', 'none'),
                        'peak_rms' => $this->field('nullable', 'portable', 'none'),
                        'is_sermon_candidate' => $this->field('required', 'portable', 'none'),
                        'is_sermon_segment' => $this->field('required', 'portable', 'none'),
                        'segment_order' => $this->field('required', 'portable', 'none'),
                        'metadata' => $this->field('nullable', 'portable', 'none'),
                    ],
                ],
                'sections' => [
                    'fields' => [
                        'section_key' => $this->field('required', 'portable', 'natural_key'),
                        'section_order' => $this->field('required', 'portable', 'none'),
                        'section_type' => $this->field('required', 'portable', 'none'),
                        'title' => $this->field('nullable', 'portable', 'none'),
                        'summary' => $this->field('nullable', 'portable', 'none'),
                        'metadata' => $this->field('nullable', 'portable', 'none'),
                        'start_time' => $this->field('required', 'portable', 'none'),
                        'end_time' => $this->field('required', 'portable', 'none'),
                        'duration' => $this->field('required', 'portable', 'none'),
                        'confidence' => $this->field('nullable', 'portable', 'none'),
                        'status' => $this->field('required', 'portable', 'none'),
                        'needs_manual_review' => $this->field('required', 'portable', 'none'),
                        'source_segment_keys' => $this->field('required', 'portable', 'local_foreign_key'),
                        'service_item_identity' => $this->field('nullable', 'portable', 'natural_key'),
                        'matched_item_identity' => $this->field('nullable', 'portable', 'natural_key'),
                        'expected_item_identity' => $this->field('nullable', 'portable', 'natural_key'),
                        'song_match_type' => $this->field('nullable', 'portable', 'none'),
                        'publication_status' => $this->field('required', 'portable', 'none'),
                        'extracted_video_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'extracted_audio_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'extracted_at' => $this->field('nullable', 'portable', 'none'),
                        'published_at' => $this->field('nullable', 'portable', 'none'),
                        'unpublished_expires_at' => $this->field('nullable', 'portable', 'none'),
                        'published_publication_key' => $this->field('nullable', 'portable', 'natural_key'),
                    ],
                ],
                'publications' => [
                    'fields' => [
                        'publication_key' => $this->field('required', 'portable', 'natural_key'),
                        'section_key' => $this->field('nullable', 'portable', 'natural_key'),
                        'content_type' => $this->field('required', 'portable', 'none'),
                        'date' => $this->field('required', 'portable', 'none'),
                        'service' => $this->field('nullable', 'portable', 'none'),
                        'slug' => $this->field('required', 'portable', 'natural_key'),
                        'filetype' => $this->field('required', 'portable', 'none'),
                        'title' => $this->field('required', 'portable', 'none'),
                        'reference' => $this->field('nullable', 'portable', 'none'),
                        'series' => $this->field('nullable', 'portable', 'none'),
                        'summary' => $this->field('nullable', 'portable', 'none'),
                        'meta_description' => $this->field('nullable', 'portable', 'none'),
                        'points' => $this->field('nullable', 'portable', 'none'),
                        'show_summary' => $this->field('required', 'portable', 'none'),
                        'show_points' => $this->field('required', 'portable', 'none'),
                        'duration' => $this->field('nullable', 'portable', 'none'),
                        'segment_start_time' => $this->field('nullable', 'portable', 'none'),
                        'segment_end_time' => $this->field('nullable', 'portable', 'none'),
                        'preacher_display_name' => $this->field('required', 'portable', 'none'),
                        'preacher' => $this->field('nullable', 'portable', 'natural_key'),
                        'preacher_source' => $this->field('nullable', 'portable', 'none'),
                        'preacher_confidence' => $this->field('nullable', 'portable', 'none'),
                        'needs_preacher_review' => $this->field('required', 'portable', 'none'),
                        'source_type' => $this->field('required', 'portable', 'none'),
                        'video_quality_status' => $this->field('required', 'portable', 'none'),
                        'video_quality_reason' => $this->field('nullable', 'portable', 'none'),
                        'video_visibility_override' => $this->field('required', 'portable', 'none'),
                        'video_quality_assessed_at' => $this->field('nullable', 'portable', 'none'),
                        'thumbnail_generated_at' => $this->field('nullable', 'portable', 'none'),
                        'thumbnail_metadata' => $this->field('nullable', 'portable', 'asset_path'),
                        /**
                         * Scripture filters are an index over `reference`, owned by
                         * SermonObserver via SermonScriptureFilterIndexService. The importer
                         * cannot make the bundle's rows authoritative without becoming a
                         * second writer to that index, so they are rebuilt on arrival and
                         * carried in the manifest as the evidence the rebuild is compared
                         * against, not as rows to insert.
                         */
                        'scripture_filters' => $this->field('required', 'deterministically_rebuilt', 'none'),
                        /**
                         * F59: a passage is relinked at the destination by its
                         * portable natural key, and where no passage could be
                         * settled the approved terminal absence travels with the
                         * publication as the reason. Both are part of the normal
                         * output, so neither may be silently dropped by an export.
                         *
                         * HIR3: the outcome is *required* even though the passage
                         * is nullable. A publication always has a settlement —
                         * linked, an approved absence, or an explicitly unsettled
                         * one that HistoricScripturePassageRequirements refuses —
                         * and a null outcome would be indistinguishable from
                         * approval.
                         */
                        'scripture_passage' => $this->field('nullable', 'portable', 'natural_key'),
                        'scripture_passage_outcome' => $this->field('required', 'portable', 'none'),
                        'audio_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'video_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'transcript_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                        'thumbnail_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                    ],
                ],
                'song_videos' => [
                    'fields' => [
                        'section_key' => $this->field('required', 'portable', 'natural_key'),
                        'song_canonical_key' => $this->field('required', 'portable', 'natural_key'),
                        'church_service_identity' => $this->field('nullable', 'portable', 'natural_key'),
                        'video_file_path' => $this->field('required', 'portable', 'asset_path'),
                        'duration' => $this->field('nullable', 'portable', 'none'),
                        'recorded_date' => $this->field('nullable', 'portable', 'none'),
                        'is_featured' => $this->field('required', 'portable', 'none'),
                    ],
                ],
                'service_manifest' => [
                    'fields' => [
                        'date' => $this->field('required', 'portable', 'none'),
                        'service' => $this->field('required', 'portable', 'none'),
                        'service_identity' => $this->field('required', 'portable', 'natural_key'),
                        'source' => $this->field('required', 'portable', 'none'),
                        'summary' => $this->field('nullable', 'portable', 'none'),
                        'notices' => $this->field('nullable', 'portable', 'none'),
                        'chapter_markers' => $this->field('nullable', 'portable', 'none'),
                        'items' => $this->field('required', 'portable', 'natural_key'),
                    ],
                ],
                'service_items' => [
                    'fields' => [
                        'service_identity' => $this->field('required', 'portable', 'natural_key'),
                        'position' => $this->field('required', 'portable', 'none'),
                        'canonical_identity' => $this->field('nullable', 'portable', 'natural_key'),
                        'type' => $this->field('required', 'portable', 'none'),
                        'section_type' => $this->field('nullable', 'portable', 'none'),
                        'source' => $this->field('nullable', 'portable', 'none'),
                        'title' => $this->field('required', 'portable', 'none'),
                        'source_title' => $this->field('nullable', 'portable', 'none'),
                        'song_canonical_key' => $this->field('nullable', 'portable', 'natural_key'),
                        'livestream_processing_key' => $this->field('nullable', 'portable', 'natural_key'),
                        'livestream_section_key' => $this->field('nullable', 'portable', 'natural_key'),
                        'occurrence_state' => $this->field('nullable', 'portable', 'none'),
                        'manual_occurrence_decision' => $this->field('nullable', 'portable', 'none'),
                        'metadata' => $this->field('nullable', 'portable', 'none'),
                        'source_assertion_hashes' => $this->field('required', 'portable', 'none'),
                    ],
                ],
                'asset_roles' => [
                    'fields' => [
                        'role' => $this->field('required', 'portable', 'natural_key'),
                        'path' => $this->field('required', 'portable', 'asset_path'),
                        'size' => $this->field('required', 'portable', 'none'),
                        'sha256' => $this->field('required', 'portable', 'none'),
                    ],
                ],
            ],
            'tables' => $this->tableMatrix(),
            'representations' => $this->representationMatrix(),
            'excluded' => [
                'source_file_path' => $this->field('nullable', 'ephemeral', 'none'),
                'enhanced_audio_file_path' => $this->field('nullable', 'ephemeral', 'none'),
                'queue_name' => $this->field('nullable', 'ephemeral', 'none'),
                'job_id' => $this->field('nullable', 'ephemeral', 'none'),
                'attempt_count' => $this->field('nullable', 'ephemeral', 'none'),
                'owner_user_id' => $this->field('nullable', 'ephemeral', 'none'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $canary
     */
    public function assertCanary(array $canary): void
    {
        $this->assertValidMatrix();
        $this->assertExactKeys($canary, ['media_graph', 'service_manifest', 'asset_roles'], 'canary');

        if (! is_array($canary['media_graph'])) {
            throw new RuntimeException('canary.media_graph must be an object.');
        }

        $this->assertInventory($canary['media_graph']);
        $serviceManifest = $this->entity('service_manifest');
        $this->assertExactKeys(
            $canary['service_manifest'],
            $this->stringKeys($serviceManifest['fields']),
            'service_manifest',
        );

        foreach ($serviceManifest['fields'] as $field => $definition) {
            $this->assertPresence($canary['service_manifest'], $field, $definition, "service_manifest.{$field}");
        }

        if (! is_array($canary['service_manifest']['items']) || $canary['service_manifest']['items'] === []) {
            throw new RuntimeException('service_manifest.items must contain the canary service items.');
        }

        foreach ($canary['service_manifest']['items'] as $index => $item) {
            $this->assertRecord($item, $this->entity('service_items')['fields'], "service_manifest.items.{$index}");
        }

        if (! is_array($canary['asset_roles']) || $canary['asset_roles'] === []) {
            throw new RuntimeException('asset_roles must contain the canary asset roles.');
        }

        $roles = [];

        foreach ($canary['asset_roles'] as $index => $asset) {
            $this->assertRecord($asset, $this->entity('asset_roles')['fields'], "asset_roles.{$index}");

            if (isset($roles[$asset['role']])) {
                throw new RuntimeException("asset_roles contains duplicate role {$asset['role']}.");
            }

            $roles[$asset['role']] = true;
        }

        $this->assertRepresentationPathsResolve($canary);
    }

    /**
     * @param  array<string, mixed>  $inventory
     */
    public function assertInventory(array $inventory): void
    {
        $this->assertValidMatrix();
        $mediaGraph = $this->entity('media_graph');
        $this->assertExactKeys(
            $inventory,
            [...$this->stringKeys($mediaGraph['fields']), ...$this->stringKeys($mediaGraph['relationships'])],
            'media_graph',
        );

        foreach ($mediaGraph['fields'] as $field => $definition) {
            $this->assertPresence($inventory, $field, $definition, "media_graph.{$field}");
        }

        if (! is_string($inventory['logical_hash']) || preg_match('/\A[a-f0-9]{64}\z/', $inventory['logical_hash']) !== 1) {
            throw new RuntimeException('media_graph.logical_hash must be a lowercase SHA-256.');
        }

        $this->assertRecord($inventory['run'], $this->entity('run')['fields'], 'media_graph.run');

        foreach (['steps', 'segments', 'sections', 'publications', 'song_videos'] as $relationship) {
            if (! is_array($inventory[$relationship])) {
                throw new RuntimeException("media_graph.{$relationship} must be a list.");
            }

            foreach ($inventory[$relationship] as $index => $record) {
                $this->assertRecord(
                    $record,
                    $this->entity($relationship)['fields'],
                    "media_graph.{$relationship}.{$index}",
                );
            }
        }

        if (! is_array($inventory['metadata'])) {
            throw new RuntimeException('media_graph.metadata must be an object.');
        }
    }

    public function assertValidMatrix(): void
    {
        $matrix = $this->matrix();

        foreach ($matrix['entities'] as $entity => $definition) {
            foreach ($definition['fields'] as $path => $field) {
                $this->assertClassification($field, "contract.{$entity}.{$path}");
            }

            foreach ($definition['relationships'] ?? [] as $path => $field) {
                $this->assertClassification($field, "contract.{$entity}.{$path}");
            }
        }

        foreach ($matrix['tables'] as $table => $definition) {
            $overlap = array_intersect(array_keys($definition['classified']), array_keys($definition['excluded']));

            if ($overlap !== []) {
                throw new RuntimeException("Contract table {$table} classifies a column as both durable and excluded.");
            }

            foreach ($definition['classified'] as $column => $field) {
                $this->assertClassification($field, "contract.tables.{$table}.{$column}");
            }

            foreach ($definition['excluded'] as $column => $field) {
                $this->assertClassification($field, "contract.tables.{$table}.excluded.{$column}");
            }
        }

        $representations = $matrix['representations'] ?? null;

        if (! is_array($representations)) {
            throw new RuntimeException('Contract representations must be an object.');
        }

        if (array_diff(array_keys($matrix['tables']), array_keys($representations)) !== []) {
            throw new RuntimeException('Contract representations are missing a table.');
        }

        foreach ($matrix['tables'] as $table => $definition) {
            $tableRepresentations = $representations[$table] ?? null;

            if (! is_array($tableRepresentations)) {
                throw new RuntimeException("Contract representations for {$table} must be an object.");
            }

            $classifiedColumns = array_keys($definition['classified']);
            $mappedColumns = array_keys($tableRepresentations);

            if (array_diff($classifiedColumns, $mappedColumns) !== [] || array_diff($mappedColumns, $classifiedColumns) !== []) {
                throw new RuntimeException("Contract representations for {$table} must map every classified column exactly once.");
            }

            foreach ($tableRepresentations as $column => $path) {
                if (! is_string($path) || trim($path) === '') {
                    throw new RuntimeException("Contract representation {$table}.{$column} must name an emitted field.");
                }

                $representedField = $this->directEntityFieldFor($path, $column);

                if ($representedField !== null
                    && $definition['classified'][$column]['presence'] !== $representedField['presence']) {
                    throw new RuntimeException("Contract table presence for {$table}.{$column} must match its emitted field.");
                }
            }
        }

        foreach ($matrix['excluded'] as $path => $field) {
            $this->assertClassification($field, "contract.excluded.{$path}");
        }
    }

    /**
     * The schema inventory is intentionally explicit. A new column in one of the
     * canary tables must be classified here before the normal-output contract can
     * be considered complete.
     *
     * @return array<string, array{classified: array<string, array<string, string>>, excluded: array<string, array<string, string>>}>
     */
    private function tableMatrix(): array
    {
        return [
            'church_services' => $this->table(
                required: ['date', 'service', 'source'],
                nullable: ['summary', 'notices', 'chapter_markers'],
                excluded: [
                    'id', 'original_filename', 'needs_review', 'review_reason', 'review_state',
                    'manual_reviewed_at', 'manual_reviewed_by_user_id', 'manual_review_reopened_at',
                    'manual_review_reopened_by_source', 'canonical_conflict_state',
                    'canonical_conflict_detected_at', 'canonical_conflict_incoming_source',
                    'canonical_conflict_reviewed_previously', 'canonical_conflict_canonical_changed',
                    'canonical_conflict_reason', 'import_metadata', 'pending_structure_merge_source',
                    'created_at', 'updated_at', 'canonical_revision', 'canonical_hash',
                    'reviewed_canonical_revision', 'source_summary', 'canonical_finalization',
                    'projection_policy_version',
                ],
            ),
            'church_service_items' => $this->table(
                required: ['position', 'type', 'title'],
                nullable: ['section_type', 'source', 'source_title', 'metadata', 'canonical_identity', 'occurrence_state', 'manual_occurrence_decision'],
                overrides: [
                    'church_service_id' => $this->field('required', 'deterministically_rebuilt', 'local_foreign_key'),
                    'song_id' => $this->field('nullable', 'deterministically_rebuilt', 'natural_key'),
                    'livestream_processing_id' => $this->field('nullable', 'portable', 'natural_key'),
                    'livestream_service_section_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                ],
                excluded: ['id', 'openlp_search_title', 'active_position', 'created_at', 'updated_at', 'deleted_at'],
            ),
            'songs' => $this->table(
                required: ['title'],
                nullable: ['canonical_key'],
                excluded: [
                    'id', 'slug', 'praise_number', 'author', 'lyrics', 'copyright', 'created_at', 'updated_at',
                    'alternative_title', 'current', 'notes', 'major_category', 'minor_category',
                    'first_line_key', 'alternate_title', 'lyrics_xml', 'lyrics_plain', 'verse_order',
                    'comments', 'ccli_number', 'import_metadata', 'deleted_at',
                ],
            ),
            'preachers' => $this->table(
                required: ['name', 'slug'],
                nullable: [],
                excluded: ['id', 'image_path', 'bio', 'is_active', 'created_at', 'updated_at'],
            ),
            'preacher_aliases' => $this->table(
                required: ['alias'],
                nullable: [],
                excluded: ['id', 'preacher_id', 'created_at', 'updated_at'],
            ),
            'sermons' => $this->table(
                required: [
                    'date', 'content_type', 'filetype', 'title', 'slug', 'preacher', 'show_points',
                    'show_summary', 'needs_preacher_review', 'source_type', 'video_quality_status',
                    'video_visibility_override',
                ],
                nullable: [
                    'service', 'audio_file_path', 'video_file_path', 'video_quality_reason',
                    'video_quality_assessed_at', 'segment_start_time', 'segment_end_time', 'duration',
                    'reference', 'series', 'points', 'transcript_file_path', 'thumbnail_file_path',
                    'thumbnail_generated_at', 'thumbnail_metadata', 'summary', 'meta_description',
                    'preacher_source', 'preacher_confidence',
                ],
                overrides: [
                    'livestream_processing_id' => $this->field('nullable', 'portable', 'natural_key'),
                    'audio_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'video_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'transcript_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'thumbnail_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'preacher_id' => $this->field('nullable', 'deterministically_rebuilt', 'natural_key'),
                ],
                /**
                 * `publication_state`, `asset_disk`, `historic_import_operation_id`
                 * and `title_provenance` are deliberately outside the
                 * portable contract. The audience boundary is a destination
                 * decision: every apply lands quarantined on the destination's
                 * own private disk and is released by a separately authorised
                 * step. Title provenance is local operational state; the title
                 * itself remains portable. Carrying these fields would let an
                 * exported bundle dictate destination-owned state.
                 */
                excluded: [
                    'id', 'scripture_passage_id', 'download_count', 'created_at', 'updated_at',
                    'publication_state', 'asset_disk', 'historic_import_operation_id', 'title_provenance',
                ],
            ),
            'media_processing_logs' => $this->table(
                required: ['processing_id', 'processing_type', 'status', 'original_filename', 'is_degraded_completion'],
                nullable: [
                    'current_step', 'error_message', 'file_hash', 'file_size', 'duration', 'extracted_date',
                    'extracted_service', 'audio_file_path', 'video_file_path', 'transcript_file_path',
                    'rms_log_path', 'sermon_start_time', 'sermon_end_time', 'processing_metadata',
                    'threshold_method', 'adaptive_threshold', 'rms_stats', 'started_at', 'completed_at',
                ],
                overrides: [
                    'audio_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'video_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'transcript_file_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'rms_log_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'sermon_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                    'church_service_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                ],
                excluded: [
                    'id', 'superseded_at', 'superseded_by_processing_log_id', 'source_file_path',
                    'enhanced_audio_file_path', 'dedup_key', 'queue_name', 'job_id', 'attempt_count',
                    'ai_analysis', 'visual_samples', 'song_clusters', 'visual_sample_count',
                    'visual_processing_time', 'owner_user_id', 'created_at', 'updated_at',
                    // Destination-owned: names the operation that applied this run.
                    'historic_import_operation_id',
                ],
            ),
            'livestream_segments' => $this->table(
                required: [
                    'segment_index', 'start_time', 'end_time', 'duration', 'classification',
                    'is_sermon_segment', 'is_sermon_candidate', 'segment_order',
                ],
                nullable: ['avg_rms', 'peak_rms', 'metadata'],
                overrides: [
                    'media_processing_log_id' => $this->field('required', 'deterministically_rebuilt', 'local_foreign_key'),
                ],
                excluded: ['id', 'visual_confidence', 'visual_sample_count', 'calibration_method', 'created_at', 'updated_at'],
            ),
            'sermon_processing_steps' => $this->table(
                required: ['step', 'status'],
                nullable: ['message', 'started_at', 'completed_at'],
                overrides: [
                    'processing_id' => $this->field('required', 'portable', 'natural_key'),
                ],
                excluded: ['id', 'created_at', 'updated_at'],
            ),
            'sermon_scripture_filters' => $this->table(
                required: [],
                nullable: [],
                overrides: [
                    'bible_book' => $this->field('required', 'deterministically_rebuilt', 'none'),
                    'bible_chapter' => $this->field('required', 'deterministically_rebuilt', 'none'),
                ],
                excluded: ['id', 'sermon_id', 'created_at', 'updated_at'],
            ),
            'service_sections' => $this->table(
                required: [
                    'section_type', 'section_order', 'start_time', 'end_time', 'duration', 'status',
                    'needs_manual_review', 'source_segment_ids', 'publication_status',
                ],
                nullable: [
                    'title', 'summary', 'metadata', 'song_match_type', 'confidence', 'published_at',
                    'extracted_video_path', 'extracted_audio_path', 'extracted_at', 'unpublished_expires_at',
                ],
                overrides: [
                    'media_processing_log_id' => $this->field('required', 'deterministically_rebuilt', 'local_foreign_key'),
                    'church_service_item_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                    'matched_item_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                    'expected_item_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                    'published_sermon_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                    'extracted_video_path' => $this->field('nullable', 'portable', 'asset_path'),
                    'extracted_audio_path' => $this->field('nullable', 'portable', 'asset_path'),
                ],
                /**
                 * `asset_disk` is destination-owned, like its counterparts on
                 * `sermons` and `song_videos`: every apply lands on the
                 * destination's own private disk, so carrying the source's disk
                 * name would let an exported bundle name storage that does not
                 * exist there. The candidate's paths stay portable.
                 */
                excluded: ['id', 'created_at', 'updated_at', 'asset_disk'],
            ),
            'song_videos' => $this->table(
                required: ['video_file_path', 'is_featured'],
                nullable: ['service_section_id', 'church_service_id', 'duration', 'recorded_date'],
                overrides: [
                    'song_id' => $this->field('required', 'deterministically_rebuilt', 'natural_key'),
                    'service_section_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                    'church_service_id' => $this->field('nullable', 'deterministically_rebuilt', 'local_foreign_key'),
                    'video_file_path' => $this->field('required', 'portable', 'asset_path'),
                ],
                // Destination-owned audience and custody state, for the same reason as sermons.
                excluded: ['id', 'created_at', 'updated_at', 'publication_state', 'asset_disk', 'historic_import_operation_id'],
            ),
        ];
    }

    /**
     * Maps each portable schema column to its emitted normal-output field. The
     * values intentionally use logical paths rather than database column names;
     * several relationships must cross a natural-key boundary before export.
     *
     * @return array<string, array<string, string>>
     */
    private function representationMatrix(): array
    {
        return [
            'church_services' => [
                'date' => 'service_manifest.date',
                'service' => 'service_manifest.service',
                'source' => 'service_manifest.source',
                'summary' => 'service_manifest.summary',
                'notices' => 'service_manifest.notices',
                'chapter_markers' => 'service_manifest.chapter_markers',
            ],
            'church_service_items' => [
                'church_service_id' => 'service_manifest.items[].service_identity',
                'position' => 'service_manifest.items[].position',
                'type' => 'service_manifest.items[].type',
                'section_type' => 'service_manifest.items[].section_type',
                'source' => 'service_manifest.items[].source',
                'title' => 'service_manifest.items[].title',
                'source_title' => 'service_manifest.items[].source_title',
                'song_id' => 'service_manifest.items[].song_canonical_key',
                'livestream_processing_id' => 'service_manifest.items[].livestream_processing_key',
                'livestream_service_section_id' => 'service_manifest.items[].livestream_section_key',
                'canonical_identity' => 'service_manifest.items[].canonical_identity',
                'occurrence_state' => 'service_manifest.items[].occurrence_state',
                'manual_occurrence_decision' => 'service_manifest.items[].manual_occurrence_decision',
                'metadata' => 'service_manifest.items[].metadata',
            ],
            'songs' => [
                'title' => 'service_manifest.items[].title',
                'canonical_key' => 'service_manifest.items[].song_canonical_key',
            ],
            'preachers' => [
                'name' => 'media_graph.publications[].preacher.name',
                'slug' => 'media_graph.publications[].preacher.slug',
            ],
            'preacher_aliases' => [
                'alias' => 'media_graph.publications[].preacher.aliases[]',
            ],
            'sermons' => [
                'date' => 'media_graph.publications[].date',
                'service' => 'media_graph.publications[].service',
                'content_type' => 'media_graph.publications[].content_type',
                'audio_file_path' => 'media_graph.publications[].audio_file_path',
                'filetype' => 'media_graph.publications[].filetype',
                'title' => 'media_graph.publications[].title',
                'slug' => 'media_graph.publications[].slug',
                'reference' => 'media_graph.publications[].reference',
                'preacher' => 'media_graph.publications[].preacher_display_name',
                'preacher_id' => 'media_graph.publications[].preacher.slug',
                'preacher_source' => 'media_graph.publications[].preacher_source',
                'preacher_confidence' => 'media_graph.publications[].preacher_confidence',
                'needs_preacher_review' => 'media_graph.publications[].needs_preacher_review',
                'series' => 'media_graph.publications[].series',
                'points' => 'media_graph.publications[].points',
                'summary' => 'media_graph.publications[].summary',
                'meta_description' => 'media_graph.publications[].meta_description',
                'show_summary' => 'media_graph.publications[].show_summary',
                'show_points' => 'media_graph.publications[].show_points',
                'transcript_file_path' => 'media_graph.publications[].transcript_file_path',
                'thumbnail_file_path' => 'media_graph.publications[].thumbnail_file_path',
                'thumbnail_generated_at' => 'media_graph.publications[].thumbnail_generated_at',
                'thumbnail_metadata' => 'media_graph.publications[].thumbnail_metadata',
                'livestream_processing_id' => 'media_graph.processing_key',
                'video_file_path' => 'media_graph.publications[].video_file_path',
                'video_quality_status' => 'media_graph.publications[].video_quality_status',
                'video_quality_reason' => 'media_graph.publications[].video_quality_reason',
                'video_visibility_override' => 'media_graph.publications[].video_visibility_override',
                'video_quality_assessed_at' => 'media_graph.publications[].video_quality_assessed_at',
                'source_type' => 'media_graph.publications[].source_type',
                'segment_start_time' => 'media_graph.publications[].segment_start_time',
                'segment_end_time' => 'media_graph.publications[].segment_end_time',
                'duration' => 'media_graph.publications[].duration',
            ],
            'media_processing_logs' => [
                'processing_id' => 'media_graph.processing_key',
                'processing_type' => 'media_graph.run.processing_type',
                'status' => 'media_graph.run.status',
                'current_step' => 'media_graph.run.current_step',
                'error_message' => 'media_graph.run.error_message',
                'original_filename' => 'media_graph.run.original_filename',
                'file_hash' => 'media_graph.run.file_hash',
                'file_size' => 'media_graph.run.file_size',
                'duration' => 'media_graph.run.duration',
                'extracted_date' => 'media_graph.run.extracted_date',
                'extracted_service' => 'media_graph.run.extracted_service',
                'audio_file_path' => 'media_graph.run.audio_file_path',
                'video_file_path' => 'media_graph.run.video_file_path',
                'transcript_file_path' => 'media_graph.run.transcript_file_path',
                'rms_log_path' => 'media_graph.run.rms_log_path',
                'sermon_start_time' => 'media_graph.run.sermon_start_time',
                'sermon_end_time' => 'media_graph.run.sermon_end_time',
                'processing_metadata' => 'media_graph.metadata',
                'threshold_method' => 'media_graph.run.threshold_method',
                'adaptive_threshold' => 'media_graph.run.adaptive_threshold',
                'rms_stats' => 'media_graph.run.rms_stats',
                'started_at' => 'media_graph.run.started_at',
                'completed_at' => 'media_graph.run.completed_at',
                'is_degraded_completion' => 'media_graph.run.is_degraded_completion',
                'sermon_id' => 'media_graph.publications[].publication_key',
                'church_service_id' => 'service_manifest.service_identity',
            ],
            'livestream_segments' => [
                'media_processing_log_id' => 'media_graph.processing_key',
                'segment_index' => 'media_graph.segments[].segment_index',
                'start_time' => 'media_graph.segments[].start_time',
                'end_time' => 'media_graph.segments[].end_time',
                'duration' => 'media_graph.segments[].duration',
                'classification' => 'media_graph.segments[].classification',
                'avg_rms' => 'media_graph.segments[].avg_rms',
                'peak_rms' => 'media_graph.segments[].peak_rms',
                'is_sermon_segment' => 'media_graph.segments[].is_sermon_segment',
                'is_sermon_candidate' => 'media_graph.segments[].is_sermon_candidate',
                'segment_order' => 'media_graph.segments[].segment_order',
                'metadata' => 'media_graph.segments[].metadata',
            ],
            'sermon_processing_steps' => [
                'processing_id' => 'media_graph.processing_key',
                'step' => 'media_graph.steps[].step',
                'status' => 'media_graph.steps[].status',
                'message' => 'media_graph.steps[].message',
                'started_at' => 'media_graph.steps[].started_at',
                'completed_at' => 'media_graph.steps[].completed_at',
            ],
            'sermon_scripture_filters' => [
                'bible_book' => 'media_graph.publications[].scripture_filters[].bible_book',
                'bible_chapter' => 'media_graph.publications[].scripture_filters[].bible_chapter',
            ],
            'service_sections' => [
                'media_processing_log_id' => 'media_graph.processing_key',
                'church_service_item_id' => 'media_graph.sections[].service_item_identity',
                'section_type' => 'media_graph.sections[].section_type',
                'section_order' => 'media_graph.sections[].section_order',
                'title' => 'media_graph.sections[].title',
                'summary' => 'media_graph.sections[].summary',
                'start_time' => 'media_graph.sections[].start_time',
                'end_time' => 'media_graph.sections[].end_time',
                'duration' => 'media_graph.sections[].duration',
                'confidence' => 'media_graph.sections[].confidence',
                'status' => 'media_graph.sections[].status',
                'needs_manual_review' => 'media_graph.sections[].needs_manual_review',
                'source_segment_ids' => 'media_graph.sections[].source_segment_keys',
                'metadata' => 'media_graph.sections[].metadata',
                'song_match_type' => 'media_graph.sections[].song_match_type',
                'matched_item_id' => 'media_graph.sections[].matched_item_identity',
                'expected_item_id' => 'media_graph.sections[].expected_item_identity',
                'publication_status' => 'media_graph.sections[].publication_status',
                'published_sermon_id' => 'media_graph.sections[].published_publication_key',
                'extracted_video_path' => 'media_graph.sections[].extracted_video_path',
                'extracted_audio_path' => 'media_graph.sections[].extracted_audio_path',
                'published_at' => 'media_graph.sections[].published_at',
                'extracted_at' => 'media_graph.sections[].extracted_at',
                'unpublished_expires_at' => 'media_graph.sections[].unpublished_expires_at',
            ],
            'song_videos' => [
                'song_id' => 'media_graph.song_videos[].song_canonical_key',
                'service_section_id' => 'media_graph.song_videos[].section_key',
                'church_service_id' => 'media_graph.song_videos[].church_service_identity',
                'video_file_path' => 'media_graph.song_videos[].video_file_path',
                'duration' => 'media_graph.song_videos[].duration',
                'recorded_date' => 'media_graph.song_videos[].recorded_date',
                'is_featured' => 'media_graph.song_videos[].is_featured',
            ],
        ];
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $nullable
     * @param  array<string, array<string, string>>  $overrides
     * @param  list<string>  $excluded
     * @return array{classified: array<string, array<string, string>>, excluded: array<string, array<string, string>>}
     */
    private function table(
        array $required,
        array $nullable,
        array $excluded,
        array $overrides = [],
    ): array {
        $classified = [];

        foreach ($required as $column) {
            $classified[$column] = $this->field('required', 'portable', 'none');
        }

        foreach ($nullable as $column) {
            $classified[$column] = $this->field('nullable', 'portable', 'none');
        }

        foreach ($overrides as $column => $definition) {
            $classified[$column] = $definition;
        }

        $excludedDefinitions = [];

        foreach ($excluded as $column) {
            $excludedDefinitions[$column] = $this->field('nullable', 'ephemeral', 'none');
        }

        return [
            'classified' => $classified,
            'excluded' => $excludedDefinitions,
        ];
    }

    private function assertClassification(mixed $field, string $path): void
    {
        if (! is_array($field)) {
            throw new RuntimeException("Contract entry {$path} must be an object.");
        }

        $this->assertExactKeys($field, ['presence', 'transport', 'remap'], $path);

        if (! is_string($field['presence']) || ! in_array($field['presence'], self::ALLOWED_PRESENCE, true)) {
            throw new RuntimeException("Contract entry {$path} has invalid presence.");
        }

        if (! is_string($field['transport']) || ! in_array($field['transport'], self::ALLOWED_TRANSPORT, true)) {
            throw new RuntimeException("Contract entry {$path} has invalid transport treatment.");
        }

        if (! is_string($field['remap']) || ! in_array($field['remap'], self::ALLOWED_REMAP, true)) {
            throw new RuntimeException("Contract entry {$path} has invalid remap strategy.");
        }

        if ($field['transport'] === 'ephemeral' && $field['remap'] !== 'none') {
            throw new RuntimeException("Ephemeral contract entry {$path} cannot be remapped.");
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function directEntityFieldFor(string $path, string $column): ?array
    {
        $segments = explode('.', $path);
        $field = array_pop($segments);

        if ($field !== $column || str_ends_with($field, '[]')) {
            return null;
        }

        $entity = match ($segments) {
            ['media_graph'] => 'media_graph',
            ['media_graph', 'run'] => 'run',
            ['media_graph', 'steps[]'] => 'steps',
            ['media_graph', 'segments[]'] => 'segments',
            ['media_graph', 'sections[]'] => 'sections',
            ['media_graph', 'publications[]'] => 'publications',
            ['media_graph', 'song_videos[]'] => 'song_videos',
            ['service_manifest'] => 'service_manifest',
            ['service_manifest', 'items[]'] => 'service_items',
            default => null,
        };

        if ($entity === null) {
            return null;
        }

        $definition = $this->entity($entity)['fields'][$field] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /** @param array<string, mixed> $canary */
    private function assertRepresentationPathsResolve(array $canary): void
    {
        foreach ($this->matrix()['representations'] as $table => $representations) {
            foreach ($representations as $column => $path) {
                if (! $this->representationPathResolves($canary, explode('.', $path))) {
                    throw new RuntimeException("Contract representation {$table}.{$column} does not resolve in the canary.");
                }
            }
        }
    }

    /** @param list<string> $segments */
    private function representationPathResolves(mixed $value, array $segments): bool
    {
        if ($segments === []) {
            return true;
        }

        $segment = array_shift($segments);
        $isList = str_ends_with($segment, '[]');
        $key = $isList ? substr($segment, 0, -2) : $segment;

        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return false;
        }

        $next = $value[$key];

        if (! $isList) {
            return $this->representationPathResolves($next, $segments);
        }

        if (! is_array($next) || $next === []) {
            return false;
        }

        foreach ($next as $item) {
            if ($this->representationPathResolves($item, $segments)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function entity(string $name): array
    {
        $entity = $this->matrix()['entities'][$name] ?? null;

        if (! is_array($entity)) {
            throw new RuntimeException("Contract entity {$name} is not defined.");
        }

        return $entity;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $fields
     */
    private function assertRecord(array $record, array $fields, string $path): void
    {
        foreach ($fields as $field => $definition) {
            $this->assertPresence($record, $field, $definition, "{$path}.{$field}");
        }

        $this->assertExactKeys($record, $this->stringKeys($fields), $path);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $definition
     */
    private function assertPresence(array $record, string $field, array $definition, string $path): void
    {
        if (! array_key_exists($field, $record)) {
            throw new RuntimeException("Required contract field {$path} is missing.");
        }

        if ($definition['presence'] === 'required' && $record[$field] === null) {
            throw new RuntimeException("Required contract field {$path} cannot be null.");
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function assertExactKeys(array $value, array $keys, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            throw new RuntimeException("{$path} has missing or unknown contract fields.");
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function stringKeys(array $value): array
    {
        $keys = [];

        foreach (array_keys($value) as $key) {
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @return array{presence: string, transport: string, remap: string}
     */
    private function field(string $presence, string $transport, string $remap): array
    {
        return [
            'presence' => $presence,
            'transport' => $transport,
            'remap' => $remap,
        ];
    }
}
