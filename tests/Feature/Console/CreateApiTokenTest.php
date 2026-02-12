<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateApiTokenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_api_token_for_user(): void
    {
        $user = User::factory()->create(['email' => 'token@test.com']);

        $this->artisan('api:create-token', ['email' => 'token@test.com'])
            ->assertExitCode(0)
            ->expectsOutputToContain('API Token created successfully')
            ->expectsOutputToContain('Token:');

        $this->assertCount(1, $user->tokens);
    }

    #[Test]
    public function it_fails_to_create_token_for_non_existent_user(): void
    {
        $this->artisan('api:create-token', ['email' => 'missing@test.com'])
            ->assertExitCode(1)
            ->expectsOutputToContain('User with email missing@test.com not found');
    }

    #[Test]
    public function it_respects_custom_token_name_and_abilities(): void
    {
        $user = User::factory()->create(['email' => 'custom@test.com']);

        $this->artisan('api:create-token', [
            'email' => 'custom@test.com',
            'name' => 'Custom Name',
            '--abilities' => ['read', 'write']
        ])->assertExitCode(0);

        $token = $user->tokens->first();
        $this->assertEquals('Custom Name', $token->name);
        $this->assertEquals(['read', 'write'], $token->abilities);
    }
}
