<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\ConfirmMediaSegmentRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfirmMediaSegmentRequestTest extends TestCase
{
    #[Test]
    public function validation_rules_pass_with_valid_data(): void
    {
        $request = new ConfirmMediaSegmentRequest;
        $rules = $request->rules();
        $rules['segment_id'] = array_filter($rules['segment_id'], fn ($rule) => ! is_string($rule) || ! str_contains($rule, 'exists'));

        $validator = Validator::make([
            'segment_id' => 12345,
        ], $rules);

        $this->assertTrue($validator->passes());
    }

    #[Test]
    public function validation_rules_reject_oversized_segment_id(): void
    {
        $request = new ConfirmMediaSegmentRequest;
        $rules = $request->rules();
        $rules['segment_id'] = array_filter($rules['segment_id'], fn ($rule) => ! is_string($rule) || ! str_contains($rule, 'exists'));

        $validator = Validator::make([
            'segment_id' => str_repeat('1', 21),
        ], $rules);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('segment_id', $validator->errors()->toArray());
    }
}
