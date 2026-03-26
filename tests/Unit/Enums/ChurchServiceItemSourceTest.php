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
        $this->assertSame('email', ChurchServiceItemSource::EMAIL->value);
        $this->assertSame('openlp', ChurchServiceItemSource::OPENLP->value);
        $this->assertSame('manual', ChurchServiceItemSource::MANUAL->value);
        $this->assertSame('livestream', ChurchServiceItemSource::LIVESTREAM->value);
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
        $this->assertTrue(ChurchServiceItemSource::EMAIL->isHumanProvided());
        $this->assertTrue(ChurchServiceItemSource::MANUAL->isHumanProvided());
    }

    #[Test]
    public function is_human_provided_returns_false_for_openlp_and_livestream(): void
    {
        $this->assertFalse(ChurchServiceItemSource::OPENLP->isHumanProvided());
        $this->assertFalse(ChurchServiceItemSource::LIVESTREAM->isHumanProvided());
    }

    #[Test]
    public function is_detected_returns_true_only_for_livestream(): void
    {
        $this->assertTrue(ChurchServiceItemSource::LIVESTREAM->isDetected());
        $this->assertFalse(ChurchServiceItemSource::EMAIL->isDetected());
        $this->assertFalse(ChurchServiceItemSource::OPENLP->isDetected());
        $this->assertFalse(ChurchServiceItemSource::MANUAL->isDetected());
    }

    #[Test]
    public function it_resolves_from_string_values(): void
    {
        $this->assertSame(ChurchServiceItemSource::LIVESTREAM, ChurchServiceItemSource::from('livestream'));
        $this->assertSame(ChurchServiceItemSource::EMAIL, ChurchServiceItemSource::from('email'));
        $this->assertSame(ChurchServiceItemSource::OPENLP, ChurchServiceItemSource::from('openlp'));
        $this->assertSame(ChurchServiceItemSource::MANUAL, ChurchServiceItemSource::from('manual'));
    }
}
