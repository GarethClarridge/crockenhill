<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Services\ChurchService\ChurchServiceIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ChurchServiceIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_unknown_identity_resolves_to_nothing(): void
    {
        $this->assertNull($this->resolve('2026-07-29', SermonService::Morning));
    }

    #[Test]
    public function a_single_row_is_reused(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-07-29',
            'service' => SermonService::Morning,
        ]);

        $this->assertTrue($service->is($this->resolve('2026-07-29', SermonService::Morning)));
    }

    /**
     * `church_services_date_service_unique` should make this unreachable, so
     * reaching it means the constraint is missing or has been bypassed. Taking
     * the first row would attach evidence to an arbitrary one of the duplicates.
     */
    #[Test]
    public function duplicate_rows_are_a_hard_failure_rather_than_a_silent_first_match(): void
    {
        ChurchService::factory()->create(['date' => '2026-07-29', 'service' => SermonService::Morning]);
        DB::statement('ALTER TABLE church_services DROP INDEX church_services_date_service_unique');

        try {
            ChurchService::factory()->create(['date' => '2026-07-29', 'service' => SermonService::Morning]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('resolves to 2 rows');

            $this->resolve('2026-07-29', SermonService::Morning);
        } finally {
            ChurchService::query()->where('date', '2026-07-29')->delete();
            DB::statement('ALTER TABLE church_services ADD UNIQUE church_services_date_service_unique (`date`, `service`)');
        }
    }

    private function resolve(string $date, SermonService $service): ?ChurchService
    {
        return app(ChurchServiceIdentityResolver::class)->resolve($date, $service);
    }
}
