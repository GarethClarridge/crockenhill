<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordVisibilityToggleTest extends TestCase
{
    #[Test]
    public function login_page_has_password_visibility_toggle(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
        $response->assertSee('showPassword');
        $response->assertSee(':type="showPassword ? \'text\' : \'password\'"', false);
        $response->assertSee('Show password');
        $response->assertSee('Hide password');
        $response->assertSee('motion-reduce:transition-none');
        $response->assertSee('svg');
    }

    #[Test]
    public function register_page_has_password_visibility_toggles(): void
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
        // Should have two toggles (password and password_confirmation)
        $this->assertEquals(2, substr_count($response->getContent(), 'showPassword = !showPassword'));
    }

    #[Test]
    public function reset_password_page_has_password_visibility_toggles(): void
    {
        // We need a token for the reset password route
        $response = $this->get(route('password.reset', ['token' => 'fake-token']));

        $response->assertStatus(200);
        // Should have two toggles
        $this->assertEquals(2, substr_count($response->getContent(), 'showPassword = !showPassword'));
    }
}
