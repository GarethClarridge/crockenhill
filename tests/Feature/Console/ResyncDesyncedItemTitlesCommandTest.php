<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResyncDesyncedItemTitlesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_without_writing(): void
    {
        $item = $this->desyncedItem();

        $this->artisan('service:resync-item-titles')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('No changes written')
            ->assertSuccessful();

        $this->assertSame('Great Is The Lord', $item->refresh()->title);
    }

    public function test_apply_restores_title_from_source_title(): void
    {
        $item = $this->desyncedItem();

        $this->artisan('service:resync-item-titles --apply')
            ->expectsOutputToContain('APPLYING')
            ->assertSuccessful();

        $this->assertSame('God is for us', $item->refresh()->title);
    }

    public function test_in_sync_items_are_left_untouched(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'title' => 'Lord Jesus build your church today',
            'source_title' => 'Lord Jesus build your church today',
        ]);

        $this->artisan('service:resync-item-titles --apply')
            ->expectsOutputToContain('No desynced song item titles found')
            ->assertSuccessful();

        $this->assertSame('Lord Jesus build your church today', $item->refresh()->title);
    }

    public function test_service_filter_limits_scope(): void
    {
        $target = $this->desyncedItem();
        $other = $this->desyncedItem();

        $this->artisan('service:resync-item-titles --apply --service='.$target->church_service_id)
            ->assertSuccessful();

        $this->assertSame('God is for us', $target->refresh()->title);
        $this->assertSame('Great Is The Lord', $other->refresh()->title);
    }

    private function desyncedItem(): ChurchServiceItem
    {
        $service = ChurchService::factory()->create();

        return ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'type' => 'songs',
            'title' => 'Great Is The Lord',
            'source_title' => 'God is for us',
        ]);
    }
}
