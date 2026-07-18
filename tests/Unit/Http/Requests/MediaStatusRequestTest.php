<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\MediaStatusRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaStatusRequestTest extends TestCase
{
    #[Test]
    public function validation_rules_pass_with_valid_data(): void
    {
        $data = [
            'include_logs' => true,
            'log_limit' => 50,
        ];

        $request = new MediaStatusRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    public function validation_rules_pass_with_empty_data(): void
    {
        $data = [];

        $request = new MediaStatusRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    public function validation_rules_reject_invalid_log_limit(): void
    {
        $request = new MediaStatusRequest;

        // Test log_limit too small
        $validator = Validator::make(['log_limit' => 0], $request->rules());
        $this->assertFalse($validator->passes());

        // Test log_limit too high
        $validator = Validator::make(['log_limit' => 101], $request->rules());
        $this->assertFalse($validator->passes());
    }

    #[Test]
    public function validation_rules_reject_oversized_log_limit_digit_length(): void
    {
        $request = new MediaStatusRequest;

        // Test log_limit with 4 digits (max allowed digits is 3)
        $validator = Validator::make(['log_limit' => '1000'], $request->rules());
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('log_limit', $validator->errors()->toArray());
    }
}
