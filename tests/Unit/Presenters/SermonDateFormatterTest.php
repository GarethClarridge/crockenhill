<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Models\Sermon;
use App\Presenters\SermonDateFormatter;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonDateFormatterTest extends TestCase
{
    private SermonDateFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new SermonDateFormatter;
    }

    #[Test]
    public function it_formats_duration_as_human_readable_string(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 2705]); // 45m (floored)

        $this->assertSame('45m', $this->formatter->formattedDuration($sermon));
    }

    #[Test]
    public function it_returns_null_duration_when_absent(): void
    {
        $sermon = Sermon::factory()->make(['duration' => null]);

        $this->assertNull($this->formatter->formattedDuration($sermon));
        $this->assertNull($this->formatter->durationIso8601($sermon));
    }

    #[Test]
    public function it_formats_duration_as_iso8601_string(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 2705]);

        $this->assertSame('PT45M5S', $this->formatter->durationIso8601($sermon));
    }

    #[Test]
    public function it_returns_formatted_dates(): void
    {
        $sermon = Sermon::factory()->make(['date' => Carbon::parse('2024-03-10')]);

        $dates = $this->formatter->formattedDates($sermon);

        $this->assertSame('March 10, 2024', $dates['human']);
        $this->assertSame('2024-03-10', $dates['iso']);
        $this->assertSame('10 March 2024', $dates['short']);
    }

    #[Test]
    public function it_returns_human_date(): void
    {
        $sermon = Sermon::factory()->make(['date' => Carbon::parse('2024-03-10')]);

        $this->assertSame('March 10, 2024', $this->formatter->humanDate($sermon));
    }
}
