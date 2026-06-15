<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\Meeting;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingValidationTest extends TestCase
{
    #[Test]
    public function it_validates_day_length(): void
    {
        $rules = Meeting::validationRules();

        // 255 characters - should pass
        $validDay = str_repeat('a', 255);
        $validator = Validator::make(['day' => $validDay], ['day' => $rules['day']]);
        $this->assertFalse($validator->fails(), '255 characters should pass');

        // 256 characters - should fail
        $invalidDay = str_repeat('a', 256);
        $validator = Validator::make(['day' => $invalidDay], ['day' => $rules['day']]);
        $this->assertTrue($validator->fails(), '256 characters should fail');
        $this->assertEquals('The day field must not be greater than 255 characters.', $validator->errors()->first('day'));
    }

    #[Test]
    public function it_validates_trimmed_text_rule(): void
    {
        $rules = Meeting::validationRules();

        // Leading whitespace
        $validator = Validator::make(['who' => '  Everyone'], ['who' => $rules['who']]);
        $this->assertTrue($validator->fails());
        $this->assertEquals('The who field must not be empty or contain leading or trailing whitespace.', $validator->errors()->first('who'));

        // Trailing whitespace
        $validator = Validator::make(['location' => 'Church '], ['location' => $rules['location']]);
        $this->assertTrue($validator->fails());
        $this->assertEquals('The location field must not be empty or contain leading or trailing whitespace.', $validator->errors()->first('location'));

        // Empty string
        $validator = Validator::make(['who' => ''], ['who' => $rules['who']]);
        $this->assertTrue($validator->fails());

        // Valid trimmed text
        $validator = Validator::make(['who' => 'Everyone'], ['who' => $rules['who']]);
        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_frequency_if_recurring_is_true(): void
    {
        $rules = Meeting::validationRules();

        // recurring=true, frequency=null -> should fail
        $validator = Validator::make([
            'is_recurring' => true,
            'frequency' => null,
        ], [
            'is_recurring' => $rules['is_recurring'],
            'frequency' => $rules['frequency'],
        ]);
        $this->assertTrue($validator->fails());
        $this->assertEquals('The frequency field is required when is recurring is true.', $validator->errors()->first('frequency'));

        // recurring=false, frequency=null -> should pass
        $validator = Validator::make([
            'is_recurring' => false,
            'frequency' => null,
        ], [
            'is_recurring' => $rules['is_recurring'],
            'frequency' => $rules['frequency'],
        ]);
        $this->assertFalse($validator->fails());
    }
}
