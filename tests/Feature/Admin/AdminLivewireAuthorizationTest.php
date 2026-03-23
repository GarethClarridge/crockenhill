<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CalendarEvent;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminLivewireAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $regularUser;

    protected User $unverifiedAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'service-tracking.enabled' => true,
            'media-processing.section_publishing.enabled' => true,
        ]);

        $this->regularUser = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->unverifiedAdmin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);
    }

    #[Test]
    public function every_routed_admin_livewire_component_uses_auth_verified_and_admin_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(function (LaravelRoute $route): bool {
                $uses = $route->getAction('uses');

                return is_string($uses)
                    && str_starts_with($route->uri(), 'admin/')
                    && str_starts_with($uses, 'App\\Livewire\\Admin\\');
            })
            ->values();

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $label = $route->getName() ?? $route->uri();

            $this->assertContains('auth', $middleware, $label);
            $this->assertContains('verified', $middleware, $label);
            $this->assertContains('admin', $middleware, $label);
        }
    }

    #[Test]
    public function guests_are_redirected_from_routed_admin_livewire_components(): void
    {
        foreach ($this->adminLivewireRouteScenarios() as [$routeName, $parameters]) {
            $this->get(route($routeName, $parameters))
                ->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function non_admin_users_cannot_access_routed_admin_livewire_components(): void
    {
        $this->actingAs($this->regularUser);

        foreach ($this->adminLivewireRouteScenarios() as [$routeName, $parameters]) {
            $this->get(route($routeName, $parameters))
                ->assertForbidden();
        }
    }

    #[Test]
    public function unverified_admin_users_are_redirected_from_routed_admin_livewire_components(): void
    {
        $this->actingAs($this->unverifiedAdmin);

        foreach ($this->adminLivewireRouteScenarios() as [$routeName, $parameters]) {
            $this->get(route($routeName, $parameters))
                ->assertRedirect(route('verification.notice'));
        }
    }

    /**
     * @return list<array{0:string,1:array<string, mixed>}>
     */
    private function adminLivewireRouteScenarios(): array
    {
        $page = Page::factory()->create();
        $meeting = Meeting::factory()->create();
        $sermon = Sermon::factory()->create();
        $churchService = ChurchService::factory()->create();
        $song = Song::factory()->create();
        $processingLog = MediaProcessingLog::factory()->manualReviewRequired()->create();
        $preacher = Preacher::factory()->create();
        $calendarEvent = CalendarEvent::factory()->upcoming()->create();
        $user = User::factory()->create();

        return [
            ['admin.pages.index', []],
            ['admin.pages.create', []],
            ['admin.pages.edit', ['page' => $page]],
            ['admin.meetings.index', []],
            ['admin.meetings.create', []],
            ['admin.meetings.edit', ['meeting' => $meeting]],
            ['admin.sermons.index', []],
            ['admin.sermons.edit', ['sermon' => $sermon]],
            ['admin.services.index', []],
            ['admin.services.create', []],
            ['admin.services.upload', []],
            ['admin.services.inbound-emails', []],
            ['admin.services.submit-email', []],
            ['admin.services.review', []],
            ['admin.services.songs.index', []],
            ['admin.services.songs.show', ['song' => $song]],
            ['admin.services.section-publications', []],
            ['admin.services.processing.review.index', []],
            ['admin.services.processing.review', ['processingLog' => $processingLog]],
            ['admin.services.edit', ['churchService' => $churchService]],
            ['admin.services.show', ['churchService' => $churchService]],
            ['admin.preachers.index', []],
            ['admin.preachers.create', []],
            ['admin.preachers.edit', ['preacher' => $preacher]],
            ['admin.calendar-events.index', []],
            ['admin.calendar-events.edit', ['calendarEvent' => $calendarEvent]],
            ['admin.users.index', []],
            ['admin.users.create', []],
            ['admin.users.edit', ['user' => $user]],
        ];
    }
}
