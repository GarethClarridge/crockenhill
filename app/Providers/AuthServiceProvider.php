<?php

namespace App\Providers;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use App\Policies\MeetingPolicy;
use App\Policies\PagePolicy;
use App\Policies\SermonPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Meeting::class => MeetingPolicy::class,
        Sermon::class => SermonPolicy::class,
        Page::class => PagePolicy::class,
    ];

    /**
     * Register any application authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('manage-sermons', function ($user) {
            return $user->is_admin && $user->hasVerifiedEmail();
        });

        Gate::define('manage-meetings', function ($user) {
            return $user->is_admin && $user->hasVerifiedEmail();
        });

        Gate::define('manage-pages', function ($user) {
            return $user->is_admin && $user->hasVerifiedEmail();
        });
    }
}
