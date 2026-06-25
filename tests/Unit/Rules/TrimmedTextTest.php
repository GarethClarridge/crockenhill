<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\TrimmedText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TrimmedTextTest extends TestCase
{
    private TrimmedText $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new TrimmedText;
    }

    #[Test]
    public function it_passes_for_null_values(): void
    {
        $failed = false;
        $this->rule->validate('attribute', null, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Validation should have passed for null.');
    }

    #[Test]
    public function it_passes_for_valid_trimmed_strings(): void
    {
        $validStrings = [
            'valid string',
            'another-valid-string',
            'SingleWord',
            'String with numbers 123',
            'String with punctuation!',
        ];

        foreach ($validStrings as $value) {
            $failed = false;
            $this->rule->validate('attribute', $value, function () use (&$failed) {
                $failed = true;
            });

            $this->assertFalse($failed, "Validation should have passed for '{$value}'.");
        }
    }

    #[Test]
    public function it_fails_for_strings_with_leading_whitespace(): void
    {
        $invalidStrings = [
            ' leading whitespace',
            '  multiple leading spaces',
            "\tleading tab",
            "\nleading newline",
        ];

        foreach ($invalidStrings as $value) {
            $failed = false;
            $this->rule->validate('attribute', $value, function ($message) use (&$failed) {
                $failed = true;
                $this->assertStringContainsString('must not be empty or contain leading or trailing whitespace', $message);
            });

            $this->assertTrue($failed, "Validation should have failed for '{$value}' due to leading whitespace.");
        }
    }

    #[Test]
    public function it_fails_for_strings_with_trailing_whitespace(): void
    {
        $invalidStrings = [
            'trailing whitespace ',
            'multiple trailing spaces  ',
            "trailing tab\t",
            "trailing newline\n",
        ];

        foreach ($invalidStrings as $value) {
            $failed = false;
            $this->rule->validate('attribute', $value, function ($message) use (&$failed) {
                $failed = true;
                $this->assertStringContainsString('must not be empty or contain leading or trailing whitespace', $message);
            });

            $this->assertTrue($failed, "Validation should have failed for '{$value}' due to trailing whitespace.");
        }
    }

    #[Test]
    public function it_fails_for_empty_strings(): void
    {
        $failed = false;
        $this->rule->validate('attribute', '', function ($message) use (&$failed) {
            $failed = true;
            $this->assertStringContainsString('must not be empty or contain leading or trailing whitespace', $message);
        });

        $this->assertTrue($failed, 'Validation should have failed for an empty string.');
    }

    #[Test]
    public function it_fails_for_whitespace_only_strings(): void
    {
        $invalidStrings = [
            ' ',
            '   ',
            "\t",
            "\n",
            "\r\n",
        ];

        foreach ($invalidStrings as $value) {
            $failed = false;
            $this->rule->validate('attribute', $value, function ($message) use (&$failed) {
                $failed = true;
                $this->assertStringContainsString('must not be empty or contain leading or trailing whitespace', $message);
            });

            $this->assertTrue($failed, "Validation should have failed for whitespace-only string '{$value}'.");
        }
    }

    #[Test]
    public function it_fails_for_non_string_values(): void
    {
        $invalidValues = [
            123,
            12.34,
            true,
            false,
            [],
            new \stdClass,
        ];

        foreach ($invalidValues as $value) {
            $failed = false;
            $this->rule->validate('attribute', $value, function ($message) use (&$failed) {
                $failed = true;
                $this->assertStringContainsString('must not be empty or contain leading or trailing whitespace', $message);
            });

            $this->assertTrue($failed, 'Validation should have failed for non-string value: '.print_r($value, true));
        }
    }
}
