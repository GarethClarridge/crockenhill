<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingIdentityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingIdentityResolverTest extends TestCase
{
    use DatabaseTransactions;

    private MediaProcessingIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MediaProcessingIdentityResolver;
    }

    #[Test]
    public function it_resolves_identity_from_explicit_columns(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Morning,
            'processing_metadata' => [
                'extracted_date' => '2024-05-13', // Should be ignored
                'extracted_service' => SermonService::Evening->value, // Should be ignored
            ],
        ]);

        $identity = $this->resolver->resolve($log);

        $this->assertNotNull($identity);
        $this->assertEquals('2024-05-12', $identity['date']);
        $this->assertEquals(SermonService::Morning, $identity['service']);
    }

    #[Test]
    public function it_returns_null_when_identity_cannot_be_resolved(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => [],
        ]);

        $this->assertNull($this->resolver->resolve($log));
    }

    #[Test]
    public function it_parses_valid_date(): void
    {
        $this->assertEquals('2024-05-12', $this->resolver->parseDate('2024-05-12'));
    }

    #[Test]
    public function it_returns_null_for_invalid_date_format(): void
    {
        $this->assertNull($this->resolver->parseDate('12-05-2024'));
        $this->assertNull($this->resolver->parseDate('2024-05-32'));
        $this->assertNull($this->resolver->parseDate('not-a-date'));
        $this->assertNull($this->resolver->parseDate(''));
        $this->assertNull($this->resolver->parseDate(null));
    }

    #[Test]
    public function it_parses_valid_service(): void
    {
        $this->assertEquals(SermonService::Morning, $this->resolver->parseService('morning'));
        $this->assertEquals(SermonService::Evening, $this->resolver->parseService('evening'));
    }

    #[Test]
    public function it_returns_null_for_invalid_service(): void
    {
        $this->assertNull($this->resolver->parseService('invalid'));
        $this->assertNull($this->resolver->parseService(''));
        $this->assertNull($this->resolver->parseService(null));
    }

    #[Test]
    public function it_checks_if_log_matches_identity(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Morning,
        ]);

        $this->assertTrue($this->resolver->matchesService($log, '2024-05-12', SermonService::Morning));
        $this->assertFalse($this->resolver->matchesService($log, '2024-05-13', SermonService::Morning));
        $this->assertFalse($this->resolver->matchesService($log, '2024-05-12', SermonService::Evening));
    }

    #[Test]
    public function it_scopes_query_to_match_identity_via_columns(): void
    {
        MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Morning,
        ]);

        MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-05-13',
            'extracted_service' => SermonService::Morning,
        ]);

        $query = MediaProcessingLog::query();
        $this->resolver->scopeMatchesIdentity($query, '2024-05-12', SermonService::Morning);

        $results = $query->get();
        $this->assertCount(1, $results);
        $this->assertEquals('2024-05-12', $results->first()->extracted_date->toDateString());
    }
}
