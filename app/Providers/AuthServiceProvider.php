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
use Illuminate\Support\Str;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'Crockenhill\Model' => 'Crockenhill\Policies\ModelPolicy', // Original placeholder
        Meeting::class => MeetingPolicy::class,
        Sermon::class => SermonPolicy::class,
        Page::class => PagePolicy::class,
    ];

    /**
     * Register any application authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability) {
            if (method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()) {
                return false;
            }
            if (Str::endsWith($user->email, '@crockenhill.org')) {
                return true;
            }

            return null; // Explicitly return null if no decision is made by this `before` callback
        });

        Gate::define('see-member-content', function ($user) {
            $member_emails = [
                '', // Placeholder
                '',  // Placeholder
            ];
            // Ensure $user is an object and email property exists
            if (is_object($user) && property_exists($user, 'email')) {
                return in_array($user->email, $member_emails);
            }

            return false;
        });

        // Gate::define('edit-sermons', ...) // Removed, handled by SermonPolicy
        // Gate::define('edit-songs', ...) // Removed as unused and logic would be part of SermonPolicy if relevant
        // Gate::define('edit-pages', ...) // Removed, handled by PagePolicy
    }
}
