<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
    #[Test]
    public function it_trims_name_attribute(): void
    {
        $user = new User();
        $user->name = '  John Doe  ';

        $this->assertEquals('John Doe', $user->name);
    }

    #[Test]
    public function it_trims_and_lowercases_email_attribute(): void
    {
        $user = new User();
        $user->email = '  John.Doe@Example.com  ';

        $this->assertEquals('john.doe@example.com', $user->email);
    }

    #[Test]
    public function it_returns_expected_validation_rules(): void
    {
        $rules = User::validationRules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayHasKey('email', $rules);

        $this->assertContains('required', $rules['name']);
        $this->assertContains('string', $rules['name']);
        $this->assertContains('max:255', $rules['name']);

        $this->assertContains('required', $rules['email']);
        $this->assertContains('email', $rules['email']);
        $this->assertContains('lowercase', $rules['email']);
        $this->assertContains('max:255', $rules['email']);

        $uniqueRule = collect($rules['email'])->first(fn ($rule) => $rule instanceof Unique);
        $this->assertNotNull($uniqueRule);
    }

    #[Test]
    public function validation_rules_ignore_user_id_when_provided(): void
    {
        $user = new User();
        $user->id = 123;

        $rules = User::validationRules($user);

        /** @var Unique|null $uniqueRule */
        $uniqueRule = collect($rules['email'])->first(fn ($rule) => $rule instanceof Unique);

        $this->assertNotNull($uniqueRule);
        $this->assertStringContainsString('"123",id', (string) $uniqueRule);
    }

    #[Test]
    public function it_validates_basic_user_data_format(): void
    {
        $rules = User::validationRules();

        // Filter out unique rule to avoid DB dependency in unit test
        $rules['email'] = collect($rules['email'])
            ->filter(fn ($rule) => ! ($rule instanceof Unique))
            ->all();

        $validData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ];

        $validator = Validator::make($validData, $rules);
        $this->assertFalse($validator->fails(), $validator->errors()->first());

        $invalidData = [
            'name' => '',
            'email' => 'not-an-email',
        ];

        $validator = Validator::make($invalidData, $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }
}
