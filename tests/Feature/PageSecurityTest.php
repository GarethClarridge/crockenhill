<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageSecurityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function guest_cannot_access_admin_page(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'restricted-page',
            'admin' => 'yes',
        ]);

        $response = $this->get('/church/restricted-page');

        $response->assertStatus(404);
    }

    #[Test]
    public function non_admin_user_cannot_access_admin_page(): void
    {
        /** @var User $user */
        $user = User::factory()->create(['is_admin' => false]);
        $page = Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'restricted-page',
            'admin' => 'yes',
        ]);

        $response = $this->actingAs($user)->get('/church/restricted-page');

        $response->assertStatus(404);
    }

    #[Test]
    public function admin_user_can_access_admin_page(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        /** @var Page $page */
        $page = Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'restricted-page',
            'admin' => 'yes',
        ]);

        $response = $this->actingAs($admin)->get('/church/restricted-page');

        $response->assertStatus(200);
        $response->assertSee($page->heading);
    }

    #[Test]
    public function everyone_can_access_non_admin_page(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'public-page',
            'admin' => 'no',
        ]);

        // Guest
        $this->get('/church/public-page')->assertStatus(200);

        // Non-admin user
        /** @var User $user */
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/church/public-page')->assertStatus(200);

        // Admin user
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/church/public-page')->assertStatus(200);
    }
}
