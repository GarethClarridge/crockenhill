<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_church_service_casts_service_to_sermon_service_enum(): void
    {
        $churchService = ChurchService::factory()->create([
            'service' => SermonService::MORNING->value,
        ]);

        $this->assertInstanceOf(SermonService::class, $churchService->service);
        $this->assertSame(SermonService::MORNING, $churchService->service);
    }

    #[Test]
    public function test_church_service_has_many_items(): void
    {
        $churchService = ChurchService::factory()->create();
        ChurchServiceItem::factory()->count(3)->create([
            'church_service_id' => $churchService->id,
        ]);

        $this->assertCount(3, $churchService->items);
    }

    #[Test]
    public function test_church_service_unique_date_service(): void
    {
        ChurchService::factory()->create([
            'date' => '2024-11-17',
            'service' => SermonService::MORNING->value,
        ]);

        $this->expectException(QueryException::class);

        ChurchService::factory()->create([
            'date' => '2024-11-17',
            'service' => SermonService::MORNING->value,
        ]);
    }

    #[Test]
    public function test_church_service_item_soft_deletes(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $item->delete();

        $this->assertNull(ChurchServiceItem::query()->find($item->id));
        $this->assertNotNull(ChurchServiceItem::withTrashed()->find($item->id));
    }

    #[Test]
    public function test_church_service_item_cascade_on_service_delete(): void
    {
        $churchService = ChurchService::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
        ]);

        $churchService->delete();

        $this->assertDatabaseMissing('church_services', ['id' => $churchService->id]);
        $this->assertDatabaseMissing('church_service_items', ['id' => $item->id]);
    }
}
