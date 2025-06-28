<?php

namespace App\Providers;

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
    'App\Model' => 'App\Policies\ModelPolicy',
  ];

  /**
   * Register any application authentication / authorization services.
   *
   * @return void
   */
  public function boot()
  {
    // This will grant all abilities to users with 'is_admin' = true
    \Gate::before(function ($user, $ability) {
        if (isset($user->is_admin) && $user->is_admin) {
            return true;
        }
        // For specific email override, keep if necessary, but is_admin is more standard
        // if ($user->email === "admin@crockenhill.org") {
        //     return true;
        // }
        return null; // Important: return null if not granting/denying here
    });

    // Gate for seeing member content
    \Gate::define('see-member-content', function ($user) {
      // User must be logged in and have the is_member flag set to true.
      return $user && $user->is_member;
    });

    // Define other gates based on is_admin or specific roles/permissions
    // For now, let's assume edit-sermons and edit-pages depend on the is_admin flag
    // which is handled by Gate::before. If more granular control is needed,
    // these can be defined explicitly checking $user->is_admin.

    // If Gate::before handles all admin privileges, explicit definitions might only be needed
    // for non-admin permissions or if specific abilities aren't covered by 'is_admin'.
    // For simplicity and to align with tests using User::factory()->admin(),
    // relying on Gate::before for is_admin is sufficient for these.
    // Explicitly defining them to check is_admin is also fine:

    \Gate::define('edit-sermons', function ($user) {
      return isset($user->is_admin) && $user->is_admin;
    });

    \Gate::define('edit-songs', function ($user) {
        // Assuming this also means admin, or define specific logic
      return isset($user->is_admin) && $user->is_admin;
    });

    \Gate::define('edit-pages', function ($user) {
      return isset($user->is_admin) && $user->is_admin;
    });
  }
}
