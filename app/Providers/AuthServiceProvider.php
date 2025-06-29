<?php

namespace App\Providers;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

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
    Gate::before(function ($user, $ability) {
      if (str_ends_with($user->email, '@crockenhill.org')) {
        return true;
      }
    });

    Gate::define('see-member-content', function ($user) {
      $member_emails = [
        "",
        ""
      ];
      return in_array($user->email, $member_emails);
    });

    Gate::define('edit-sermons', function ($user) {
      $emails = [
        "",
        ""
      ];
      return in_array($user->email, $emails);
    });

    Gate::define('edit-songs', function ($user) {
      $emails = [
        "",
        ""
      ];
      return in_array($user->email, $emails);
    });

    Gate::define('edit-pages', function ($user) {
      $emails = [
        "",
        ""
      ];
      return in_array($user->email, $emails);
    });
  }
}
