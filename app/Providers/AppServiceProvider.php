<?php namespace Crockenhill\Providers;

use Illuminate\Support\ServiceProvider;
// use Laravel\Dusk\DuskServiceProvider;

class AppServiceProvider extends ServiceProvider {

	/**
	 * Bootstrap any application services.
	 *
	 * @return void
	 */
	public function boot()
	{

		// Share user with all views
    if (\Auth::user()) {
      $user = \Auth::user();
    } else {
      $user = null;
    }

    view()->share('user', $user);
	}

	/**
	 * Register any application services.
	 *
	 * This service provider is a great spot to register your various container
	 * bindings with the application. As you can see, we are registering our
	 * "Registrar" implementation here. You can add your own bindings too!
	 *
	 * @return void
	 */
	public function register()
	{
		$this->app->bind(
			'Illuminate\Contracts\Auth\Registrar',
			'Crockenhill\Services\Registrar'
		);

		$this->app->bind('path.public', function() {
			return base_path() . '/public';
		});

		// if ($this->app->environment('local', 'testing')) {
    //   $this->app->register(DuskServiceProvider::class);
    // }
	}
}
