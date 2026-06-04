<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\NotEmptyString;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotEmptyStringTest extends TestCase
{
    private NotEmptyString $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new NotEmptyString;
    }

    #[Test]
    public function it_passes_for_valid_strings(): void
    {
        $failed = false;
        $this->rule->validate('attribute', 'valid string', function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed, 'Rule should have passed for a valid string.');
    }

    #[Test]
    public function it_passes_for_null(): void
    {
        $failed = false;
        $this->rule->validate('attribute', null, function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed, 'Rule should have passed for null.');
    }

    #[Test]
    public function it_fails_for_empty_string(): void
    {
        $failed = false;
        $this->rule->validate('attribute', '', function ($message) use (&$failed): void {
            $failed = true;
            $this->assertEquals('The :attribute field must not be empty or contain only whitespace.', $message);
        });

        $this->assertTrue($failed, 'Rule should have failed for empty string.');
    }

    #[Test]
    public function it_fails_for_whitespace_only_strings(): void
    {
        $failed = false;
        $this->rule->validate('attribute', '   ', function ($message) use (&$failed): void {
            $failed = true;
            $this->assertEquals('The :attribute field must not be empty or contain only whitespace.', $message);
        });

        $this->assertTrue($failed, 'Rule should have failed for whitespace.');
    }

    #[Test]
    public function it_fails_for_non_string_values(): void
    {
        $failed = false;
        $this->rule->validate('attribute', 123, function ($message) use (&$failed): void {
            $failed = true;
            $this->assertEquals('The :attribute field must not be empty or contain only whitespace.', $message);
        });

        $this->assertTrue($failed, 'Rule should have failed for non-string value.');
    }
}
