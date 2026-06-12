<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminListAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_accessibility_features_when_paginator_is_provided(): void
    {
        /** @var User $admin */
        $admin = User::factory()->admin()->create();
        Sermon::factory()->count(5)->create(['date' => now()->subMonths(2)]);

        Livewire::actingAs($admin)
            ->test(ListSermons::class)
            ->assertSee('Skip to results')
            ->assertSee('Showing')
            ->assertSee('5')
            ->assertSee('sermons')
            ->assertSee('id="admin-list-results"', false)
            ->assertSee('tabindex="-1"', false);
    }

    #[Test]
    public function it_renders_paginated_accessibility_features(): void
    {
        /** @var User $admin */
        $admin = User::factory()->admin()->create();
        // Create 25 sermons to trigger pagination (default 20 per page)
        Sermon::factory()->count(25)->create(['date' => now()->subMonths(2)]);

        Livewire::actingAs($admin)
            ->test(ListSermons::class)
            ->assertSee('Showing')
            ->assertSee('1')
            ->assertSee('20')
            ->assertSee('25')
            ->assertSee('sermons')
            ->call('setPage', 2)
            ->assertSee('Showing')
            ->assertSee('21')
            ->assertSee('25')
            ->assertSee('sermons');
    }
}
