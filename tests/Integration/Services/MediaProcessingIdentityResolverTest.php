<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private MediaProcessingIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MediaProcessingIdentityResolver;
    }

    #[Test]
    public function it_resolves_identity_from_columns_when_present(): void
    {
        $log = MediaProcessingLog::factory()->make([
            'extracted_date' => '2024-03-20',
            'extracted_service' => SermonService::Morning,
            'processing_metadata' => [
                'extracted_date' => '2024-01-01',
                'extracted_service' => 'evening',
            ],
        ]);

        $identity = $this->resolver->resolve($log);

        $this->assertNotNull($identity);
        $this->assertEquals('2024-03-20', $identity['date']);
        $this->assertEquals(SermonService::Morning, $identity['service']);
    }

    #[Test]
    public function it_returns_null_when_identity_cannot_be_resolved(): void
    {
        $log = MediaProcessingLog::factory()->make([
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => [],
        ]);

        $this->assertNull($this->resolver->resolve($log));
    }

    #[Test]
    public function it_parses_valid_dates(): void
    {
        $this->assertEquals('2024-03-20', $this->resolver->parseDate('2024-03-20'));
    }

    #[Test]
    public function it_returns_null_for_invalid_dates(): void
    {
        $this->assertNull($this->resolver->parseDate('invalid-date'));
        $this->assertNull($this->resolver->parseDate('2024-13-01'));
        $this->assertNull($this->resolver->parseDate(''));
        $this->assertNull($this->resolver->parseDate(null));
        $this->assertNull($this->resolver->parseDate(['array']));
    }

    #[Test]
    public function it_parses_valid_services(): void
    {
        $this->assertEquals(SermonService::Morning, $this->resolver->parseService('morning'));
        $this->assertEquals(SermonService::Evening, $this->resolver->parseService('evening'));
    }

    #[Test]
    public function it_returns_null_for_invalid_services(): void
    {
        $this->assertNull($this->resolver->parseService('invalid'));
        $this->assertNull($this->resolver->parseService(''));
        $this->assertNull($this->resolver->parseService(null));
    }

    #[Test]
    public function it_matches_service_correctly(): void
    {
        $log = MediaProcessingLog::factory()->make([
            'extracted_date' => '2024-03-20',
            'extracted_service' => SermonService::Morning,
        ]);

        $this->assertTrue($this->resolver->matchesService($log, '2024-03-20', SermonService::Morning));
        $this->assertFalse($this->resolver->matchesService($log, '2024-03-21', SermonService::Morning));
        $this->assertFalse($this->resolver->matchesService($log, '2024-03-20', SermonService::Evening));
    }

    #[Test]
    public function scope_matches_identity_finds_logs_by_columns(): void
    {
        $matchingLog = MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-03-20',
            'extracted_service' => SermonService::Morning,
        ]);

        MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-03-21',
            'extracted_service' => SermonService::Morning,
        ]);

        $results = MediaProcessingLog::query()
            ->tap(fn ($q) => $this->resolver->scopeMatchesIdentity($q, '2024-03-20', SermonService::Morning))
            ->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($matchingLog));
    }

    #[Test]
    public function scope_matches_identity_matches_only_by_columns(): void
    {
        $matchingLog = MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-03-20',
            'extracted_service' => SermonService::Morning,
        ]);

        $nonMatchingLog = MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-03-21',
            'extracted_service' => SermonService::Morning,
        ]);

        $results = MediaProcessingLog::query()
            ->tap(fn ($q) => $this->resolver->scopeMatchesIdentity($q, '2024-03-20', SermonService::Morning))
            ->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($matchingLog));
        $this->assertFalse($results->contains($nonMatchingLog));
    }
}
