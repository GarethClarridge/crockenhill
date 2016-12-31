<?php namespace Crockenhill\Providers;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        'Crockenhill\Model' => 'Crockenhill\Policies\ModelPolicy',
    ];

    /**
     * Register any application authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        \Gate::before(function ($user, $ability) {
            if ($user->email === "admin@crockenhill.org") {
                return true;
            }
        });

        \Gate::define('see-member-content', function ($user) {
            $member_emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $member_emails);
        });

        \Gate::define('edit-sermons', function ($user) {
            $emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $emails);
        });

        \Gate::define('edit-songs', function ($user) {
            $emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $emails);
        });

        \Gate::define('edit-pages', function ($user) {
            $emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $emails);
        });

        \Gate::define('edit-documents', function ($user) {
            $emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $emails);
        });
    }
}
