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

    #[Test]
    public function validation_rules_reject_oversized_preacher_id_digit_length(): void
    {
        $request = new SermonIndexRequest;

        // preacher_id with 11 digits (max allowed digits is 10)
        $validator = Validator::make(['preacher_id' => '12345678901'], $request->rules());
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('preacher_id', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rules_reject_oversized_per_page_digit_length(): void
    {
        $request = new SermonIndexRequest;

        // per_page with 4 digits (max allowed digits is 3)
        $validator = Validator::make(['per_page' => '1000'], $request->rules());
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }
}
