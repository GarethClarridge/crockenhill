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

        // 75 characters - should pass
        $validDay = str_repeat('a', 75);
        $validator = Validator::make(['day' => $validDay], ['day' => $rules['day']]);
        $this->assertFalse($validator->fails(), '75 characters should pass');

        // 76 characters - should fail
        $invalidDay = str_repeat('a', 76);
        $validator = Validator::make(['day' => $invalidDay], ['day' => $rules['day']]);
        $this->assertTrue($validator->fails(), '76 characters should fail');
        $this->assertEquals('The day field must not be greater than 75 characters.', $validator->errors()->first('day'));
    }
}
