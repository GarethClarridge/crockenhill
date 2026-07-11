<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use App\Services\Email\OosEmailParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosEmailParserServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prefers_plain_text_and_returns_high_confidence_parse(): void
    {
        $extractor = new class implements OosEmailItemExtractor
        {
            public string $capturedBody = '';

            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                $this->capturedBody = $body;

                return new OosEmailItemExtractionResult(
                    items: [
                        ['type' => 'welcome', 'title' => 'Welcome'],
                        ['type' => 'song', 'title' => 'Before the throne of God above'],
                        ['type' => 'prayer', 'title' => 'Opening prayer'],
                        ['type' => 'bible_reading', 'title' => 'Luke 15:1-32'],
                    ],
                    confidence: 0.95,
                );
            }
        };

        $parser = new OosEmailParserService($extractor);

        $email = InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 15 March 2026 AM',
            'body_plain' => "Welcome\nBefore the throne of God above\nOpening prayer\nLuke 15:1-32",
            'body_html' => '<p>HTML should not be used</p>',
            'received_at' => '2026-03-10 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame("Welcome\nBefore the throne of God above\nOpening prayer\nLuke 15:1-32", $extractor->capturedBody);
        $this->assertSame('2026-03-15', $result->date);
        $this->assertSame(SermonService::Morning, $result->service);
        $this->assertTrue($result->shouldImport);
        $this->assertFalse($result->needsReview);
        $this->assertGreaterThanOrEqual(0.90, $result->confidenceScore);
        $this->assertSame('songs', $result->items[1]['type']);
        $this->assertNull($result->items[1]['openlp_search_title']);
    }

    #[Test]
    public function it_marks_ambiguous_parse_for_review(): void
    {
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [
                        ['type' => 'welcome', 'title' => 'Welcome'],
                        ['type' => 'song', 'title' => 'How deep the Father\'s love for us'],
                        ['type' => 'sermon', 'title' => 'Sermon'],
                    ],
                    confidence: 0.90,
                );
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'Service plan for 16 March',
            'body_plain' => "10.30am service\nWelcome\nHow deep the Father's love for us\nSermon",
            'received_at' => '2026-03-10 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('2026-03-16', $result->date);
        $this->assertSame(SermonService::Morning, $result->service);
        $this->assertTrue($result->shouldImport);
        $this->assertTrue($result->needsReview);
        $this->assertGreaterThanOrEqual(0.75, $result->confidenceScore);
        $this->assertLessThan(0.90, $result->confidenceScore);
    }

    #[Test]
    public function it_uses_html_when_plain_text_is_missing(): void
    {
        $extractor = new class implements OosEmailItemExtractor
        {
            public string $capturedBody = '';

            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                $this->capturedBody = $body;

                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Song one']],
                    confidence: 0.92,
                );
            }
        };

        $parser = new OosEmailParserService($extractor);

        $email = InboundEmail::factory()->make([
            'subject' => 'OoS 2026-03-16 AM',
            'body_plain' => null,
            'body_html' => '<p>Welcome</p><p>Song one</p><div>Prayer</div>',
            'received_at' => '2026-03-10 09:00:00',
        ]);

        $parser->parse($email);

        $this->assertSame("Welcome\nSong one\nPrayer", $extractor->capturedBody);
    }

    #[Test]
    public function it_returns_low_confidence_when_email_is_garbage(): void
    {
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(items: [], confidence: 0.10, notes: ['Could not identify any OoS items.']);
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'hello there',
            'body_plain' => 'just checking in',
            'received_at' => '2026-03-10 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertNull($result->date);
        $this->assertNull($result->service);
        $this->assertFalse($result->shouldImport);
        $this->assertFalse($result->needsReview);
        $this->assertLessThan(0.75, $result->confidenceScore);
    }

    #[Test]
    public function it_preserves_unknown_llm_types_as_other_items(): void
    {
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'communion', 'title' => 'Communion']],
                    confidence: 0.92,
                );
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'OoS 2026-03-16 AM',
            'body_plain' => 'Communion',
            'received_at' => '2026-03-10 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('custom', $result->items[0]['type']);
        $this->assertSame('other', $result->items[0]['metadata']['email_type']);
    }

    #[Test]
    public function it_extracts_dates_from_multiple_subject_formats(): void
    {
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Song one']],
                    confidence: 0.92,
                );
            }
        });

        $cases = [
            'OoS 2026-03-16 AM' => '2026-03-16',
            'OoS 16/03/2026 AM' => '2026-03-16',
            'OoS Sunday 16th March AM' => '2026-03-16',
            'OoS March 16, 2026 AM' => '2026-03-16',
        ];

        foreach ($cases as $subject => $expectedDate) {
            $email = InboundEmail::factory()->make([
                'subject' => $subject,
                'body_plain' => 'Song one',
                'received_at' => '2026-03-10 09:00:00',
            ]);

            $result = $parser->parse($email);

            $this->assertSame($expectedDate, $result->date, $subject);
        }
    }

    #[Test]
    public function an_implausible_extractor_plan_date_falls_back_to_the_email_level_extraction(): void
    {
        // gpt-4.1-nano defaults to its training period when the email names no year: the
        // subject "sunday 14th June" (received June 2026) came back as 2023-06-14, overriding
        // the email-level extraction's correct 2026-06-14 (first archive eval run, entry #3).
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Praise to the Lord']],
                    confidence: 0.95,
                    services: [
                        ['service' => 'morning', 'date' => '2023-06-14', 'items' => [
                            ['type' => 'song', 'title' => 'Praise to the Lord'],
                        ], 'confidence' => 0.95],
                    ],
                );
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'Fwd: order of services for sunday 14th June',
            'body_plain' => "Morning order of service\nPraise to the Lord",
            'received_at' => '2026-06-12 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('2026-06-14', $result->date);
        $this->assertSame('2026-06-14', $result->servicePlans[0]->date);
        $this->assertGreaterThan(0.74, $result->confidenceScore, 'A recovered date must not carry the implausibility cap.');
        $this->assertTrue($result->shouldImport);
    }

    #[Test]
    public function a_plausible_extractor_plan_date_is_kept_even_when_it_differs_from_the_email_level_date(): void
    {
        // Multi-date emails are real (Christmas weekend): the Christmas-morning plan's own
        // date must survive even though the email-level extraction resolves the Sunday date.
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Once in royal David\'s city']],
                    confidence: 0.95,
                    services: [
                        ['service' => 'morning', 'date' => '2023-12-24', 'items' => [
                            ['type' => 'song', 'title' => 'Once in royal David\'s city'],
                        ], 'confidence' => 0.95],
                        ['service' => 'other', 'date' => '2023-12-25', 'items' => [
                            ['type' => 'song', 'title' => 'O come all ye faithful'],
                        ], 'confidence' => 0.95],
                    ],
                );
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'Christmas weekend services - Sunday 24 December 2023',
            'body_plain' => "Sunday morning\nOnce in royal David's city\n\nChristmas morning\nO come all ye faithful",
            'received_at' => '2023-12-22 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('2023-12-24', $result->servicePlans[0]->date);
        $this->assertSame('2023-12-25', $result->servicePlans[1]->date);
    }

    #[Test]
    public function the_fallback_only_rewrites_a_plan_date_that_shares_the_email_level_month_and_day(): void
    {
        // A multi-date email: the second plan's extractor date has only the year hallucinated
        // (2023-06-21 for "14 and 21 June 2026"). Rewriting it to the email-level 14 June would
        // import the wrong service; a plan date with a different month-day must hold instead.
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Praise to the Lord']],
                    confidence: 0.95,
                    services: [
                        ['service' => 'morning', 'date' => '2023-06-14', 'items' => [
                            ['type' => 'song', 'title' => 'Praise to the Lord'],
                        ], 'confidence' => 0.95],
                        ['service' => 'evening', 'date' => '2023-06-21', 'items' => [
                            ['type' => 'song', 'title' => 'Abide with me'],
                        ], 'confidence' => 0.95],
                    ],
                );
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'Fwd: orders of service for sunday 14th June',
            'body_plain' => "Morning order (14 June)\nPraise to the Lord\n\nNext week evening (21 June)\nAbide with me",
            'received_at' => '2026-06-12 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('2026-06-14', $result->servicePlans[0]->date, 'Same month-day: year-hallucinated date recovers.');
        $this->assertSame('2023-06-21', $result->servicePlans[1]->date, 'Different month-day: the plan date must not be rewritten.');
        $this->assertLessThanOrEqual(0.74, $result->servicePlans[1]->confidence, 'The unrecovered plan holds for review.');
    }

    #[Test]
    public function a_fully_specified_hyphen_date_still_parses(): void
    {
        // The verse-range fix must only reject two-part hyphenated numbers ("1-7"); a
        // day-month-year hyphen date is a legitimate UK format with no verse-range ambiguity.
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Song one']],
                    confidence: 0.92,
                );
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'OoS 16-03-2026 AM',
            'body_plain' => 'Song one',
            'received_at' => '2026-03-10 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('2026-03-16', $result->date);
    }

    #[Test]
    public function a_bible_verse_range_is_not_mistaken_for_a_numeric_date(): void
    {
        // Second archive eval run: "Bible Reading: Luke 2:1-7" matched the numeric-date
        // regex's hyphen separator as day=1/month=7 → 2025-07-01 for a Christmas email.
        // Hyphenated verse ranges are everywhere in these emails; real numeric dates use
        // slashes (and ISO dates have their own extractor), so "-" is not a date separator.
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'bible_reading', 'title' => 'Luke 2:1-7']],
                    confidence: 0.95,
                );
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'Christmas morning order of service',
            'body_plain' => "Christmas morning\nBible Reading: Luke 2:1-7\nO come all ye faithful",
            'received_at' => '2025-12-23 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertNull($result->date, 'A verse range must not resolve to a date.');
    }

    #[Test]
    public function it_treats_pm_time_hints_as_evening(): void
    {
        $parser = new OosEmailParserService(new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Song one']],
                    confidence: 0.92,
                );
            }
        });

        $email = InboundEmail::factory()->make([
            'subject' => 'Service plan for 16 March',
            'body_plain' => "6pm service\nSong one",
            'received_at' => '2026-03-10 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame(SermonService::Evening, $result->service);
    }

    /**
     * @return array<string, array{subject:string}>
     */
    public static function malformedDateSubjects(): array
    {
        return [
            // "1 Timothy 2:11-15" matches the numeric-date regex as day=11/month=15,
            // which CarbonImmutable::create() would silently overflow into 2027-03-11.
            'month overflow from scripture reference' => ['subject' => 'Reading: 1 Timothy 2:11-15'],
            'impossible day for month' => ['subject' => 'Meeting on 31/02'],
            'non-leap 29 February' => ['subject' => 'Service 29/02/2025'],
            'iso non-existent day' => ['subject' => 'Order of Service 2025-02-31'],
        ];
    }

    #[Test]
    #[DataProvider('malformedDateSubjects')]
    public function it_rejects_calendar_impossible_dates(string $subject): void
    {
        $parser = new OosEmailParserService($this->stubExtractor());

        $email = InboundEmail::factory()->make([
            'subject' => $subject,
            'body_plain' => 'Song one',
            'received_at' => '2026-03-10 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertNull($result->date, $subject);
    }

    #[Test]
    public function it_accepts_a_valid_leap_day(): void
    {
        $parser = new OosEmailParserService($this->stubExtractor());

        $email = InboundEmail::factory()->make([
            'subject' => 'Service 29/02/2024',
            'body_plain' => 'Song one',
            'received_at' => '2024-02-27 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('2024-02-29', $result->date);
    }

    #[Test]
    public function it_holds_a_fully_specified_date_that_falls_on_the_wrong_weekday_and_is_in_the_past(): void
    {
        $parser = new OosEmailParserService($this->stubExtractor());

        // The real "Sunday 5 June 2026" email: 5 June 2026 is actually a Friday and,
        // against the curated corrected received date (2026-07-03), is in the past.
        $email = InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 5th June 2026 AM',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-03 09:00:00',
        ]);

        $result = $parser->parse($email);

        // The parser extracts what the email literally says — it must not auto-correct.
        $this->assertSame('2026-06-05', $result->date);
        $this->assertLessThanOrEqual(0.74, $result->confidenceScore);
        $this->assertFalse($result->shouldImport);
        $this->assertSame('2026-07-05', $result->importMetadata['date_extraction']['suggested_date'] ?? null);
        $this->assertNotEmpty(array_filter(
            $result->importMetadata['warnings'],
            static fn (string $warning): bool => str_contains(strtolower($warning), 'plausib'),
        ));
    }

    #[Test]
    public function it_holds_a_date_far_in_the_future(): void
    {
        $parser = new OosEmailParserService($this->stubExtractor());

        $email = InboundEmail::factory()->make([
            'subject' => 'Order of Service - 2026-08-30 AM',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-03 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('2026-08-30', $result->date);
        $this->assertLessThanOrEqual(0.74, $result->confidenceScore);
        $this->assertFalse($result->shouldImport);
    }

    #[Test]
    public function it_leaves_a_normal_near_future_sunday_untouched(): void
    {
        $parser = new OosEmailParserService($this->stubExtractor());

        // 12 July 2026 is a genuine Sunday, two days after the email arrived.
        $email = InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-10 09:00:00',
        ]);

        $result = $parser->parse($email);

        $this->assertSame('2026-07-12', $result->date);
        $this->assertGreaterThanOrEqual(0.90, $result->confidenceScore);
        $this->assertTrue($result->shouldImport);
        $this->assertNull($result->importMetadata['date_extraction']['suggested_date'] ?? null);
    }

    /**
     * @return array<string, array{receivedAt:string,resolvedDate:string,shouldHold:bool}>
     */
    public static function plausibilityWindowBoundaries(): array
    {
        // Window is [received calendar day, received + max_future_days (14)].
        return [
            'same day' => ['receivedAt' => '2026-07-05 09:00:00', 'resolvedDate' => '2026-07-05', 'shouldHold' => false],
            'plus max future days' => ['receivedAt' => '2026-07-05 09:00:00', 'resolvedDate' => '2026-07-19', 'shouldHold' => false],
            'one day past window' => ['receivedAt' => '2026-07-05 09:00:00', 'resolvedDate' => '2026-07-20', 'shouldHold' => true],
            'day before received' => ['receivedAt' => '2026-07-05 09:00:00', 'resolvedDate' => '2026-07-04', 'shouldHold' => true],
        ];
    }

    #[Test]
    #[DataProvider('plausibilityWindowBoundaries')]
    public function it_enforces_the_received_at_window_boundaries(string $receivedAt, string $resolvedDate, bool $shouldHold): void
    {
        $parser = new OosEmailParserService($this->stubExtractor());

        $email = InboundEmail::factory()->make([
            'subject' => "Order of Service - {$resolvedDate} AM",
            'body_plain' => "Welcome\nSong one",
            'received_at' => $receivedAt,
        ]);

        $result = $parser->parse($email);

        $this->assertSame($resolvedDate, $result->date);

        if ($shouldHold) {
            $this->assertLessThanOrEqual(0.74, $result->confidenceScore, "{$resolvedDate} should hold");
        } else {
            $this->assertGreaterThan(0.74, $result->confidenceScore, "{$resolvedDate} should pass");
        }
    }

    private function stubExtractor(): OosEmailItemExtractor
    {
        return new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Song one']],
                    confidence: 0.95,
                );
            }
        };
    }
}
