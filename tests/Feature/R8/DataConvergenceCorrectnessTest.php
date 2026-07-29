<?php

declare(strict_types=1);

namespace Tests\Feature\R8;

use App\Actions\SaveChurchServiceFromAdmin;
use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceItemSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Red contract tests for R8 data-convergence correctness.
 *
 * WP0 intentionally lands these tests before the normalized evidence store and
 * projector exist. Each assertion names the durable behavior a later work
 * package must implement; this class is expected to remain red until WP1–WP4.
 */
class DataConvergenceCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_manually_saved_email_song_survives_an_omitting_email_revision_and_creates_a_proposal(): void
    {
        $service = $this->reviewedServiceWithSong(ChurchServiceItemSource::Email);

        app(ChurchServiceItemSyncService::class)->sync($service, [], ChurchServiceItemSource::Email);

        $this->assertSame(['Reviewed Song'], $this->canonicalTitles($service));
        $this->assertProposalExists($service, ChurchServiceItemSource::Email);
    }

    #[Test]
    public function a_manually_saved_openlp_song_survives_an_omitting_openlp_revision_and_creates_a_proposal(): void
    {
        $service = $this->reviewedServiceWithSong(ChurchServiceItemSource::OpenLp);

        app(ChurchServiceItemSyncService::class)->sync($service, [], ChurchServiceItemSource::OpenLp);

        $this->assertSame(['Reviewed Song'], $this->canonicalTitles($service));
        $this->assertProposalExists($service, ChurchServiceItemSource::OpenLp);
    }

    #[Test]
    public function a_machine_revision_cannot_add_change_or_reorder_items_on_a_reviewed_service(): void
    {
        $service = $this->reviewedServiceWithSong(ChurchServiceItemSource::Email);
        $before = $this->canonicalManifest($service);

        app(ChurchServiceItemSyncService::class)->sync($service, [
            $this->item(1, 'songs', 'Changed Song'),
            $this->item(2, 'custom', 'Machine Addition'),
        ], ChurchServiceItemSource::Livestream);

        $this->assertSame($before, $this->canonicalManifest($service));
        $this->assertProposalExists($service, ChurchServiceItemSource::Livestream);
    }

    #[Test]
    public function resolving_one_source_proposal_does_not_remove_another_source_proposal(): void
    {
        $this->assertNormalizedEvidenceSchemaExists();

        $this->fail('OpenLP and Email proposals must remain independently resolvable and auditable.');
    }

    #[Test]
    public function every_email_openlp_livestream_arrival_permutation_has_the_same_manifest(): void
    {
        $manifests = [];

        foreach ($this->sourcePermutations() as $index => $sources) {
            $day = $index + 1;
            $service = ChurchService::factory()->create(['date' => "2026-08-0{$day}"]);

            foreach ($sources as $source) {
                app(ChurchServiceItemSyncService::class)->sync(
                    $service,
                    $this->sourceItems($source),
                    $source,
                );
            }

            $manifests[] = $this->canonicalManifest($service);
        }

        $this->assertCount(1, array_unique($manifests));
    }

    #[Test]
    public function livestream_service_content_is_order_independent_reviewable_and_hashed(): void
    {
        $this->assertNormalizedEvidenceSchemaExists();

        $this->fail('Livestream summary, notices, and chapter markers must enter review and the canonical hash.');
    }

    #[Test]
    public function ingesting_an_identical_source_revision_writes_nothing(): void
    {
        $this->assertNormalizedEvidenceSchemaExists();

        $this->fail('An identical source revision must not create records, proposals, or canonical writes.');
    }

    #[Test]
    public function concurrent_source_writes_preserve_both_revisions_and_both_proposals(): void
    {
        $this->assertNormalizedEvidenceSchemaExists();

        $this->fail('Concurrent source ingestion must preserve both immutable revisions and proposals.');
    }

    #[Test]
    public function occurrence_state_distinguishes_every_planned_and_observed_combination(): void
    {
        $this->assertTrue(
            Schema::hasColumn('church_service_items', 'occurrence_state'),
            'Canonical items must persist the projector-derived occurrence state.',
        );
    }

    #[Test]
    public function aggregate_equal_but_item_different_manifests_do_not_compare_equal(): void
    {
        $left = hash('sha256', json_encode([
            ['position' => 1, 'type' => 'songs', 'title' => 'Alpha'],
            ['position' => 2, 'type' => 'custom', 'title' => 'Sermon'],
        ], JSON_THROW_ON_ERROR));
        $right = hash('sha256', json_encode([
            ['position' => 1, 'type' => 'songs', 'title' => 'Beta'],
            ['position' => 2, 'type' => 'custom', 'title' => 'Sermon'],
        ], JSON_THROW_ON_ERROR));

        $this->assertNotSame($left, $right);
        $this->assertNormalizedEvidenceSchemaExists();
    }

    private function reviewedServiceWithSong(ChurchServiceItemSource $machineSource): ChurchService
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-30',
            'service' => SermonService::Morning,
            'source' => $machineSource->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'source' => $machineSource->value,
            'title' => 'Reviewed Song',
            'source_title' => 'Reviewed Song',
            'openlp_search_title' => $machineSource === ChurchServiceItemSource::OpenLp ? 'reviewed song@' : null,
            'metadata' => null,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        return app(SaveChurchServiceFromAdmin::class)->execute(
            validated: [
                'date' => $service->date->toDateString(),
                'service' => $service->service->value,
            ],
            syncPayload: [$this->item(1, 'songs', 'Reviewed Song')],
            churchService: $service,
            userId: $admin->id,
        );
    }

    /**
     * @return array{position: int, type: string, title: string, source_title: string, openlp_search_title: null, song_id: null, metadata: null}
     */
    private function item(int $position, string $type, string $title): array
    {
        return [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'source_title' => $title,
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function canonicalTitles(ChurchService $service): array
    {
        return $service->items()->orderBy('position')->pluck('title')->all();
    }

    private function canonicalManifest(ChurchService $service): string
    {
        return hash('sha256', $service->items()
            ->orderBy('position')
            ->get(['position', 'type', 'title', 'source_title', 'openlp_search_title', 'song_id'])
            ->toJson());
    }

    private function assertProposalExists(ChurchService $service, ChurchServiceItemSource $source): void
    {
        $this->assertNormalizedEvidenceSchemaExists();

        $this->assertDatabaseHas('church_service_merge_proposals', [
            'church_service_id' => $service->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('church_service_source_records', [
            'church_service_id' => $service->id,
            'source' => $source->value,
        ]);
    }

    private function assertNormalizedEvidenceSchemaExists(): void
    {
        $this->assertTrue(
            Schema::hasTable('church_service_source_records')
                && Schema::hasTable('church_service_item_assertions')
                && Schema::hasTable('church_service_merge_proposals'),
            'The immutable evidence and proposal schema required by the convergence contract does not exist yet.',
        );
    }

    /**
     * @return list<list<ChurchServiceItemSource>>
     */
    private function sourcePermutations(): array
    {
        $email = ChurchServiceItemSource::Email;
        $openLp = ChurchServiceItemSource::OpenLp;
        $livestream = ChurchServiceItemSource::Livestream;

        return [
            [$email, $openLp, $livestream],
            [$email, $livestream, $openLp],
            [$openLp, $email, $livestream],
            [$openLp, $livestream, $email],
            [$livestream, $email, $openLp],
            [$livestream, $openLp, $email],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceItems(ChurchServiceItemSource $source): array
    {
        return match ($source) {
            ChurchServiceItemSource::Email => [
                $this->item(1, 'songs', 'Opening Song'),
                $this->item(2, 'custom', 'Sermon'),
            ],
            ChurchServiceItemSource::OpenLp => [
                $this->item(1, 'songs', 'Opening Song'),
                $this->item(2, 'bibles', 'John 3:16'),
            ],
            ChurchServiceItemSource::Livestream => [
                $this->item(1, 'custom', 'Welcome'),
                $this->item(2, 'songs', 'Opening Song'),
                $this->item(3, 'custom', 'Sermon'),
            ],
            ChurchServiceItemSource::Manual => [],
        };
    }
}
