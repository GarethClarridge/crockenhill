<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminListAccessibilityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_renders_accessibility_features_when_paginator_is_provided(): void
    {
        /** @var User $admin */
        $admin = User::factory()->admin()->create();
        Sermon::factory()->count(5)->create(['date' => now()->subMonths(2)]);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\Sermons\ListSermons::class)
            ->assertSeeHtml('Skip to results')
            ->assertSeeHtml('Showing <span class="font-medium text-gray-700">5</span> sermons')
            ->assertSeeHtml('id="admin-list-results"')
            ->assertSeeHtml('tabindex="-1"');
    }

    #[Test]
    public function it_renders_paginated_accessibility_features(): void
    {
        /** @var User $admin */
        $admin = User::factory()->admin()->create();
        // Create 25 sermons to trigger pagination (default 20 per page)
        Sermon::factory()->count(25)->create(['date' => now()->subMonths(2)]);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\Sermons\ListSermons::class)
            ->assertSeeHtml('Showing <span class="font-medium text-gray-700">1</span> to <span class="font-medium text-gray-700">20</span> of <span class="font-medium text-gray-700">25</span> sermons')
            ->call('setPage', 2)
            ->assertSeeHtml('Showing <span class="font-medium text-gray-700">21</span> to <span class="font-medium text-gray-700">25</span> of <span class="font-medium text-gray-700">25</span> sermons');
    }
}
