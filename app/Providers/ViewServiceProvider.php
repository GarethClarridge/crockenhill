<?php

declare(strict_types=1);

namespace App\Providers;

use App\View\Composers\ChurchPageComposer;
use App\View\Composers\CommunityPageComposer;
use App\View\Composers\HomePageComposer;
use App\View\Composers\PageShowComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('pages.show', PageShowComposer::class);
        View::composer('full-width-pages.home', HomePageComposer::class);
        View::composer('full-width-pages.community', CommunityPageComposer::class);
        View::composer('full-width-pages.church', ChurchPageComposer::class);
    }
}
