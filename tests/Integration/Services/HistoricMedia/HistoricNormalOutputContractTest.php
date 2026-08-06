<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\ServiceSectionMetadata;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricNormalOutputContract;
use App\Services\HistoricMedia\HistoricNormalOutputServiceManifest;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\HistoricNormalOutputCanary;
use Tests\TestCase;

class HistoricNormalOutputContractTest extends TestCase
{
    use RefreshDatabase;

    /** The run the canary fixture is built as, before it is persisted for real. */
    private const string SOURCE_PROCESSING_ID = HistoricNormalOutputCanary::SOURCE_PROCESSING_ID;

    /** The run the real persistence path creates from it. */
    private const string TARGET_PROCESSING_ID = HistoricNormalOutputCanary::TARGET_PROCESSING_ID;

    private ?HistoricNormalOutputCanary $canary = null;

    #[Test]
    public function a_full_service_canary_has_a_stable_complete_manifest(): void
    {
        $canary = $this->createCanary();
        $contract = app(HistoricNormalOutputContract::class);

        $contract->assertCanary($canary['manifest']);

        $this->assertSame(
            $canary['manifest']['media_graph']['logical_hash'],
            app(HistoricProcessingResultInventory::class)->build($canary['run'])['logical_hash'],
        );
        $this->assertCount(3, $canary['manifest']['media_graph']['segments']);
        $this->assertCount(3, $canary['manifest']['media_graph']['sections']);
        $this->assertCount(2, $canary['manifest']['media_graph']['song_videos']);
        $this->assertSame(
            ['repeated-song', 'repeated-song'],
            array_column($canary['manifest']['media_graph']['song_videos'], 'song_canonical_key'),
        );
        $this->assertSame(
            ['observed-anchor', 'planned-only', 'observed-anchor-2'],
            array_column($canary['manifest']['service_manifest']['items'], 'canonical_identity'),
        );
        $this->assertSame(
            '2026-08-02|morning',
            $canary['manifest']['service_manifest']['service_identity'],
        );
        $this->assertSame(
            ['observed-anchor', null, 'observed-anchor-2'],
            array_column($canary['manifest']['media_graph']['sections'], 'service_item_identity'),
        );
        $this->assertSame(
            'observed-anchor',
            $canary['manifest']['media_graph']['sections'][0]['matched_item_identity'],
        );
        $this->assertSame(
            'observed-anchor-2',
            $canary['manifest']['media_graph']['sections'][2]['expected_item_identity'],
        );
        $this->assertSame(
            '2026-08-02|morning',
            $canary['manifest']['media_graph']['song_videos'][0]['church_service_identity'],
        );
        $this->assertSame(
            ChurchServiceOccurrenceState::PlannedOnly->value,
            $canary['manifest']['service_manifest']['items'][1]['occurrence_state'],
        );
        $this->assertSame(
            'preserve the complete durable item metadata',
            $canary['manifest']['service_manifest']['items'][0]['metadata']['canary_detail'],
        );
        $this->assertSame(
            'rms',
            $canary['manifest']['media_graph']['metadata']['service_artifacts'][0]['kind'],
        );
        $this->assertSame(
            SermonVideoQualityStatus::Approved->value,
            $canary['manifest']['media_graph']['publications'][0]['video_quality_status'],
        );
        $this->assertSame(
            SermonVideoVisibilityOverride::ForceShow->value,
            $canary['manifest']['media_graph']['publications'][0]['video_visibility_override'],
        );
        $this->assertSame(
            "sermons/{$canary['run']->sermon_id}/thumbnail-plain.webp",
            $canary['manifest']['media_graph']['publications'][0]['thumbnail_metadata']['plain_thumbnail_path'],
        );
        $this->assertSame(
            'Canary Preacher',
            $canary['manifest']['media_graph']['publications'][0]['preacher']['name'],
        );
        $this->assertSame(
            [
                ['bible_book' => 'John', 'bible_chapter' => 3],
                ['bible_book' => 'Romans', 'bible_chapter' => 8],
            ],
            $canary['manifest']['media_graph']['publications'][0]['scripture_filters'],
        );
        $this->assertSame(
            'Canary Children Speaker',
            $canary['manifest']['media_graph']['sections'][1]['metadata']['childrens_talk_speaker']['reviewed']['preacher_name'],
        );
        $this->assertSame(
            4,
            count(array_filter(
                $canary['manifest']['asset_roles'],
                fn (array $asset): bool => $asset['path'] === 'shared/song.mp4',
            )),
        );
        $this->assertSame(
            1,
            count(array_unique(array_map(
                fn (array $asset): string => $asset['sha256'],
                array_filter(
                    $canary['manifest']['asset_roles'],
                    fn (array $asset): bool => $asset['path'] === 'shared/song.mp4',
                ),
            ))),
        );
        $this->assertNotSame(
            hash('sha256', 'shared/song.mp4'),
            array_values(array_filter(
                $canary['manifest']['asset_roles'],
                fn (array $asset): bool => $asset['path'] === 'shared/song.mp4',
            ))[0]['sha256'],
        );
    }

    /**
     * The exporter's four-roles-one-file fan-out becomes four separate production
     * copies on import, one per role, because each role owns a distinct destination.
     * The roles and their content must survive that; only the paths may diverge.
     */
    #[Test]
    public function the_real_path_fans_shared_asset_content_out_to_one_copy_per_role(): void
    {
        $canary = $this->createCanary();
        /**
         * Asset roles name their run, so the only legitimate difference between the
         * exporter's roles and the persisted ones is the processing key. Everything
         * after it — the section natural keys especially — must be byte identical, or
         * the real path has not reproduced the graph it was given.
         */
        $sharedRoles = array_map(
            fn (string $role): string => str_replace(
                self::SOURCE_PROCESSING_ID,
                self::TARGET_PROCESSING_ID,
                $role,
            ),
            array_column(
                array_filter(
                    $canary['manifest']['asset_roles'],
                    fn (array $asset): bool => $asset['path'] === 'shared/song.mp4',
                ),
                'role',
            ),
        );
        $persisted = array_values(array_filter(
            $this->persistedAssetRoles($canary['run']),
            fn (array $asset): bool => in_array($asset['role'], $sharedRoles, true),
        ));

        $this->assertCount(4, $sharedRoles);
        $this->assertCount(4, $persisted, 'Every shared-content role must survive persistence.');
        $this->assertCount(
            4,
            array_unique(array_column($persisted, 'path')),
            'Each role owns a distinct production path.',
        );
        $this->assertCount(
            1,
            array_unique(array_column($persisted, 'sha256')),
            'The copies must all still carry the one source content.',
        );
        $this->assertSame(
            [],
            array_values(array_filter(
                array_column($persisted, 'path'),
                fn (string $path): bool => str_starts_with($path, 'shared/'),
            )),
            'No staging path may survive against production media.',
        );
    }

    #[Test]
    public function fails_when_any_durable_canary_field_or_relationship_is_unclassified(): void
    {
        $canary = $this->createCanary();
        unset($canary['manifest']['media_graph']['sections'][0]['source_segment_keys']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required contract field media_graph.sections.0.source_segment_keys is missing.');

        app(HistoricNormalOutputContract::class)->assertCanary($canary['manifest']);
    }

    #[Test]
    public function fails_when_a_required_canary_relationship_is_missing(): void
    {
        $canary = $this->createCanary();
        unset($canary['manifest']['media_graph']['song_videos']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('media_graph has missing or unknown contract fields.');

        app(HistoricNormalOutputContract::class)->assertCanary($canary['manifest']);
    }

    #[Test]
    public function the_transport_matrix_is_versioned_and_uses_only_the_consolidated_taxonomy(): void
    {
        $contract = app(HistoricNormalOutputContract::class);
        $matrix = $contract->matrix();

        $contract->assertValidMatrix();

        $this->assertSame(HistoricNormalOutputContract::VERSION, $matrix['version']);
        $this->assertSame(['required', 'nullable'], $matrix['dimensions']['presence']);
        $this->assertSame(
            ['portable', 'deterministically_rebuilt', 'ephemeral'],
            $matrix['dimensions']['transport'],
        );
        $this->assertSame(
            ['none', 'natural_key', 'asset_path', 'local_foreign_key'],
            $matrix['dimensions']['remap'],
        );
        $this->assertNotContains('production-remapped', $matrix['dimensions']['transport']);
        $this->assertSame(
            'media_graph.sections[].source_segment_keys',
            $matrix['representations']['service_sections']['source_segment_ids'],
        );
        $this->assertSame(
            'media_graph.sections[].matched_item_identity',
            $matrix['representations']['service_sections']['matched_item_id'],
        );
        $this->assertSame(
            'nullable',
            $matrix['entities']['service_items']['fields']['canonical_identity']['presence'],
        );
        $this->assertArrayHasKey('active_position', $matrix['tables']['church_service_items']['excluded']);
        $this->assertSame(
            'required',
            $matrix['entities']['segments']['fields']['is_sermon_segment']['presence'],
        );
    }

    #[Test]
    public function nullable_values_are_preserved_in_the_normal_output(): void
    {
        $canary = $this->createCanary();
        $canary['service']->update([
            'notices' => null,
            'chapter_markers' => null,
        ]);
        $canary['manifest']['service_manifest'] = app(HistoricNormalOutputServiceManifest::class)
            ->build($canary['service']->fresh());

        $this->assertNull($canary['manifest']['service_manifest']['notices']);
        $this->assertNull($canary['manifest']['service_manifest']['chapter_markers']);
        $this->assertNull($canary['manifest']['media_graph']['sections'][0]['metadata']);

        app(HistoricNormalOutputContract::class)->assertCanary($canary['manifest']);
    }

    #[Test]
    public function every_canary_table_column_is_explicitly_classified_or_excluded(): void
    {
        $contract = app(HistoricNormalOutputContract::class);

        foreach ($contract->matrix()['tables'] as $table => $definition) {
            $classifiedColumns = array_keys($definition['classified']);
            $excludedColumns = array_keys($definition['excluded']);
            $contractColumns = [...$classifiedColumns, ...$excludedColumns];
            $schemaColumns = Schema::getColumnListing($table);

            $this->assertSame(
                [],
                array_values(array_diff($schemaColumns, $contractColumns)),
                "{$table} has schema columns missing from the normal-output contract.",
            );
            $this->assertSame(
                [],
                array_values(array_diff($contractColumns, $schemaColumns)),
                "{$table} has contract columns missing from the schema.",
            );
        }
    }

    #[Test]
    public function required_fields_cannot_be_null(): void
    {
        $canary = $this->createCanary();
        $canary['manifest']['media_graph']['sections'][0]['section_key'] = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required contract field media_graph.sections.0.section_key cannot be null.');

        app(HistoricNormalOutputContract::class)->assertCanary($canary['manifest']);
    }

    #[Test]
    public function section_keys_do_not_change_when_local_item_ids_are_remapped(): void
    {
        $canary = $this->createCanary();
        $section = ServiceSection::query()
            ->where('media_processing_log_id', $canary['run']->id)
            ->where('section_order', 1)
            ->sole();
        $originalKey = $canary['manifest']['media_graph']['sections'][0]['section_key'];
        $originalItem = ChurchServiceItem::query()->findOrFail($section->church_service_item_id);
        $replacementItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $originalItem->church_service_id,
            'position' => 4,
            'type' => $originalItem->type,
            'title' => $originalItem->title,
            'canonical_identity' => $originalItem->canonical_identity,
        ]);

        $section->update(['church_service_item_id' => $replacementItem->id]);

        $remapped = app(HistoricProcessingResultInventory::class)->build($canary['run']->fresh());

        $this->assertSame($originalKey, $remapped['sections'][0]['section_key']);
    }

    #[Test]
    public function section_keys_distinguish_resolved_childrens_talk_speakers_without_using_local_ids(): void
    {
        $canary = $this->createCanary();
        $section = ServiceSection::query()
            ->where('media_processing_log_id', $canary['run']->id)
            ->where('section_order', 2)
            ->sole();
        $originalKey = $canary['manifest']['media_graph']['sections'][1]['section_key'];
        $metadata = $section->metadata?->toArray() ?? [];
        $metadata['childrens_talk_speaker']['reviewed']['preacher_name'] = 'Different Canary Speaker';
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();

        $changed = app(HistoricProcessingResultInventory::class)->build($canary['run']->fresh());

        $this->assertNotSame($originalKey, $changed['sections'][1]['section_key']);
    }

    #[Test]
    public function unlinked_preachers_do_not_create_a_canonical_identity_from_the_display_cache(): void
    {
        $sermon = Sermon::factory()->create([
            'preacher' => 'Mark Drury',
            'preacher_id' => null,
        ]);
        $run = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        $inventory = app(HistoricProcessingResultInventory::class)->build($run);

        $this->assertNull($inventory['publications'][0]['preacher']);
        $this->assertSame('Mark Drury', $inventory['publications'][0]['preacher_display_name']);
        app(HistoricNormalOutputContract::class)->assertInventory($inventory);
    }

    /**
     * @return array{run: MediaProcessingLog, service: ChurchService, manifest: array<string, mixed>}
     */
    private function createCanary(): array
    {
        return $this->canary()->createCanary();
    }

    /**
     * @return list<array{role: string, path: string, size: int, sha256: string}>
     */
    private function persistedAssetRoles(MediaProcessingLog $run): array
    {
        return $this->canary()->persistedAssetRoles($run);
    }

    private function canary(): HistoricNormalOutputCanary
    {
        return $this->canary ??= new HistoricNormalOutputCanary;
    }
}
