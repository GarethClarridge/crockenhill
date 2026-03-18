<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Tests\TestCase;

final class AdminUserScenario
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes = []): User
    {
        return User::factory()
            ->crockenhillAdmin()
            ->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function actingAs(TestCase $testCase, array $attributes = []): User
    {
        $user = self::create($attributes);

        $testCase->actingAs($user);

        return $user;
    }
}
