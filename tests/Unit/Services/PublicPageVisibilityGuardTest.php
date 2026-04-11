<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\PageArea;
use App\Models\Page;
use App\Models\User;
use App\Services\PublicPageVisibilityGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicPageVisibilityGuardTest extends TestCase
{
    use RefreshDatabase;

    private PublicPageVisibilityGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(PublicPageVisibilityGuard::class);
    }

    #[Test]
    public function it_returns_null_when_page_is_null(): void
    {
        $result = $this->guard->enforce(null);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_a_public_page_accessed_by_a_guest(): void
    {
        $page = Page::factory()->create(['area' => PageArea::Christ->value, 'admin' => 'no']);

        $result = $this->guard->enforce($page);

        $this->assertNull($result);
    }

    #[Test]
    public function it_aborts_403_when_a_guest_accesses_an_admin_page(): void
    {
        $page = Page::factory()->admin()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->guard->enforce($page);
    }

    #[Test]
    public function it_aborts_403_when_a_non_admin_user_accesses_an_admin_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);

        $page = Page::factory()->admin()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->guard->enforce($page);
    }

    #[Test]
    public function it_returns_null_when_an_admin_user_accesses_an_admin_page(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $page = Page::factory()->admin()->create();

        $result = $this->guard->enforce($page);

        $this->assertNull($result);
    }

    #[Test]
    public function it_redirects_a_guest_to_login_for_a_members_area_page(): void
    {
        $page = Page::factory()->inArea(PageArea::Members)->create(['admin' => 'no']);

        $result = $this->guard->enforce($page);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsString('login', $result->getTargetUrl());
    }

    #[Test]
    public function it_returns_null_when_an_authenticated_user_accesses_a_members_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $page = Page::factory()->inArea(PageArea::Members)->create(['admin' => 'no']);

        $result = $this->guard->enforce($page);

        $this->assertNull($result);
    }
}
