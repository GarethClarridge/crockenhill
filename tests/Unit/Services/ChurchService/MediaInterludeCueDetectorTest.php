<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\MediaInterludeCueDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaInterludeCueDetectorTest extends TestCase
{
    private MediaInterludeCueDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new MediaInterludeCueDetector;
    }

    #[Test]
    public function it_returns_false_for_null_or_empty_transcript(): void
    {
        $this->assertFalse($this->detector->hasCue(null));
        $this->assertFalse($this->detector->hasCue(''));
        $this->assertFalse($this->detector->hasCue('   '));
    }

    #[Test]
    public function it_returns_true_for_exact_cue_phrases(): void
    {
        $this->assertTrue($this->detector->hasCue('watch a video'));
        $this->assertTrue($this->detector->hasCue('mission update'));
        $this->assertTrue($this->detector->hasCue('take a look at this'));
        $this->assertTrue($this->detector->hasCue('short film'));
    }

    #[Test]
    public function it_is_case_insensitive(): void
    {
        $this->assertTrue($this->detector->hasCue('WATCH A VIDEO'));
        $this->assertTrue($this->detector->hasCue('Mission Update'));
        $this->assertTrue($this->detector->hasCue('Take A Look At This'));
    }

    #[Test]
    public function it_detects_cue_phrases_embedded_in_text(): void
    {
        $this->assertTrue($this->detector->hasCue("Now we're going to watch a video together."));
        $this->assertTrue($this->detector->hasCue('Please take a look at this video on the screen.'));
        $this->assertTrue($this->detector->hasCue('And here is a short clip from the mission field.'));
    }

    #[Test]
    public function it_returns_false_for_transcript_without_cues(): void
    {
        $this->assertFalse($this->detector->hasCue('Let us pray together.'));
        $this->assertFalse($this->detector->hasCue('The reading is from John chapter 3.'));
        $this->assertFalse($this->detector->hasCue('We will now sing our next hymn.'));
    }
}
