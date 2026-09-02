<?php

declare(strict_types=1);

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class FixtureAuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        'Fixture\Model' => 'Fixture\Policy',
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
