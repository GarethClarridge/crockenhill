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
        $request = new MediaStatusRequest;

        $validator = Validator::make([
            'include_logs' => true,
            'log_limit' => 50,
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    public function validation_rules_pass_with_empty_data(): void
    {
        $request = new MediaStatusRequest;

        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    public function validation_rules_reject_oversized_include_logs(): void
    {
        $request = new MediaStatusRequest;

        $validator = Validator::make([
            'include_logs' => str_repeat('1', 21),
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('include_logs', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rules_reject_oversized_log_limit(): void
    {
        $request = new MediaStatusRequest;

        $validator = Validator::make([
            'log_limit' => str_repeat('1', 21),
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('log_limit', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rules_reject_out_of_range_log_limit(): void
    {
        $request = new MediaStatusRequest;

        // Test below min
        $validator = Validator::make([
            'log_limit' => 0,
        ], $request->rules());
        $this->assertFalse($validator->passes());

        // Test above max
        $validator = Validator::make([
            'log_limit' => 101,
        ], $request->rules());
        $this->assertFalse($validator->passes());
    }
}
