<?php

declare(strict_types=1);

namespace App\Providers;

use App\View\Composers\ChurchPageComposer;
use App\View\Composers\CommunityPageComposer;
use App\View\Composers\HomePageComposer;
use App\View\Composers\PageShowComposer;
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
        View::composer('includes.photo-selector', PhotoSelectorComposer::class);
        View::composer('pages.show', PageShowComposer::class);
        // Phase 2 migration: pages/christ/free-bible.blade.php is the last override that @extends('layouts.page').
        // Remove this binding once it migrates to @extends('layouts.main') + x-page.shell.
        View::composer('layouts/page', PageShowComposer::class);
        View::composer('full-width-pages.home', HomePageComposer::class);
        View::composer('full-width-pages.community', CommunityPageComposer::class);
        View::composer('full-width-pages.church', ChurchPageComposer::class);
    }
}
