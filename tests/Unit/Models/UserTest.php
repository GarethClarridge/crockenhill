<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_trims_name_attribute(): void
    {
        /** @var User $user */
        $user = User::factory()->make();
        $user->name = '  John Doe  ';

        $this->assertEquals('John Doe', $user->name);
    }

    #[Test]
    public function it_trims_and_lowercases_email_attribute(): void
    {
        /** @var User $user */
        $user = User::factory()->make();
        $user->email = '  TEST@EXAMPLE.COM  ';

        $this->assertEquals('test@example.com', $user->email);
    }

    #[Test]
    public function it_returns_expected_validation_rules(): void
    {
        $rules = User::validationRules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertContains('required', $rules['name']);
        $this->assertContains('string', $rules['name']);
        $this->assertContains('max:255', $rules['name']);

        $this->assertArrayHasKey('email', $rules);
        $this->assertContains('required', $rules['email']);
        $this->assertContains('email', $rules['email']);
        $this->assertContains('lowercase', $rules['email']);
        $this->assertContains('max:255', $rules['email']);
    }

    #[Test]
    public function it_handles_unique_email_validation(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $rules = User::validationRules();

        $validator = Validator::make(
            ['email' => 'existing@example.com', 'name' => 'Test'],
            $rules
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    #[Test]
    public function it_ignores_own_id_in_unique_email_validation(): void
    {
        /** @var User $existingUser */
        $existingUser = User::factory()->create(['email' => 'existing@example.com']);
        $rules = User::validationRules($existingUser);

        $validator = Validator::make(
            ['email' => 'existing@example.com', 'name' => 'Test'],
            $rules
        );

        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }
}
