<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CalendarEvent;
use App\Models\ChurchService;
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
use ReflectionMethod;
use Tests\TestCase;

class AdminLivewireAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $regularUser;

    protected User $unverifiedAdmin;

    protected User $verifiedAdmin;

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

        $this->verifiedAdmin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function every_routed_admin_livewire_component_uses_auth_verified_and_admin_middleware(): void
    {
        $routes = $this->routedAdminLivewireRoutes();

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
    public function routed_admin_livewire_mount_methods_rely_on_route_middleware(): void
    {
        $componentClasses = $this->routedAdminLivewireComponentClasses();

        $this->assertNotEmpty($componentClasses);

        foreach ($componentClasses as $componentClass) {
            if (! method_exists($componentClass, 'mount')) {
                continue;
            }

            $method = new ReflectionMethod($componentClass, 'mount');
            $fileName = $method->getFileName();
            $this->assertIsString($fileName);

            $sourceLines = file($fileName);
            $this->assertIsArray($sourceLines);

            $methodSource = implode('', array_slice(
                $sourceLines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            $this->assertStringNotContainsString(
                'authorizeAdmin(',
                $methodSource,
                "{$componentClass}::mount() should rely on the admin route middleware."
            );
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

    #[Test]
    public function verified_admin_users_can_render_routed_admin_livewire_components(): void
    {
        $this->actingAs($this->verifiedAdmin);

        foreach ($this->adminLivewireRouteScenarios() as [$routeName, $parameters]) {
            $response = $this->get(route($routeName, $parameters));

            $this->assertSame(
                200,
                $response->getStatusCode(),
                sprintf(
                    '[%s] expected to render for a verified admin but returned %d: %s',
                    $routeName,
                    $response->getStatusCode(),
                    $response->exception?->getMessage() ?? 'no exception',
                ),
            );
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
            ['admin.services.inbox', []],
            ['admin.services.add', []],
            ['admin.services.create', []],
            ['admin.services.upload-recording', []],
            ['admin.services.songs.index', []],
            ['admin.services.songs.show', ['song' => $song]],
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

    /**
     * @return list<LaravelRoute>
     */
    private function routedAdminLivewireRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(function (LaravelRoute $route): bool {
                return str_starts_with($route->uri(), 'admin/')
                    && $this->routedAdminLivewireComponentClass($route) !== null;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<class-string>
     */
    private function routedAdminLivewireComponentClasses(): array
    {
        $classes = [];

        foreach ($this->routedAdminLivewireRoutes() as $route) {
            $componentClass = $this->routedAdminLivewireComponentClass($route);

            if ($componentClass !== null) {
                $classes[] = $componentClass;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return class-string|null
     */
    private function routedAdminLivewireComponentClass(LaravelRoute $route): ?string
    {
        $uses = $route->getAction('uses');
        if (! is_string($uses)) {
            return null;
        }

        $componentClass = explode('@', $uses, 2)[0];
        if (! str_starts_with($componentClass, 'App\\Livewire\\Admin\\')) {
            return null;
        }

        if (! class_exists($componentClass)) {
            return null;
        }

        return $componentClass;
    }
}
