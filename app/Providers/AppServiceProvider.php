<?php

namespace App\Providers;

use App\Contracts\TranscriptionServiceInterface;
use App\HealthChecks\OpenAIHealthCheck;
use App\HealthChecks\SermonProcessingQueueHealthCheck;
use App\HealthChecks\StorageHealthCheck;
use App\Models\Page;
use App\Services\AudioTranscriptionService;
use App\Services\MockTranscriptionService;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
// use Laravel\Dusk\DuskServiceProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Register custom health checks for Laravel 12
        Event::listen(DiagnosingHealth::class, function () {
            // Run OpenAI health check
            $openAICheck = new OpenAIHealthCheck;
            $openAIResult = $openAICheck->run();
            if ($openAIResult['status'] === 'error') {
                throw new \Exception('OpenAI API health check failed: '.$openAIResult['message']);
            }

            // Run sermon processing queue health check
            $queueCheck = new SermonProcessingQueueHealthCheck;
            $queueResult = $queueCheck->run();
            if ($queueResult['status'] === 'error') {
                throw new \Exception('Sermon processing queue health check failed: '.$queueResult['message']);
            }

            // Run storage health check
            $storageCheck = new StorageHealthCheck;
            $storageResult = $storageCheck->run();
            if ($storageResult['status'] === 'error') {
                throw new \Exception('Storage health check failed: '.$storageResult['message']);
            }
        });

        // Share user with all views
        if (Auth::user()) {
            $user = Auth::user();
        } else {
            $user = null;
        }

        view()->share('user', $user);

        // Share $pages with the header component
        View::composer('components.layout.header', function ($view) {
            $view->with('pages', Page::isNavigation()->get());
        });
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
            'App\Services\Registrar'
        );

        $this->app->bind('path.public', function () {
            return base_path().'/public';
        });

        // Bind transcription service based on environment configuration
        $this->app->bind(TranscriptionServiceInterface::class, function ($app) {
            $serviceType = config('sermon-processing.transcription.service_type', 'openai');

            switch ($serviceType) {
                case 'mock':
                    return $app->make(MockTranscriptionService::class);
                case 'openai':
                default:
                    return $app->make(AudioTranscriptionService::class);
            }
        });

        // if ($this->app->environment('local', 'testing')) {
        //   $this->app->register(DuskServiceProvider::class);
        // }
    }
}
