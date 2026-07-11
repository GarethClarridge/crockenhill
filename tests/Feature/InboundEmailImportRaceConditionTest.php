<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\OosEmailParseResult;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundEmailImportRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_create_duplicate_church_service_records_when_import_is_called_twice(): void
    {
        $importService = app(InboundEmailImportService::class);

        $email1 = InboundEmail::factory()->create();
        $email2 = InboundEmail::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: '2025-05-11',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.95,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
        );

        // Simulate two concurrent imports for the same service/date
        $importService->import($email1, $parseResult);
        $importService->import($email2, $parseResult);

        $this->assertDatabaseCount('church_services', 1);

        $service = ChurchService::query()
            ->where('date', '2025-05-11')
            ->where('service', 'morning')
            ->first();

        $this->assertNotNull($service);
    }
}
