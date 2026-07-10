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
