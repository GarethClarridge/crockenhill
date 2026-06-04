<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Thumbnail\ThumbnailTextHelper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailTextHelperTest extends TestCase
{
    private ThumbnailTextHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new ThumbnailTextHelper;
    }

    // ---- calculateResponsiveFontSize ----

    #[Test]
    public function it_returns_base_font_size_at_reference_width(): void
    {
        $this->assertEquals(48, $this->helper->calculateResponsiveFontSize(48, 1280, 1280));
    }

    #[Test]
    public function it_scales_font_size_up_for_wider_images(): void
    {
        $this->assertEquals(96, $this->helper->calculateResponsiveFontSize(48, 2560, 1280));
    }

    #[Test]
    public function it_scales_font_size_down_for_narrower_images(): void
    {
        $this->assertEquals(24, $this->helper->calculateResponsiveFontSize(48, 640, 1280));
    }

    #[Test]
    public function it_clamps_font_scaling_to_maximum_of_two(): void
    {
        // Very large width should cap at 2x
        $this->assertEquals(96, $this->helper->calculateResponsiveFontSize(48, 5000, 1280));
    }

    #[Test]
    public function it_clamps_font_scaling_to_minimum_of_half(): void
    {
        // Very small width should cap at 0.5x
        $this->assertEquals(24, $this->helper->calculateResponsiveFontSize(48, 100, 1280));
    }

    // ---- calculateTextBounds ----

    #[Test]
    public function it_returns_width_and_height_keys(): void
    {
        $bounds = $this->helper->calculateTextBounds('Hello', 24, null);

        $this->assertArrayHasKey('width', $bounds);
        $this->assertArrayHasKey('height', $bounds);
    }

    #[Test]
    public function it_returns_positive_bounds_for_non_empty_text(): void
    {
        $bounds = $this->helper->calculateTextBounds('Test Text', 24, null);

        $this->assertGreaterThan(0, $bounds['width']);
        $this->assertGreaterThan(0, $bounds['height']);
    }

    #[Test]
    public function it_scales_bounds_with_font_size(): void
    {
        $bounds24 = $this->helper->calculateTextBounds('Test Text', 24, null);
        $bounds48 = $this->helper->calculateTextBounds('Test Text', 48, null);

        $this->assertGreaterThan($bounds24['width'], $bounds48['width']);
        $this->assertGreaterThan($bounds24['height'], $bounds48['height']);
    }

    #[Test]
    public function it_measures_multiline_text_as_taller(): void
    {
        $singleLine = $this->helper->calculateTextBounds('One line', 24, null);
        $multiLine = $this->helper->calculateTextBounds("Line one\nLine two\nLine three", 24, null);

        $this->assertGreaterThan($singleLine['height'], $multiLine['height']);
    }

    // ---- fallbackTextWrap ----

    #[Test]
    public function it_wraps_long_text_to_multiple_lines(): void
    {
        $longText = 'This is a very long sermon title that should definitely be wrapped across multiple lines';
        $result = $this->helper->fallbackTextWrap($longText, 200, 24);

        $lines = explode("\n", $result);
        $this->assertGreaterThan(1, count($lines));
    }

    #[Test]
    public function it_preserves_all_words_when_wrapping(): void
    {
        $longText = 'This is a very long sermon title that should definitely be wrapped across multiple lines';
        $result = $this->helper->fallbackTextWrap($longText, 200, 24);

        $this->assertEquals(
            str_word_count($longText),
            str_word_count(str_replace("\n", ' ', $result))
        );
    }

    #[Test]
    public function it_returns_single_word_unchanged(): void
    {
        $result = $this->helper->fallbackTextWrap('Hello', 400, 24);
        $this->assertEquals('Hello', $result);
    }

    #[Test]
    public function it_returns_empty_string_for_empty_input(): void
    {
        $result = $this->helper->fallbackTextWrap('', 400, 24);
        $this->assertEquals('', $result);
    }
}
