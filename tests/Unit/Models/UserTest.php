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
        $user->email = '  JOHN@Example.Com  ';

        $this->assertEquals('john@example.com', $user->email);
    }

    #[Test]
    public function it_validates_basic_rules(): void
    {
        $rules = $this->filterRules(User::validationRules());

        $validator = Validator::make([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ], $rules);

        $this->assertFalse($validator->fails(), 'Validation should pass with valid data');

        $validator = Validator::make([
            'name' => '',
            'email' => 'not-an-email',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    /**
     * @param array<string, array<int, mixed>> $rules
     * @return array<string, array<int, mixed>>
     */
    private function filterRules(array $rules): array
    {
        return array_map(function ($fieldRules) {
            return array_filter($fieldRules, function ($rule) {
                $ruleString = (string) $rule;

                return ! str_starts_with($ruleString, 'unique') && ! str_starts_with($ruleString, 'exists');
            });
        }, $rules);
    }
}
