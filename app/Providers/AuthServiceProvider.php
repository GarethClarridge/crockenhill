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
     * @param  \Illuminate\Contracts\Auth\Access\Gate  $gate
     * @return void
     */
    public function boot(GateContract $gate)
    {
        $this->registerPolicies($gate);

        $gate->before(function ($user, $ability) {
            if ($user->email === "admin@crockenhill.org") {
                return true;
            }
        });

        $gate->define('see-member-content', function ($user) {
            $member_emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $member_emails);
        });

        $gate->define('edit-sermons', function ($user) {
            $emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $emails);
        });

        $gate->define('edit-songs', function ($user) {
            $emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $emails);
        });

        $gate->define('edit-pages', function ($user) {
            $emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $emails);
        });

        $gate->define('edit-documents', function ($user) {
            $emails = [
                "garethclarridge@hotmail.co.uk",
                ""
            ];
            return in_array($user->email, $emails);
        });
    }
}