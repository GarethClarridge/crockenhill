<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\Song;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
