<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Crockenhill\User; // Reverted to Crockenhill namespace
use Database\Factories\UserFactory;
use Laravel\Sanctum\Sanctum;

class AuthUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function unauthenticated_user_cannot_access_user_endpoint()
    {
        // Make a GET request to /api/user without any authentication token
        // Ensure the request explicitly asks for a JSON response
        $response = $this->getJson('/api/user');

        // Assert that the response status is 401 (Unauthorized)
        $response->assertStatus(401);

        // Assert JSON response contains an error message like "Unauthenticated."
        $response->assertJson(['message' => 'Unauthenticated.']); // Default Sanctum message
    }

    /**
     * @test
     */
    public function authenticated_user_can_access_user_endpoint()
    {
        // Create a user using UserFactory
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'testuser@example.com',
        ]);

        // Authenticate this user for API requests using Laravel Sanctum
        Sanctum::actingAs($user);

        // Make a GET request to /api/user
        $response = $this->getJson('/api/user');

        // Assert that the response status is 200 (OK)
        $response->assertStatus(200);

        // Assert that the JSON response contains the user's data
        $response->assertJson([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            // Add other fields that are expected to be returned by the API resource for the user
            // e.g., 'email_verified_at', 'created_at', 'updated_at' if they are part of the resource.
            // For now, let's check only the main ones.
        ]);

        // More specific checks using assertJsonPath:
        $response->assertJsonPath('id', $user->id);
        $response->assertJsonPath('name', 'Test User');
        $response->assertJsonPath('email', 'testuser@example.com');

        // Ensure it does not return sensitive information like password
        $response->assertJsonMissingPath('password');
    }
}
