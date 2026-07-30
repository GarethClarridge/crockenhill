<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class ImportChurchServiceFromOpenLpTest extends TestCase
{
    use RefreshDatabase;

    private ImportChurchServiceFromOpenLp $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->service = app(ImportChurchServiceFromOpenLp::class);
    }

    #[Test]
    public function it_imports_an_archive_and_returns_a_structured_result(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'song one',
            'title' => 'Song One Canonical',
        ]);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('Notices')
                ),
            ]),
        );

        $result = $this->service->import($upload);

        $this->assertTrue($result->wasCreated);
        $this->assertSame('2024-11-17', $result->churchService->date->toDateString());
        $this->assertSame(SermonService::Morning, $result->churchService->service);
        $this->assertCount(2, $result->churchService->items);
        $this->assertSame('upload_filename', $result->parseResult->importMetadata['parse_method'] ?? null);
        $this->assertSame(1, $result->linkResult['matched']);
        $this->assertSame($song->id, $result->churchService->items->firstWhere('type', 'songs')?->song_id);
        $this->assertSame([], $result->syncResult['conflicts'] ?? []);
    }

    #[Test]
    public function it_persists_the_curation_manifest_hash_on_the_openlp_source_record(): void
    {
        $batchHash = hash('sha256', 'private curation manifest');
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
        );

        $result = $this->service->import($upload, $batchHash);

        $this->assertDatabaseHas('church_service_source_records', [
            'church_service_id' => $result->churchService->id,
            'source' => 'openlp',
            'input_hash' => hash_file('sha256', $upload->getRealPath()),
            'batch_hash' => $batchHash,
        ]);
    }

    #[Test]
    public function it_updates_an_existing_service_instead_of_creating_a_duplicate(): void
    {
        $firstResult = $this->service->import(OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
            ]),
        ));

        $secondResult = $this->service->import(OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One Updated', 'song one@')
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('Reading')
                ),
            ]),
        ));

        $this->assertTrue($firstResult->wasCreated);
        $this->assertFalse($secondResult->wasCreated);
        $this->assertDatabaseCount('church_services', 1);
        $this->assertDatabaseCount('church_service_items', 2);
        $this->assertSame($firstResult->churchService->id, $secondResult->churchService->id);

        $service = ChurchService::query()
            ->with(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->firstOrFail();

        $this->assertSame('Song One Updated', $service->items[0]->title);
        $this->assertSame('Reading', $service->items[1]->title);
    }

    #[Test]
    public function it_merges_the_archive_when_another_import_wins_the_create_race(): void
    {
        // RefreshDatabase wraps the test in an outer transaction. End it so the
        // separate connection below can commit the competing insert and the
        // importer can observe it after its savepoint rolls back.
        DB::rollBack();

        config(['database.connections.race' => config('database.connections.mysql')]);
        DB::purge('race');

        $winnerCreated = false;
        Event::listen('eloquent.saving: '.ChurchService::class, function (ChurchService $churchService) use (&$winnerCreated): void {
            if ($churchService->exists || $winnerCreated) {
                return;
            }

            $winnerCreated = true;
            $winner = (new ChurchService)->setConnection('race');
            $winner->forceFill([
                'date' => '2024-11-17',
                'service' => SermonService::Morning->value,
                'source' => 'email',
                'needs_review' => false,
            ])->saveQuietly();

            throw new UniqueConstraintViolationException(
                'mysql',
                'INSERT INTO church_services',
                [],
                new \PDOException('Duplicate entry for church_services_date_service_unique'),
            );
        });

        try {
            $result = $this->service->import(OpenLpArchiveFactory::makeUpload(
                archiveName: '2024-11-17 AM.osz',
                osjName: '2024-11-17 AM.osj',
                payload: OpenLpArchiveFactory::payload([
                    OpenLpArchiveFactory::serviceItem(
                        OpenLpArchiveFactory::customHeader('Notices')
                    ),
                ]),
            ));

            $service = ChurchService::query()->where([
                'date' => '2024-11-17',
                'service' => SermonService::Morning->value,
            ])->sole();

            $this->assertFalse($result->wasCreated);
            $this->assertSame($service->id, $result->churchService->id);
            $this->assertSame('Notices', ChurchServiceItem::query()->where('church_service_id', $service->id)->sole()->title);
        } finally {
            $service = ChurchService::query()->where([
                'date' => '2024-11-17',
                'service' => SermonService::Morning->value,
            ])->first();

            if ($service instanceof ChurchService) {
                ChurchServiceItem::query()->where('church_service_id', $service->id)->delete();
                $service->deleteQuietly();
            }

            DB::beginTransaction();
        }
    }
}
