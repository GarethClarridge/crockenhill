<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BladeShellRenderingTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // x-auth.shell
    // -------------------------------------------------------------------------

    #[Test]
    public function auth_shell_pushes_heading_as_page_title(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('<title>Log in | Crockenhill Baptist Church</title>', false);
    }

    #[Test]
    public function auth_shell_pushes_description_as_meta_description(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('<meta name="description" content="Log in to your account">', false);
    }

    #[Test]
    public function auth_shell_forgot_password_title_is_correct(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertOk();
        $response->assertSee('<title>Forgot password | Crockenhill Baptist Church</title>', false);
    }

    #[Test]
    public function auth_shell_forgot_password_description_is_correct(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertOk();
        $response->assertSee('<meta name="description" content="Reset your password">', false);
    }

    // -------------------------------------------------------------------------
    // x-page.shell (via pages/show.blade.php)
    // -------------------------------------------------------------------------

    #[Test]
    public function page_shell_pushes_heading_as_page_title(): void
    {
        Page::factory()->create([
            'slug' => 'shell-test-page',
            'area' => 'church',
            'heading' => 'Shell Test Page',
            'body' => 'Body content',
        ]);

        $response = $this->get('/church/shell-test-page');

        $response->assertOk();
        $response->assertSee('<title>Shell Test Page | Crockenhill Baptist Church</title>', false);
    }

    #[Test]
    public function page_shell_pushes_description_as_meta_description(): void
    {
        Page::factory()->create([
            'slug' => 'shell-desc-page',
            'area' => 'church',
            'heading' => 'Shell Desc Page',
            'description' => 'A custom page description for SEO.',
            'body' => 'Body content',
        ]);

        $response = $this->get('/church/shell-desc-page');

        $response->assertOk();
        $response->assertSee('<meta name="description" content="A custom page description for SEO.">', false);
    }

    #[Test]
    public function page_shell_emits_canonical_url(): void
    {
        Page::factory()->create([
            'slug' => 'shell-canonical-page',
            'area' => 'church',
            'heading' => 'Shell Canonical Page',
            'body' => 'Body content',
        ]);

        $response = $this->get('/church/shell-canonical-page');

        $response->assertOk();
        $response->assertSee('<link rel="canonical"', false);
    }

    // -------------------------------------------------------------------------
    // x-admin.shell
    // -------------------------------------------------------------------------

    #[Test]
    public function admin_shell_pushes_heading_as_page_title(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        $response = $this->actingAs($admin)->get('/admin/meetings');

        $response->assertOk();
        $response->assertSee('<title>Meetings | Crockenhill Baptist Church</title>', false);
    }

    #[Test]
    public function admin_shell_calendar_patterns_title_is_correct(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        $response = $this->actingAs($admin)->get('/admin/calendar/patterns');

        $response->assertOk();
        $response->assertSee('<title>Calendar patterns | Crockenhill Baptist Church</title>', false);
    }

    #[Test]
    public function layout_preloads_the_vite_built_pattern_asset(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee(
            '<link rel="preload" as="image" href="'.Vite::asset('resources/svg/pattern.svg').'">',
            false
        );
        // The unhashed public copy is never referenced by the built CSS; preloading
        // it downloads the pattern twice and logs an unused-preload warning.
        $response->assertDontSee('href="/svg/pattern.svg"', false);
    }

    #[Test]
    public function shells_render_main_content_with_tabindex(): void
    {
        // Auth Shell
        $this->get('/login')->assertSee('id="main-content"', false)->assertSee('tabindex="-1"', false);

        // Page Shell
        Page::factory()->create(['slug' => 'tabindex-test', 'area' => 'church']);
        $this->get('/church/tabindex-test')->assertSee('id="main-content"', false)->assertSee('tabindex="-1"', false);

        // Admin Shell
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($admin)->get('/admin/meetings')->assertSee('id="main-content"', false)->assertSee('tabindex="-1"', false);
    }
}
