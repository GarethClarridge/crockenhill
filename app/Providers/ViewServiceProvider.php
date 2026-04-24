<?php

declare(strict_types=1);

namespace App\Providers;

use App\View\Composers\BreadcrumbComposer;
use App\View\Composers\ChurchPageComposer;
use App\View\Composers\CommunityPageComposer;
use App\View\Composers\FooterComposer;
use App\View\Composers\HeaderComposer;
use App\View\Composers\HomePageComposer;
use App\View\Composers\LayoutPageComposer;
use App\View\Composers\PhotoSelectorComposer;
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
        View::composer('components.layout.header', HeaderComposer::class);
        View::composer('components.breadcrumbs', BreadcrumbComposer::class);
        View::composer('includes.footer', FooterComposer::class);
        View::composer('includes.photo-selector', PhotoSelectorComposer::class);
        View::composer('layouts/page', LayoutPageComposer::class);
        View::composer('full-width-pages.home', HomePageComposer::class);
        View::composer('full-width-pages.community', CommunityPageComposer::class);
        View::composer('full-width-pages.church', ChurchPageComposer::class);
    }
}
