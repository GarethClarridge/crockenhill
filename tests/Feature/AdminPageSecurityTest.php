<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AdminPageSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_view_admin_page()
    {
        // Create an admin page
        $page = Page::factory()->admin()->create([
            'slug' => 'secret-admin-page',
            'area' => 'church',
        ]);

        $response = $this->get('/church/secret-admin-page');

        $response->assertStatus(403);
    }

    #[Test]
    public function non_admin_user_cannot_view_admin_page()
    {
        // Create an admin page
        $page = Page::factory()->admin()->create([
            'slug' => 'secret-admin-page',
            'area' => 'church',
        ]);

        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/church/secret-admin-page');

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_user_can_view_admin_page()
    {
        // Create an admin page
        $page = Page::factory()->admin()->create([
            'slug' => 'secret-admin-page',
            'area' => 'church',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get('/church/secret-admin-page');

        $response->assertStatus(200);
    }
}
