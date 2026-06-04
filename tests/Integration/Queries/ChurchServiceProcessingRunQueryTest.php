<?php

declare(strict_types=1);

namespace Tests\Integration\Queries;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Queries\ChurchServiceProcessingRunQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceProcessingRunQueryTest extends TestCase
{
    use RefreshDatabase;

    private ChurchServiceProcessingRunQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = app(ChurchServiceProcessingRunQuery::class);
    }

    #[Test]
    public function it_returns_logs_matching_by_identity(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2025-05-20',
            'service' => 'morning',
        ]);

        $matchingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2025-05-20',
            'extracted_service' => 'morning',
        ]);

        $nonMatchingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2025-05-20',
            'extracted_service' => 'evening',
        ]);

        $results = $this->query->forService($service);

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($matchingLog));
        $this->assertFalse($results->contains($nonMatchingLog));
    }

    #[Test]
    public function it_returns_logs_matching_by_explicit_church_service_id(): void
    {
        $service = ChurchService::factory()->create();

        $matchingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '1990-01-01', // Ensure identity doesn't match
        ]);

        $results = $this->query->forService($service);

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($matchingLog));
    }

    #[Test]
    public function it_returns_logs_matching_by_fallback_ids_in_items(): void
    {
        $service = ChurchService::factory()->create();
        $processingId = 'fallback-uuid-123';

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'livestream_processing_id' => $processingId,
        ]);

        $matchingLog = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => $processingId,
            'extracted_date' => '1990-01-01',
        ]);

        $results = $this->query->forService($service);

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($matchingLog));
    }

    #[Test]
    public function it_returns_logs_matching_by_fallback_id_in_livestream_projection_metadata(): void
    {
        $processingId = 'projection-uuid-456';
        $service = ChurchService::factory()->create([
            'import_metadata' => [
                'livestream_projection' => [
                    'processing_id' => $processingId,
                ],
            ],
        ]);

        $matchingLog = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => $processingId,
            'extracted_date' => '1990-01-01',
        ]);

        $results = $this->query->forService($service);

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($matchingLog));
    }

    #[Test]
    public function it_only_returns_livestream_type_logs(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2025-05-20',
            'service' => 'morning',
        ]);

        MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2025-05-20',
            'extracted_service' => 'morning',
        ]);

        MediaProcessingLog::factory()->audio()->create([
            'extracted_date' => '2025-05-20',
            'extracted_service' => 'morning',
        ]);

        $results = $this->query->forService($service);

        $this->assertCount(1, $results);
        $this->assertEquals('livestream', $results->first()->processing_type->value);
    }

    #[Test]
    public function it_orders_results_by_created_at_descending(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2025-05-20',
            'service' => 'morning',
        ]);

        $firstCreated = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2025-05-20',
            'extracted_service' => 'morning',
            'created_at' => now()->subDay(),
        ]);

        $lastCreated = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2025-05-20',
            'extracted_service' => 'morning',
            'created_at' => now(),
        ]);

        $results = $this->query->forService($service);

        $this->assertCount(2, $results);
        $this->assertTrue($results->first()->is($lastCreated));
        $this->assertTrue($results->last()->is($firstCreated));
    }

    #[Test]
    public function it_eager_loads_required_relationships(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2025-05-20',
            'service' => 'morning',
        ]);

        MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2025-05-20',
            'extracted_service' => 'morning',
        ]);

        $results = $this->query->forService($service);
        $log = $results->first();

        $this->assertTrue($log->relationLoaded('serviceSections'));
        $this->assertTrue($log->relationLoaded('processingSteps'));
    }

    #[Test]
    public function it_matches_service_by_identity(): void
    {
        $service = ChurchService::factory()->create(['date' => '2025-05-20', 'service' => 'morning']);
        $matchingLog = MediaProcessingLog::factory()->livestream()->create(['extracted_date' => '2025-05-20', 'extracted_service' => 'morning']);
        $nonMatchingLog = MediaProcessingLog::factory()->livestream()->create(['extracted_date' => '2025-05-20', 'extracted_service' => 'evening']);

        $this->assertTrue($this->query->matchesService($matchingLog, $service));
        $this->assertFalse($this->query->matchesService($nonMatchingLog, $service));
    }

    #[Test]
    public function it_matches_service_by_explicit_id(): void
    {
        $service = ChurchService::factory()->create();
        $matchingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '1990-01-01',
        ]);

        $this->assertTrue($this->query->matchesService($matchingLog, $service));
    }

    #[Test]
    public function it_matches_service_by_fallback_ids(): void
    {
        $service = ChurchService::factory()->create();
        $processingId = 'fallback-uuid-789';

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'livestream_processing_id' => $processingId,
        ]);

        $matchingLog = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => $processingId,
            'extracted_date' => '1990-01-01',
        ]);

        $this->assertTrue($this->query->matchesService($matchingLog, $service));
    }
}
