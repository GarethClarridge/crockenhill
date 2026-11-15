<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use App\Services\HistoricMedia\ProductionSermonBinding;
use App\Services\Import\ProductionCensusExporter;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class ProductionSermonBindingTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = base_path('storage/scratch/reference-correction-20261115-wp51/test-'.bin2hex(random_bytes(8)));
        mkdir($this->folder, 0700, true);
        Storage::fake('binding_old', ['root' => $this->folder.'/old']);
        Storage::fake('historic_staging', ['root' => $this->folder.'/staging']);
        Storage::fake('historic_quarantine', ['root' => $this->folder.'/quarantine']);
        config([
            'media-processing.storage.sermon_disk' => 'binding_old',
            'media-processing.storage.transcript_disk' => 'binding_old',
            'thumbnail-generation.storage.disk' => 'binding_old',
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
        ]);
    }

    #[Test]
    #[TestWith([true])]
    #[TestWith([false])]
    public function an_explicit_reference_correction_rebuilds_passage_and_filters(bool $cached): void
    {
        $old = \App\Models\ScripturePassage::factory()->create(['normalized_reference' => '3 John 1:10-11']);
        $correct = $cached ? \App\Models\ScripturePassage::factory()->create(['normalized_reference' => '2 John 1:10-11']) : null;
        [$target, $bundle] = $this->fixture(null, ['reference' => '3 John 1:10-11', 'scripture_passage_id' => $old->id]);
        $bound = $this->bind($bundle, $target->id, ['reference' => '2 John 1:10-11']);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $importer->importService($bound, $importer->prepareService($bound)->planHash);
        $target->refresh();
        $this->assertSame('2 John 1:10-11', $target->reference);
        $this->assertSame('curated', $target->reference_provenance?->value);
        $this->assertSame($correct?->id, $target->scripture_passage_id);
        $this->assertSame(['2 John'], $target->scriptureFilters()->pluck('bible_book')->all());
        $this->assertSame([1], $target->scriptureFilters()->pluck('bible_chapter')->all());
        $this->assertSame('2 John 1:10-11', $target->media_replacement_history[0]['corrections_applied']['reference']['applied']);
        $this->assertSame('already_present', $importer->prepareService($bound)->classification);
    }

    #[Test]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    #[TestWith([false, false])]
    public function bound_partial_recording_is_the_union_of_production_and_local(bool $production, bool $local): void
    {
        [$target, $bundle] = $this->fixture(null, ['partial_recording' => $production]);
        $bundle['services'][0]['media_graph']['publications'][0]['partial_recording'] = $local;
        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)->logicalHash($bundle['services'][0]['media_graph']);
        $bound = $this->bind($this->rehash($bundle), $target->id);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $importer->importService($bound, $importer->prepareService($bound)->planHash);
        $this->assertSame($production || $local, $target->fresh()->partial_recording);
        $this->assertSame('already_present', $importer->prepareService($bound)->classification);
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['nonsense'])]
    #[TestWith(['2 John 2:10-11'])]
    #[TestWith(['John 3:999'])]
    #[TestWith([123])]
    public function malformed_reference_corrections_are_refused(mixed $reference): void
    {
        [$target, $bundle] = $this->fixture();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('correction');
        $this->bind($bundle, $target->id, ['reference' => $reference]);
    }

    #[Test]
    public function a_reference_correction_is_hash_bound(): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $this->bind($bundle, $target->id, ['reference' => '2 John 1:10-11']);
        $bound['production_binding']['entries'][0]['corrections']['reference'] = '3 John 1:10-11';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('binding hash');
        app(HistoricProcessingResultBundle::class)->validate($this->rehash($bound));
    }

    #[Test]
    public function a_reference_correction_does_not_bypass_the_seed_signature(): void
    {
        [$target, $bundle] = $this->fixture();
        $bundle['services'][0]['date'] = '2024-12-15';
        $publication = &$bundle['services'][0]['media_graph']['publications'][0];
        $publication['date'] = '2024-12-15';
        $publication['title'] = 'The Birth of Our Saviour';
        unset($publication);
        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)->logicalHash($bundle['services'][0]['media_graph']);
        $this->expectException(\App\Exceptions\SeedResidueException::class);
        $this->expectExceptionMessage('seeded');
        $this->bind($this->rehash($bundle), $target->id, ['reference' => '2 John 1:10-11']);
    }

    #[Test]
    public function a_cleared_partial_flag_is_detected_on_replay(): void
    {
        [$target, $bundle] = $this->fixture(null, ['partial_recording' => true]);
        $bound = $this->bind($bundle, $target->id);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $importer->importService($bound, $importer->prepareService($bound)->planHash);
        $target->refresh()->forceFill(['partial_recording' => false])->saveQuietly();
        $this->assertNotSame('already_present', $importer->prepareService($bound)->classification);
    }

    #[Test]
    public function binding_replaces_in_place_retains_old_bytes_and_reruns_without_changes(): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $this->bind($bundle, $target->id);
        Bus::fake();
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bound);
        $result = $importer->importService($bound, $plan->planHash);
        $target->refresh();

        $this->assertDatabaseCount('sermons', 1);
        $this->assertSame($target->id, $result['processing_log']->sermon_id);
        $this->assertSame('production-public-address', $target->slug);
        $this->assertNotSame('Approved local title', $target->title);
        $this->assertSame('curated', $target->title_provenance->value);
        $this->assertSame(SermonPublicationState::Quarantined, $target->publication_state);
        $this->assertSame('historic_quarantine', $target->asset_disk);
        $this->assertStringContainsString('production-bindings/'.$bound['production_binding']['binding_hash'].'/', $target->audio_file_path);
        $this->assertSame('approved audio', Storage::disk('historic_quarantine')->get($target->audio_file_path));
        $this->assertSame('old audio', Storage::disk('binding_old')->get('old.mp3'));
        $this->assertSame('old video', Storage::disk('binding_old')->get('old.mp4'));
        $history = $target->getAttribute('media_replacement_history');
        $this->assertCount(1, $history);
        $this->assertSame('old.mp3', $history[0]['previous_row']['audio_file_path']);
        $this->assertSame('old.mp4', $history[0]['previous_row']['video_file_path']);
        $this->assertSame(hash_file('sha256', $this->folder.'/census.jsonl'), $history[0]['census_sha256']);
        $this->assertSame(hash('sha256', 'old audio'), $history[0]['previous_assets']['audio_file_path']['sha256']);
        $this->assertSame('Romans', $history[0]['previous_scripture_filters'][0]['bible_book']);

        $secondPlan = $importer->prepareService($bound);
        $this->assertSame('already_present', $secondPlan->classification, $secondPlan->reason);
        $second = $importer->importService($bound, $secondPlan->planHash);
        $this->assertSame([], $second['created_assets']);
        $this->assertSame($history, $target->fresh()->getAttribute('media_replacement_history'));
        Bus::assertNothingDispatched();
    }

    #[Test]
    #[TestWith(['slug'])]
    #[TestWith(['audio_file_path'])]
    #[TestWith(['summary'])]
    public function a_changed_production_row_is_rejected_before_any_apply_write(string $field): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $this->bind($bundle, $target->id);
        $target->forceFill([$field => 'changed'])->saveQuietly();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('census');
        try {
            app(HistoricProcessingResultBundleImporter::class)->prepareService($bound);
        } finally {
            $this->assertDatabaseCount('media_processing_logs', 0);
            $this->assertSame([], Storage::disk('historic_quarantine')->allFiles());
        }
    }

    #[Test]
    public function changed_old_bytes_are_rejected_even_if_the_row_is_unchanged(): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $this->bind($bundle, $target->id);
        Storage::disk('binding_old')->put('old.mp3', 'bad audio');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('census');
        app(HistoricProcessingResultBundleImporter::class)->prepareService($bound);
    }

    #[Test]
    public function apply_rechecks_the_target_after_preparation(): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $this->bind($bundle, $target->id);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bound);
        $target->forceFill(['summary' => 'changed after prepare'])->saveQuietly();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('census');
        try {
            DB::transaction(fn () => $importer->persistPreparedService($plan, $plan->planHash));
        } finally {
            $this->assertDatabaseCount('media_processing_logs', 0);
        }
    }

    #[Test]
    #[TestWith([718])]
    #[TestWith([720])]
    #[TestWith([726])]
    public function held_back_ids_are_never_bound(int $id): void
    {
        [, $bundle] = $this->fixture($id);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('held');
        $this->bind($bundle, $id);
    }

    #[Test]
    #[TestWith(['unmapped'])]
    #[TestWith(['duplicate_run'])]
    #[TestWith(['duplicate_target'])]
    #[TestWith(['unknown_run'])]
    #[TestWith(['unknown_target'])]
    #[TestWith(['wrong_service'])]
    #[TestWith(['tampered_census'])]
    #[TestWith(['truncated_census'])]
    #[TestWith(['missing_publication_asset'])]
    #[TestWith(['publication_slug_collision'])]
    public function generation_refuses_ambiguous_or_mismatched_evidence(string $case): void
    {
        [$target, $bundle] = $this->fixture();
        $mapping = [['processing_key' => 'approved-run', 'target_sermon_id' => $target->id]];
        if ($case === 'unmapped') {
            $mapping = [];
        } elseif ($case === 'duplicate_run' || $case === 'duplicate_target') {
            $mapping[] = $mapping[0];
            if ($case === 'duplicate_target') {
                $mapping[1]['processing_key'] = 'another-run';
            }
        } elseif ($case === 'unknown_run') {
            $mapping[0]['processing_key'] = 'unknown';
        } elseif ($case === 'unknown_target') {
            $mapping[0]['target_sermon_id'] = 999999;
        } elseif ($case === 'wrong_service') {
            $bundle['services'][0]['date'] = '2026-08-03';
            $bundle = $this->rehash($bundle);
        } elseif ($case === 'tampered_census') {
            file_put_contents($this->folder.'/census.jsonl', str_replace('old.mp3', 'bad.mp3', file_get_contents($this->folder.'/census.jsonl')));
        } elseif ($case === 'missing_publication_asset') {
            $bundle['services'][0]['assets'][0]['roles'] = ['run_audio_file_path'];
            $bundle = $this->rehash($bundle);
        } elseif ($case === 'publication_slug_collision') {
            $publication = $bundle['services'][0]['media_graph']['publications'][0];
            $publication['section_key'] = 'another-publication';
            $publication['publication_key'] = 'another-publication';
            $publication['slug'] = $target->slug;
            $bundle['services'][0]['media_graph']['publications'][] = $publication;
            $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)->logicalHash($bundle['services'][0]['media_graph']);
            $bundle = $this->rehash($bundle);
        } else {
            $lines = file($this->folder.'/census.jsonl');
            array_pop($lines);
            file_put_contents($this->folder.'/census.jsonl', implode('', $lines));
        }
        $this->expectException(RuntimeException::class);
        app(ProductionSermonBinding::class)->bind($bundle, $this->folder.'/census.jsonl', $mapping);
    }

    #[Test]
    public function binding_and_bundle_hashes_protect_the_mapping_and_run_graph(): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $this->bind($bundle, $target->id);
        $bound['production_binding']['entries'][0]['target_sermon_id'] = 123456;
        $bound = $this->rehash($bound);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('binding');
        app(HistoricProcessingResultBundle::class)->validate($bound);
    }

    #[Test]
    public function an_explicit_create_cannot_fall_back_to_enriching_an_existing_sermon(): void
    {
        [$target, $bundle] = $this->fixture();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unmapped census sermon');
        $this->bind($bundle, null);
    }

    #[Test]
    public function the_file_only_command_writes_a_valid_private_bundle_and_refuses_overwrite(): void
    {
        [$target, $bundle] = $this->fixture();
        file_put_contents($this->folder.'/bundle.json', CanonicalJson::encodeReadable($bundle));
        file_put_contents($this->folder.'/mapping.json', CanonicalJson::encodeReadable([
            ['processing_key' => 'approved-run', 'target_sermon_id' => $target->id],
        ]));
        $options = ['--bundle' => $this->folder.'/bundle.json', '--census' => $this->folder.'/census.jsonl',
            '--mapping' => $this->folder.'/mapping.json', '--output' => $this->folder.'/bound.json'];
        Bus::fake();
        $before = $target->fresh()->getRawOriginal();
        $this->artisan('historic-import:bind-production-sermons', $options)->assertSuccessful();
        $contents = file_get_contents($this->folder.'/bound.json');
        $bound = app(HistoricProcessingResultBundle::class)->decode($contents);
        $this->assertSame($target->id, $bound['production_binding']['entries'][0]['target_sermon_id']);
        $this->assertSame(0600, fileperms($this->folder.'/bound.json') & 0777);
        $this->artisan('historic-import:bind-production-sermons', $options)->assertFailed();
        $this->assertSame($contents, file_get_contents($this->folder.'/bound.json'));
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertDatabaseCount('media_processing_logs', 0);
        $this->assertSame([], Storage::disk('historic_quarantine')->allFiles());
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function an_explicit_create_with_no_census_match_creates_and_reruns(): void
    {
        [$target, $bundle] = $this->fixture();
        $bundle['services'][0]['date'] = '2026-08-09';
        $bundle['services'][0]['media_graph']['publications'][0]['date'] = '2026-08-09';
        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)->logicalHash($bundle['services'][0]['media_graph']);
        $bound = $this->bind($this->rehash($bundle), null);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bound);
        $result = $importer->importService($bound, $plan->planHash);
        $this->assertNotSame($target->id, $result['processing_log']->sermon_id);
        $this->assertDatabaseCount('sermons', 2);
        $this->assertSame('old.mp3', $target->fresh()->audio_file_path);
        $second = $importer->prepareService($bound);
        $this->assertSame('already_present', $second->classification, $second->reason);
    }

    #[Test]
    public function a_census_without_media_hashes_is_rejected(): void
    {
        [$target, $bundle] = $this->fixture();
        app(ProductionCensusExporter::class)->export($this->folder.'/no-hashes.jsonl', true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('media hashes');
        app(ProductionSermonBinding::class)->bind($bundle, $this->folder.'/no-hashes.jsonl', [
            ['processing_key' => 'approved-run', 'target_sermon_id' => $target->id],
        ]);
    }

    #[Test]
    public function a_held_service_cannot_be_smuggled_in_as_an_explicit_create(): void
    {
        [, $bundle] = $this->fixture(718);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unmapped census sermon');
        $this->bind($bundle, null);
    }

    #[Test]
    public function changed_retained_bytes_block_a_replay_after_replacement(): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $this->bind($bundle, $target->id);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bound);
        $importer->importService($bound, $plan->planHash);
        Storage::disk('binding_old')->put('old.mp3', 'bad audio');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('census');
        $importer->prepareService($bound);
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function an_old_processing_identity_with_the_same_source_hash_may_only_point_at_the_bound_target(bool $conflicting): void
    {
        [$target, $bundle] = $this->fixture();
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'file_hash' => $bundle['services'][0]['media_graph']['run']['file_hash'],
            'sermon_id' => $conflicting ? Sermon::factory()->create()->id : $target->id,
        ]);
        $bound = $this->bind($bundle, $target->id);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bound);
        if ($conflicting) {
            $this->assertSame('conflict', $plan->classification);
            $this->expectException(RuntimeException::class);
            $importer->importService($bound, $plan->planHash);

            return;
        }
        $this->assertSame('create', $plan->classification, $plan->reason);
        $result = $importer->importService($bound, $plan->planHash);
        $this->assertSame($target->id, $result['processing_log']->sermon_id);
        $this->assertDatabaseCount('sermons', 1);
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function scripture_outcomes_survive_both_changed_and_unchanged_public_slugs(bool $sameSlug): void
    {
        [$target, $bundle] = $this->fixture();
        $graph = $bundle['services'][0]['media_graph'];
        $slug = $sameSlug ? $target->slug : $graph['publications'][0]['slug'];
        $graph['publications'][0]['slug'] = $slug;
        $outcome = $graph['publications'][0]['scripture_passage_outcome'];
        $graph['metadata']['historic_import']['scripture_passage_outcomes'] = [$slug => $outcome];
        $graph['logical_hash'] = app(HistoricProcessingResultInventory::class)->logicalHash($graph);
        $bundle['services'][0]['media_graph'] = $graph;
        $bound = $this->bind($this->rehash($bundle), $target->id);
        $this->assertSame([$target->slug => $outcome], $bound['services'][0]['media_graph']['metadata']['historic_import']['scripture_passage_outcomes']);
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function operation_owned_apply_and_audit_preserve_the_binding_without_staging(bool $withBinding): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $withBinding ? $this->bind($bundle, $target->id) : $bundle;
        $operation = HistoricImportOperation::query()->create([
            'operation_id' => 'historic-'.str_repeat('a', 32),
            'binding_hash' => str_repeat('b', 64), 'batch_key' => 'wp33',
            'manifest_hashes' => ['video' => $bound['bundle_hash']],
            'plan_hash' => str_repeat('c', 64), 'target_fingerprint' => str_repeat('d', 64),
            'runtime_fingerprint' => str_repeat('e', 64), 'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 0,
        ]);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bound, 0, $operation);
        $result = DB::transaction(fn (): array => $importer->persistPreparedService($plan, $plan->planHash));
        $this->assertStringStartsWith('historic-import/'.$operation->operation_id.'/', $result['processing_log']->sermon->audio_file_path);
        if ($withBinding) {
            $this->assertStringContainsString('/production-bindings/', $target->fresh()->audio_file_path);
            $this->assertSame('already_present', $importer->prepareService($bound, 0, $operation)->classification);
        }
        Storage::disk('historic_staging')->delete('approved.mp3');
        $audit = $importer->prepareServiceForAudit($bound);
        $this->assertSame('already_present', $audit->classification, $audit->reason);
        $this->assertSame('old audio', Storage::disk('binding_old')->get('old.mp3'));
    }

    #[Test]
    public function a_replacement_keeps_every_hand_entered_detail_and_records_what_it_did_not_apply(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Production Preacher']);
        [$target, $bundle] = $this->fixture(null, [
            'title' => 'Production title', 'preacher' => 'Production Preacher', 'preacher_id' => $preacher->id,
            'reference' => 'Colossians 1:15-20', 'series' => 'Production series',
            'summary' => 'Production summary', 'points' => ['Production point'], 'show_summary' => true,
        ]);
        $bound = $this->bind($bundle, $target->id);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bound);
        $importer->importService($bound, $plan->planHash);
        $target->refresh();

        $this->assertSame('Production title', $target->title);
        $this->assertSame('curated', $target->title_provenance->value);
        $this->assertSame('Production Preacher', $target->preacher);
        $this->assertSame($preacher->id, $target->preacher_id);
        $this->assertSame('Colossians 1:15-20', $target->reference);
        $this->assertSame('Production series', $target->series);
        $this->assertSame('Production summary', $target->summary);
        $this->assertSame(['Production point'], $target->points);
        $this->assertTrue($target->show_summary);
        $this->assertSame('production-public-address', $target->slug);
        $this->assertStringContainsString('production-bindings/', $target->audio_file_path);

        $entry = $target->getAttribute('media_replacement_history')[0];
        $this->assertSame('Approved local title', $entry['unapplied_local_values']['title']);
        $this->assertSame('Approved local summary', $entry['unapplied_local_values']['summary']);
        $this->assertSame('Unknown', $entry['unapplied_local_values']['preacher']);
        $this->assertArrayHasKey('reference', $entry['unapplied_local_values']);
        $this->assertSame([], $entry['filled_from_local']);
        $this->assertSame([], $entry['corrections_applied']);

        $again = $importer->prepareService($bound);
        $this->assertSame('already_present', $again->classification, $again->reason);
    }

    #[Test]
    public function the_production_scripture_filters_survive_a_replacement(): void
    {
        [$target, $bundle] = $this->fixture();
        $bound = $this->bind($bundle, $target->id);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $importer->importService($bound, $importer->prepareService($bound)->planHash);

        $filters = $target->scriptureFilters()->get();
        $this->assertCount(1, $filters);
        $this->assertSame('Romans', $filters[0]->bible_book);
        $this->assertSame(8, $filters[0]->bible_chapter);
    }

    #[Test]
    public function an_empty_production_summary_is_filled_and_a_non_empty_one_is_kept(): void
    {
        [$target, $bundle] = $this->fixture(null, ['summary' => null, 'meta_description' => 'Production description']);
        $bound = $this->bind($bundle, $target->id);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $importer->importService($bound, $importer->prepareService($bound)->planHash);
        $target->refresh();

        $this->assertSame('Approved local summary', $target->summary);
        $this->assertSame('Production description', $target->meta_description);
        $entry = $target->getAttribute('media_replacement_history')[0];
        $this->assertSame(['summary' => 'Approved local summary'], array_intersect_key($entry['filled_from_local'], ['summary' => 1]));
        $this->assertArrayNotHasKey('summary', $entry['unapplied_local_values']);
    }

    #[Test]
    public function a_listed_correction_replaces_the_production_value_and_is_recorded(): void
    {
        [$target, $bundle] = $this->fixture(null, ['title' => 'Persistant prayer']);
        $bundle = $this->asEveningService($bundle);
        $bound = $this->bind($bundle, $target->id, ['service' => 'evening', 'title' => 'Persistent prayer']);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $importer->importService($bound, $importer->prepareService($bound)->planHash);
        $target->refresh();

        $this->assertSame('evening', $target->service->value);
        $this->assertSame('Persistent prayer', $target->title);
        $applied = $target->getAttribute('media_replacement_history')[0]['corrections_applied'];
        $this->assertSame('morning', $applied['service']['production']);
        $this->assertSame('evening', $applied['service']['applied']);
        $this->assertSame('Persistant prayer', $applied['title']['production']);
        $this->assertSame('already_present', $importer->prepareService($bound)->classification);
    }

    #[Test]
    public function a_service_mismatch_needs_a_correction_to_bind(): void
    {
        [$target, $bundle] = $this->fixture();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('identity differs');
        $this->bind($this->asEveningService($bundle), $target->id);
    }

    #[Test]
    #[TestWith([['slug' => 'other']])]
    #[TestWith([['preacher_id' => '3']])]
    #[TestWith([['bogus' => 'x']])]
    #[TestWith([['service' => 'midnight']])]
    #[TestWith([['title' => '']])]
    public function an_unlisted_or_malformed_correction_is_refused(array $corrections): void
    {
        [$target, $bundle] = $this->fixture();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('correction');
        $this->bind($bundle, $target->id, $corrections);
    }

    #[Test]
    public function the_binding_hash_covers_the_corrections(): void
    {
        [$target, $bundle] = $this->fixture();
        $plain = $this->bind($bundle, $target->id);
        $corrected = $this->bind($bundle, $target->id, ['title' => 'Corrected title']);
        $this->assertNotSame($plain['production_binding']['binding_hash'], $corrected['production_binding']['binding_hash']);

        $corrected['production_binding']['entries'][0]['corrections'] = ['title' => 'Another title'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('binding');
        app(HistoricProcessingResultBundle::class)->validate($this->rehash($corrected));
    }

    #[Test]
    #[TestWith([0])]
    #[TestWith([1])]
    public function a_create_whose_slug_belongs_to_a_different_production_sermon_gets_a_free_deterministic_slug(int $extraOccupants): void
    {
        $occupants = [['slug' => 'local-address', 'date' => '2017-09-24', 'service' => 'evening']];
        if ($extraOccupants === 1) {
            $occupants[] = ['slug' => 'local-address-1', 'date' => '2014-11-09', 'service' => 'evening'];
        }
        [$target, $bundle] = $this->fixture(null, [], $occupants);
        $expected = $extraOccupants === 1 ? 'local-address-2' : 'local-address-1';
        $bound = $this->bind($this->asLaterDate($bundle), null);
        $again = $this->bind($this->asLaterDate($bundle), null);

        $entry = $bound['production_binding']['entries'][0];
        $this->assertSame($expected, $entry['slug_allocation']['allocated_slug']);
        $this->assertSame('local-address', $entry['slug_allocation']['source_slug']);
        $this->assertSame($bound['production_binding']['binding_hash'], $again['production_binding']['binding_hash']);
        $this->assertSame($expected, $bound['services'][0]['media_graph']['publications'][0]['slug']);

        $importer = app(HistoricProcessingResultBundleImporter::class);
        $result = $importer->importService($bound, $importer->prepareService($bound)->planHash);
        $this->assertSame($expected, $result['processing_log']->sermon->slug);
        $this->assertSame('local-address', Sermon::query()->where('date', '2017-09-24')->value('slug'));
        $this->assertSame('old.mp3', $target->fresh()->audio_file_path);
        $second = $importer->prepareService($bound);
        $this->assertSame('already_present', $second->classification, $second->reason);
    }

    #[Test]
    public function an_allocated_slug_that_is_taken_by_apply_time_is_refused(): void
    {
        [, $bundle] = $this->fixture(null, [], [['slug' => 'local-address', 'date' => '2017-09-24', 'service' => 'evening']]);
        $bound = $this->bind($this->asLaterDate($bundle), null);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bound);
        Sermon::factory()->create(['slug' => 'local-address-1', 'date' => '2001-01-01']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug already belongs');
        $importer->importService($bound, $plan->planHash);
    }

    #[Test]
    public function a_tampered_slug_allocation_is_refused(): void
    {
        [, $bundle] = $this->fixture(null, [], [['slug' => 'local-address', 'date' => '2017-09-24', 'service' => 'evening']]);
        $bound = $this->bind($this->asLaterDate($bundle), null);
        $bound['production_binding']['entries'][0]['slug_allocation']['allocated_slug'] = 'local-address-9';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('binding');
        app(HistoricProcessingResultBundle::class)->validate($this->rehash($bound));
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    private function asLaterDate(array $bundle): array
    {
        $bundle['services'][0]['date'] = '2026-08-09';
        $bundle['services'][0]['media_graph']['publications'][0]['date'] = '2026-08-09';
        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)->logicalHash($bundle['services'][0]['media_graph']);

        return $this->rehash($bundle);
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    private function asEveningService(array $bundle): array
    {
        $bundle['services'][0]['service'] = 'evening';
        $bundle['services'][0]['media_graph']['publications'][0]['service'] = 'evening';
        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)->logicalHash($bundle['services'][0]['media_graph']);

        return $this->rehash($bundle);
    }

    /**
     * @param  array<string, mixed>  $targetOverrides
     * @param  list<array<string, mixed>>  $occupants
     * @return array{Sermon, array<string, mixed>}
     */
    private function fixture(?int $targetId = null, array $targetOverrides = [], array $occupants = []): array
    {
        $source = Sermon::factory()->create([
            'title' => 'Approved local title', 'summary' => 'Approved local summary',
            'slug' => 'local-address', 'date' => '2026-08-02', 'service' => 'morning',
            'reference' => null, 'preacher' => 'Unknown', 'preacher_id' => null,
            'audio_file_path' => 'approved.mp3', 'video_file_path' => null,
            'transcript_file_path' => null, 'thumbnail_file_path' => null,
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'approved-run', 'sermon_id' => $source->id,
            'file_hash' => str_repeat('e', 64),
            'audio_file_path' => null, 'video_file_path' => null, 'transcript_file_path' => null,
            'processing_metadata' => ['service_artifacts' => []], 'church_service_id' => null,
        ]);
        $graph = app(HistoricProcessingResultInventory::class)->build($run);
        $source->deleteQuietly();
        $run->deleteQuietly();
        $target = Sermon::factory()->create([
            ...($targetId === null ? [] : ['id' => $targetId]),
            'slug' => 'production-public-address', 'date' => '2026-08-02', 'service' => 'morning',
            'title_provenance' => 'curated',
            'reference' => null,
            'audio_file_path' => 'old.mp3', 'video_file_path' => 'old.mp4',
            'transcript_file_path' => null, 'thumbnail_file_path' => null, 'asset_disk' => 'binding_old',
            ...$targetOverrides,
        ]);
        foreach ($occupants as $occupant) {
            Sermon::factory()->create($occupant);
        }
        Storage::disk('binding_old')->put('old.mp3', 'old audio');
        $target->scriptureFilters()->create(['bible_book' => 'Romans', 'bible_chapter' => 8]);
        Storage::disk('binding_old')->put('old.mp4', 'old video');
        Storage::disk('historic_staging')->put('approved.mp3', 'approved audio');
        app(ProductionCensusExporter::class)->export($this->folder.'/census.jsonl', false);
        $bundle = app(HistoricProcessingResultBundle::class)->make(str_repeat('a', 64), ['version' => 1], [[
            'date' => '2026-08-02', 'service' => 'morning',
            'source_manifest_hash' => str_repeat('b', 64), 'evidence_set_hash' => str_repeat('c', 64),
            'pre_review_hash' => str_repeat('d', 64), 'media_graph' => $graph,
            'livestream_source_revision' => [], 'assets' => [[
                'path' => 'approved.mp3', 'size' => 14, 'sha256' => hash('sha256', 'approved audio'),
                'kind' => 'audio', 'roles' => ['publication:main:audio_file_path'],
            ]],
        ]]);

        return [$target, $bundle];
    }

    /** @param array<string, mixed> $bundle @param array<string, mixed> $corrections @return array<string, mixed> */
    private function bind(array $bundle, ?int $target, array $corrections = []): array
    {
        return app(ProductionSermonBinding::class)->bind($bundle, $this->folder.'/census.jsonl', [
            ['processing_key' => 'approved-run', 'target_sermon_id' => $target]
            + ($corrections === [] ? [] : ['corrections' => $corrections]),
        ]);
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    private function rehash(array $bundle): array
    {
        $bundle['bundle_hash'] = CanonicalJson::hash(array_diff_key($bundle, ['bundle_hash' => true]));

        return $bundle;
    }
}
