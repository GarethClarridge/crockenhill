<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\ChurchServiceDomainServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\MediaProcessingServiceProvider;
use App\Providers\ModelObserverServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\UrlServiceProvider;
use App\Providers\ViewServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    ChurchServiceDomainServiceProvider::class,
    UrlServiceProvider::class,
    AuthServiceProvider::class,
    ViewServiceProvider::class,
    ModelObserverServiceProvider::class,
    RateLimitServiceProvider::class,
    MediaProcessingServiceProvider::class,
    HorizonServiceProvider::class,
];
