<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\SermonIndexRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIndexRequestTest extends TestCase
{
    #[Test]
    public function authorize_returns_true(): void
    {
        $request = new SermonIndexRequest;

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function validation_rules_pass_with_valid_data(): void
    {
        $data = [
            'search' => 'Grace',
            'service' => 'morning',
            'preacher' => 'John Doe',
            'series' => 'Romans',
            'sort' => 'date',
            'order' => 'desc',
            'per_page' => 15,
            'with_thumbnail' => true,
        ];

        $request = new SermonIndexRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    public function validation_rules_pass_with_empty_data(): void
    {
        $data = [];

        $request = new SermonIndexRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    public function validation_rules_reject_invalid_sort_field(): void
    {
        $data = [
            'sort' => 'invalid_field',
        ];

        $request = new SermonIndexRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('sort', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rules_reject_invalid_order(): void
    {
        $data = [
            'order' => 'sideways',
        ];

        $request = new SermonIndexRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('order', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rules_reject_out_of_range_per_page(): void
    {
        $request = new SermonIndexRequest;

        // Test too low
        $validator = Validator::make(['per_page' => 0], $request->rules());
        $this->assertFalse($validator->passes());

        // Test too high
        $validator = Validator::make(['per_page' => 101], $request->rules());
        $this->assertFalse($validator->passes());
    }

    #[Test]
    public function validation_rules_reject_non_integer_preacher_id(): void
    {
        $data = [
            'preacher_id' => 'not-an-integer',
        ];

        $request = new SermonIndexRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->passes());
    }

    #[Test]
    public function validation_rules_reject_oversized_digits_preacher_id(): void
    {
        $data = [
            'preacher_id' => 12345678901, // 11 digits
        ];

        $request = new SermonIndexRequest;
        $rules = $request->rules();

        // Temporarily filter out exists rule so we only test bounds
        $rules['preacher_id'] = array_filter($rules['preacher_id'], function ($rule) {
            return ! (is_string($rule) && str_starts_with($rule, 'exists'));
        });

        $validator = Validator::make($data, ['preacher_id' => $rules['preacher_id']]);

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('preacher_id'));
    }

    #[Test]
    public function validation_rules_reject_oversized_digits_per_page(): void
    {
        $data = [
            'per_page' => 1000, // 4 digits
        ];

        $request = new SermonIndexRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('per_page'));
    }

    #[Test]
    public function validation_rules_reject_oversized_strings(): void
    {
        $request = new SermonIndexRequest;

        $validator = Validator::make(['search' => str_repeat('a', 256)], $request->rules());
        $this->assertFalse($validator->passes());

        $validator = Validator::make(['service' => str_repeat('a', 51)], $request->rules());
        $this->assertFalse($validator->passes());

        $validator = Validator::make(['preacher' => str_repeat('a', 256)], $request->rules());
        $this->assertFalse($validator->passes());

        $validator = Validator::make(['series' => str_repeat('a', 256)], $request->rules());
        $this->assertFalse($validator->passes());
    }
}
