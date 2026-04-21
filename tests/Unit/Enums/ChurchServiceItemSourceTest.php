<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ChurchServiceItemSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ChurchServiceItemSourceTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = ChurchServiceItemSource::cases();

        $this->assertCount(4, $cases);
        $this->assertSame('email', ChurchServiceItemSource::Email->value);
        $this->assertSame('openlp', ChurchServiceItemSource::OpenLp->value);
        $this->assertSame('manual', ChurchServiceItemSource::Manual->value);
        $this->assertSame('livestream', ChurchServiceItemSource::Livestream->value);
    }

    #[Test]
    public function it_returns_all_values(): void
    {
        $this->assertSame(
            ['email', 'openlp', 'manual', 'livestream'],
            ChurchServiceItemSource::values()
        );
    }

    #[Test]
    public function is_human_provided_returns_true_for_email_and_manual(): void
    {
        $this->assertTrue(ChurchServiceItemSource::Email->isHumanProvided());
        $this->assertTrue(ChurchServiceItemSource::Manual->isHumanProvided());
    }

    #[Test]
    public function is_human_provided_returns_false_for_openlp_and_livestream(): void
    {
        $this->assertFalse(ChurchServiceItemSource::OpenLp->isHumanProvided());
        $this->assertFalse(ChurchServiceItemSource::Livestream->isHumanProvided());
    }

    #[Test]
    public function is_detected_returns_true_only_for_livestream(): void
    {
        $this->assertTrue(ChurchServiceItemSource::Livestream->isDetected());
        $this->assertFalse(ChurchServiceItemSource::Email->isDetected());
        $this->assertFalse(ChurchServiceItemSource::OpenLp->isDetected());
        $this->assertFalse(ChurchServiceItemSource::Manual->isDetected());
    }

    #[Test]
    public function it_resolves_from_string_values(): void
    {
        $this->assertSame(ChurchServiceItemSource::Livestream, ChurchServiceItemSource::from('livestream'));
        $this->assertSame(ChurchServiceItemSource::Email, ChurchServiceItemSource::from('email'));
        $this->assertSame(ChurchServiceItemSource::OpenLp, ChurchServiceItemSource::from('openlp'));
        $this->assertSame(ChurchServiceItemSource::Manual, ChurchServiceItemSource::from('manual'));
    }
}
