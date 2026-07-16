<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\ChurchServiceDomainServiceProvider;
use App\Providers\HealthCheckServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\MediaProcessingServiceProvider;
use App\Providers\ModelObserverServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\UrlServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    ChurchServiceDomainServiceProvider::class,
    UrlServiceProvider::class,
    AuthServiceProvider::class,
    ModelObserverServiceProvider::class,
    RateLimitServiceProvider::class,
    MediaProcessingServiceProvider::class,
    HorizonServiceProvider::class,
    HealthCheckServiceProvider::class,
];
