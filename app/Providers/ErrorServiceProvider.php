<?php namespace Crockenhill\Providers;

use Illuminate\Support\ServiceProvider;

class ErrorServiceProvider extends ServiceProvider {

	/**
	 * Bootstrap the application services.
	 *
	 * @return void
	 */
	public function boot(Handler $handler, Log $log)
	{
		$handler->missing(function(Exception $exception)
		{
		    return Response::view('errors.missing', array(), 404);
		});
	}

	/**
	 * Register the application services.
	 *
	 * @return void
	 */
	public function register()
	{
		//
	}

}
