<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\SermonPointElement;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPointElementTest extends TestCase
{
    #[Test]
    public function it_passes_for_null(): void
    {
        $rule = new SermonPointElement;
        $failed = false;

        $rule->validate('attribute', null, function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed, 'Rule should have passed for null.');
    }

    #[Test]
    public function it_passes_for_array_values(): void
    {
        // Arrays are handled by nested rules (points.*.point and points.*.sub_points)
        $rule = new SermonPointElement;
        $failed = false;

        $rule->validate('attribute', ['point' => 'test', 'sub_points' => []], function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed, 'Rule should have passed for an array.');
    }

    #[Test]
    public function it_passes_for_valid_strings(): void
    {
        $rule = new SermonPointElement(10);
        $failed = false;

        $rule->validate('attribute', '1234567890', function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed, 'Rule should have passed for a string within max length.');
    }

    #[Test]
    public function it_fails_for_over_long_strings(): void
    {
        $rule = new SermonPointElement(5);
        $failed = false;

        $rule->validate('attribute', '123456', function ($message) use (&$failed): void {
            $failed = true;
            $this->assertEquals('The :attribute field must not be greater than 5 characters.', $message);
        });

        $this->assertTrue($failed, 'Rule should have failed for an over-long string.');
    }

    #[Test]
    public function it_fails_for_non_string_non_array_scalars(): void
    {
        $rule = new SermonPointElement;

        // Test integer
        $failed = false;
        $rule->validate('attribute', 123, function ($message) use (&$failed): void {
            $failed = true;
            $this->assertEquals('The :attribute field must be a string.', $message);
        });
        $this->assertTrue($failed, 'Rule should have failed for an integer.');

        // Test boolean
        $failed = false;
        $rule->validate('attribute', true, function ($message) use (&$failed): void {
            $failed = true;
            $this->assertEquals('The :attribute field must be a string.', $message);
        });
        $this->assertTrue($failed, 'Rule should have failed for a boolean.');
    }

    #[Test]
    public function it_handles_multibyte_strings_correctly(): void
    {
        // '🚀' is 1 character but 4 bytes
        $rule = new SermonPointElement(1);
        $failed = false;

        $rule->validate('attribute', '🚀', function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed, 'Rule should have passed for a 1-character multibyte string.');

        $failed = false;
        $rule->validate('attribute', '🚀🚀', function ($message) use (&$failed): void {
            $failed = true;
            $this->assertEquals('The :attribute field must not be greater than 1 characters.', $message);
        });

        $this->assertTrue($failed, 'Rule should have failed for a 2-character multibyte string.');
    }
}
