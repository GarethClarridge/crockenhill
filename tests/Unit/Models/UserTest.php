<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
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
        $user->email = '  JOE@Example.COM  ';

        $this->assertEquals('joe@example.com', $user->email);
    }

    #[Test]
    public function it_can_access_admin_only_if_admin_and_verified(): void
    {
        // Not admin, not verified
        $user = User::factory()->make([
            'is_admin' => false,
            'email_verified_at' => null,
        ]);
        $this->assertFalse($user->canAccessAdmin());

        // Admin, not verified
        $user->is_admin = true;
        $this->assertFalse($user->canAccessAdmin());

        // Not admin, verified
        $user->is_admin = false;
        $user->email_verified_at = now();
        $this->assertFalse($user->canAccessAdmin());

        // Admin and verified
        $user->is_admin = true;
        $this->assertTrue($user->canAccessAdmin());
    }

    #[Test]
    public function it_validates_required_name(): void
    {
        $rules = User::validationRules();
        $filteredRules = $this->filterDatabaseRules($rules['name']);

        $validator = Validator::make(['name' => ''], ['name' => $filteredRules]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_email_format(): void
    {
        $rules = User::validationRules();
        $filteredRules = $this->filterDatabaseRules($rules['email']);

        $validator = Validator::make(['email' => 'not-an-email'], ['email' => $filteredRules]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());

        $validator = Validator::make(['email' => 'test@example.com'], ['email' => $filteredRules]);
        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_validates_email_lowercase(): void
    {
        $rules = User::validationRules();
        $filteredRules = $this->filterDatabaseRules($rules['email']);

        $validator = Validator::make(['email' => 'UPPER@example.com'], ['email' => $filteredRules]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    #[Test]
    public function unique_email_rule_ignores_current_user_id(): void
    {
        $user = User::factory()->make(['id' => 123]);
        $rules = User::validationRules($user);

        $emailRules = $rules['email'];
        $uniqueRuleFound = false;

        foreach ($emailRules as $rule) {
            $ruleString = (string) $rule;
            if (str_starts_with($ruleString, 'unique:users,email')) {
                $uniqueRuleFound = true;
                $this->assertStringContainsString('"123"', $ruleString);
                $this->assertStringContainsString('id', $ruleString);
            }
        }

        $this->assertTrue($uniqueRuleFound, 'Unique rule for email was not found.');
    }

    private function filterDatabaseRules(array $rules): array
    {
        return array_filter($rules, function ($rule) {
            $ruleString = (string) $rule;

            return ! str_starts_with($ruleString, 'exists:') && ! str_starts_with($ruleString, 'unique:');
        });
    }
}
