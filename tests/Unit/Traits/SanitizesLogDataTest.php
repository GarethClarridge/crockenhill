<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Traits\SanitizesLogData;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SanitizesLogDataTest extends TestCase
{
    private object $traitInstance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->traitInstance = new class
        {
            use SanitizesLogData;

            public function callSanitizeForLog(string $value): string
            {
                return $this->sanitizeForLog($value);
            }
        };
    }

    #[Test]
    public function it_leaves_normal_strings_unchanged(): void
    {
        $this->assertSame('Normal String', $this->traitInstance->callSanitizeForLog('Normal String'));
    }

    #[Test]
    public function it_replaces_newlines_and_carriage_returns_with_spaces(): void
    {
        $this->assertSame('Line 1 Line 2', $this->traitInstance->callSanitizeForLog("Line 1\nLine 2"));
        $this->assertSame('Line 1 Line 2', $this->traitInstance->callSanitizeForLog("Line 1\r\nLine 2"));
        $this->assertSame('Line 1 Line 2', $this->traitInstance->callSanitizeForLog("Line 1\rLine 2"));
    }

    #[Test]
    public function it_replaces_tabs_with_spaces(): void
    {
        $this->assertSame('Tab separated values', $this->traitInstance->callSanitizeForLog("Tab\tseparated\tvalues"));
    }

    #[Test]
    public function it_collapses_multiple_spaces_into_one(): void
    {
        $this->assertSame('Too many spaces', $this->traitInstance->callSanitizeForLog('Too   many  spaces'));
    }

    #[Test]
    public function it_trims_leading_and_trailing_whitespace(): void
    {
        $this->assertSame('Trimmed', $this->traitInstance->callSanitizeForLog('  Trimmed  '));
    }

    #[Test]
    public function it_handles_complex_malicious_input(): void
    {
        $input = "  Attempted\nLog\rInjection\tWith   Multiple  Spaces  ";
        $expected = 'Attempted Log Injection With Multiple Spaces';

        $this->assertSame($expected, $this->traitInstance->callSanitizeForLog($input));
    }
}
