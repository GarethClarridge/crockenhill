<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SermonContentFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonContentFormatterTest extends TestCase
{
    #[Test]
    public function human_duration_returns_null_for_missing_or_non_positive_durations(): void
    {
        $this->assertNull(SermonContentFormatter::humanDuration(null));
        $this->assertNull(SermonContentFormatter::humanDuration(0));
        $this->assertNull(SermonContentFormatter::humanDuration(-60));
    }

    #[Test]
    public function human_duration_formats_minutes_only(): void
    {
        $this->assertSame('30m', SermonContentFormatter::humanDuration(1800));
    }

    #[Test]
    public function human_duration_formats_hours_and_minutes(): void
    {
        $this->assertSame('1h 30m', SermonContentFormatter::humanDuration(5400));
    }

    #[Test]
    public function human_duration_handles_exactly_one_hour(): void
    {
        $this->assertSame('1h 0m', SermonContentFormatter::humanDuration(3600));
    }

    #[Test]
    public function human_duration_floors_sub_minute_remainders(): void
    {
        $this->assertSame('0m', SermonContentFormatter::humanDuration(45));
    }

    #[Test]
    public function iso8601_duration_returns_null_for_missing_or_non_positive_durations(): void
    {
        $this->assertNull(SermonContentFormatter::iso8601Duration(null));
        $this->assertNull(SermonContentFormatter::iso8601Duration(0));
        $this->assertNull(SermonContentFormatter::iso8601Duration(-1));
    }

    #[Test]
    public function iso8601_duration_formats_correctly(): void
    {
        $this->assertSame('PT45M', SermonContentFormatter::iso8601Duration(2700));
        $this->assertSame('PT1H', SermonContentFormatter::iso8601Duration(3600));
    }

    #[Test]
    public function plain_text_outline_returns_null_for_empty_or_non_array_points(): void
    {
        $this->assertNull(SermonContentFormatter::plainTextOutline(null));
        $this->assertNull(SermonContentFormatter::plainTextOutline([]));
        $this->assertNull(SermonContentFormatter::plainTextOutline('not an array'));
    }

    #[Test]
    public function plain_text_outline_formats_structured_and_scalar_points(): void
    {
        $points = [
            ['point' => 'First point', 'sub_points' => ['First sub point']],
            'Second point',
        ];

        $this->assertSame(
            "1. First point\n   - First sub point\n2. Second point",
            SermonContentFormatter::plainTextOutline($points),
        );
    }

    #[Test]
    public function plain_text_outline_labels_untitled_points_carrying_sub_points(): void
    {
        $points = [
            ['point' => '', 'sub_points' => ['Orphan sub point']],
        ];

        $this->assertSame(
            "1. (Untitled point)\n   - Orphan sub point",
            SermonContentFormatter::plainTextOutline($points),
        );
    }

    #[Test]
    public function plain_text_outline_skips_fully_empty_points(): void
    {
        $points = [
            ['point' => '', 'sub_points' => []],
            'Real point',
        ];

        $this->assertSame('1. Real point', SermonContentFormatter::plainTextOutline($points));
    }

    #[Test]
    public function meta_description_builds_base_sentence_from_title_preacher_and_date(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'The Prodigal Son',
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: null,
            hasVideo: false,
            hasAudio: false,
            summary: null,
        );

        $this->assertSame(
            "Listen to 'The Prodigal Son' by John Smith preached at Crockenhill Baptist Church on March 14, 2025",
            $description,
        );
    }

    #[Test]
    public function meta_description_chooses_verb_from_media_availability(): void
    {
        $args = [
            'title' => 'The Prodigal Son',
            'preacherName' => 'John Smith',
            'humanDate' => 'March 14, 2025',
            'reference' => null,
            'series' => null,
            'serviceLabel' => null,
            'summary' => null,
        ];

        $this->assertStringStartsWith('Watch or listen to', SermonContentFormatter::metaDescription(...$args, hasVideo: true, hasAudio: true));
        $this->assertStringStartsWith('Watch ', SermonContentFormatter::metaDescription(...$args, hasVideo: true, hasAudio: false));
        $this->assertStringStartsWith('Listen to', SermonContentFormatter::metaDescription(...$args, hasVideo: false, hasAudio: true));
        $this->assertStringStartsWith('Listen to', SermonContentFormatter::metaDescription(...$args, hasVideo: false, hasAudio: false));
    }

    #[Test]
    public function meta_description_appends_reference_and_series(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'The Prodigal Son',
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: 'Luke 15:11-32',
            series: 'Parables of Jesus',
            serviceLabel: null,
            hasVideo: false,
            hasAudio: false,
            summary: null,
        );

        $this->assertStringContainsString(' - Luke 15:11-32', $description);
        $this->assertStringContainsString('(Part of our Parables of Jesus series)', $description);
    }

    #[Test]
    public function meta_description_includes_service_phrase_when_a_service_label_is_given(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'The Prodigal Son',
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: 'Morning',
            hasVideo: false,
            hasAudio: false,
            summary: null,
        );

        $this->assertStringContainsString('during our Morning service', $description);
    }

    #[Test]
    public function meta_description_omits_service_phrase_when_no_service_label(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'The Prodigal Son',
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: null,
            hasVideo: false,
            hasAudio: false,
            summary: null,
        );

        $this->assertStringNotContainsString('during our', $description);
    }

    #[Test]
    public function meta_description_drops_service_phrase_rather_than_truncating_the_series(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'The Prodigal Son',
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: 'Luke 15:11-32',
            series: 'Parables of Jesus',
            serviceLabel: 'Morning',
            hasVideo: false,
            hasAudio: false,
            summary: null,
        );

        // The lower-priority service phrase is sacrificed so the scripture reference
        // and full series text both survive within the limit.
        $this->assertStringNotContainsString('during our Morning service', $description);
        $this->assertStringContainsString(' - Luke 15:11-32', $description);
        $this->assertStringContainsString('(Part of our Parables of Jesus series)', $description);
    }

    #[Test]
    public function meta_description_drops_service_phrase_rather_than_shortening_the_summary(): void
    {
        // This summary fits within the limit alongside the base sentence, but only
        // if the lower-priority service phrase is omitted. The service phrase must
        // be dropped so the full summary survives untruncated.
        $summary = 'God is good and faithful to us all every single day.';

        $description = SermonContentFormatter::metaDescription(
            title: 'Grace',
            preacherName: 'John',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: 'Morning',
            hasVideo: false,
            hasAudio: false,
            summary: $summary,
        );

        $this->assertStringNotContainsString('during our Morning service', $description);
        $this->assertStringContainsString($summary, $description);
        $this->assertStringEndsWith($summary, $description);
    }

    #[Test]
    public function meta_description_keeps_both_service_phrase_and_summary_when_they_fit(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'Grace',
            preacherName: 'John',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: 'Morning',
            hasVideo: false,
            hasAudio: false,
            summary: 'Short summary.',
        );

        $this->assertStringContainsString('during our Morning service', $description);
        $this->assertStringEndsWith('. Short summary.', $description);
    }

    #[Test]
    public function meta_description_appends_summary_when_it_fits(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'The Prodigal Son',
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: null,
            hasVideo: false,
            hasAudio: false,
            summary: 'This is a great sermon summary.',
        );

        $this->assertStringEndsWith('. This is a great sermon summary.', $description);
    }

    #[Test]
    public function meta_description_treats_blank_summary_as_omitted(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'The Prodigal Son',
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: null,
            hasVideo: false,
            hasAudio: false,
            summary: '   ',
        );

        $this->assertSame(
            "Listen to 'The Prodigal Son' by John Smith preached at Crockenhill Baptist Church on March 14, 2025",
            $description,
        );
    }

    #[Test]
    public function meta_description_truncates_base_when_it_exceeds_the_limit(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: str_repeat('Very Long Sermon Title ', 10),
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: null,
            hasVideo: false,
            hasAudio: false,
            summary: null,
        );

        $this->assertLessThanOrEqual(163, strlen($description));
        $this->assertStringEndsWith('...', $description);
    }

    #[Test]
    public function meta_description_truncates_summary_to_fit_within_the_limit(): void
    {
        $description = SermonContentFormatter::metaDescription(
            title: 'The Prodigal Son',
            preacherName: 'John Smith',
            humanDate: 'March 14, 2025',
            reference: null,
            series: null,
            serviceLabel: null,
            hasVideo: false,
            hasAudio: false,
            summary: str_repeat('This is a very long summary that should definitely be truncated to ensure the total length remains within expected limits. ', 10),
        );

        $this->assertLessThanOrEqual(163, strlen($description));
        $this->assertStringContainsString("Listen to 'The Prodigal Son' by John Smith", $description);
        $this->assertStringEndsWith('...', $description);
    }
}
