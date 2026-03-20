<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_are_restricted_to_admins(): void
    {
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'admin-only-page',
            'admin' => 'yes',
        ]);

        $this->get('/church/admin-only-page')->assertStatus(403);

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/church/admin-only-page')->assertStatus(403);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/church/admin-only-page')->assertStatus(200);
    }

    public function test_non_admin_pages_remain_publicly_accessible(): void
    {
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'public-page',
            'admin' => 'no',
        ]);

        $this->get('/church/public-page')->assertStatus(200);

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/church/public-page')->assertStatus(200);
    }

    public function test_members_area_pages_require_authentication(): void
    {
        Page::factory()->create([
            'area' => PageArea::MEMBERS,
            'slug' => 'members-only-page',
            'admin' => 'no',
        ]);

        $this->get('/members/members-only-page')->assertRedirect('/login');

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/members/members-only-page')->assertStatus(200);
    }

    public function test_admin_landing_pages_are_restricted_to_admins(): void
    {
        Page::unguarded(fn (): Page => Page::query()->updateOrCreate(
            ['slug' => 'members'],
            [
                'heading' => 'Members',
                'description' => 'Members landing page',
                'area' => PageArea::MEMBERS,
                'body' => 'Members landing page',
                'admin' => 'yes',
                'markdown' => '# Members',
                'navigation' => false,
            ]
        ));

        $this->get('/members')->assertStatus(403);

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/members')->assertStatus(403);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/members')->assertStatus(200);
    }

    public function test_landing_page_falls_back_to_body_content_when_markdown_is_missing(): void
    {
        $area = PageArea::SERMONS->value;

        Page::unguarded(fn (): Page => Page::query()->updateOrCreate(
            ['slug' => $area],
            [
                'heading' => 'Sermons',
                'description' => 'Sermons landing page',
                'area' => $area,
                'markdown' => null,
                'body' => '<h1>Legacy Body Content</h1>',
                'admin' => 'no',
                'navigation' => true,
            ]
        ));

        $response = $this->get('/'.$area);

        $response->assertStatus(200);
        $response->assertSee('Legacy Body Content', false);
    }

    public function test_non_members_area_without_landing_page_returns_404(): void
    {
        Page::query()->where('area', PageArea::SERMONS)->delete();

        $this->get('/sermons')->assertNotFound();
    }
}
