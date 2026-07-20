<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use App\Services\Email\OosEmailParserService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosEmailParserServiceTest extends TestCase
{
    #[Test]
    public function it_prefers_plain_text_and_returns_a_high_confidence_typed_parse(): void
    {
        $extractor = new class($this->extraction([$this->plan('morning', '2026-03-15', 0.95, [['type' => 'welcome', 'title' => 'Welcome'], ['type' => 'song', 'title' => 'Before the throne of God above'], ['type' => 'prayer', 'title' => 'Opening prayer'], ['type' => 'bible_reading', 'title' => 'Luke 15:1-32']])])) implements OosEmailItemExtractor
        {
            public string $capturedBody = '';

            public string $capturedReceivedDate = '';

            public int $calls = 0;

            public function __construct(
                private readonly OosEmailItemExtractionResult $result,
            ) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $this->capturedBody = $body;
                $this->capturedReceivedDate = $receivedDate;
                $this->calls++;

                return $this->result;
            }
        };

        $result = (new OosEmailParserService($extractor))->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 15 March 2026 AM',
            'body_plain' => "Welcome\nBefore the throne of God above\nOpening prayer\nLuke 15:1-32",
            'body_html' => '<p>HTML should not be used</p>',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame(1, $extractor->calls);
        $this->assertSame("Welcome\nBefore the throne of God above\nOpening prayer\nLuke 15:1-32", $extractor->capturedBody);
        $this->assertSame('2026-03-10', $extractor->capturedReceivedDate);
        $this->assertSame('2026-03-15', $result->date);
        $this->assertSame(SermonService::Morning, $result->service);
        $this->assertTrue($result->shouldImport);
        $this->assertFalse($result->needsReview);
        $this->assertSame(0.95, $result->confidenceScore);
        $this->assertSame('songs', $result->items[1]['type']);
        $this->assertNull($result->items[1]['openlp_search_title']);
        $this->assertSame('llm', $result->importMetadata['date_extraction']['method']);
        $this->assertSame('llm', $result->importMetadata['service_extraction']['method']);
    }

    #[Test]
    public function it_marks_a_medium_confidence_typed_parse_for_review(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-03-16', 0.85, [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'song', 'title' => 'How deep the Father\'s love for us'],
                ['type' => 'sermon', 'title' => 'Sermon'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Service plan for 16 March',
            'body_plain' => "10.30am service\nWelcome\nHow deep the Father's love for us\nSermon",
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame('2026-03-16', $result->date);
        $this->assertSame(SermonService::Morning, $result->service);
        $this->assertTrue($result->shouldImport);
        $this->assertTrue($result->needsReview);
        $this->assertSame(0.85, $result->confidenceScore);
    }

    #[Test]
    public function it_uses_html_when_plain_text_is_missing(): void
    {
        $extractor = new class($this->extraction([$this->plan('morning', '2026-03-16')])) implements OosEmailItemExtractor
        {
            public string $capturedBody = '';

            public function __construct(
                private readonly OosEmailItemExtractionResult $result,
            ) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $this->capturedBody = $body;

                return $this->result;
            }
        };

        (new OosEmailParserService($extractor))->parse(InboundEmail::factory()->make([
            'subject' => 'OoS 2026-03-16 AM',
            'body_plain' => null,
            'body_html' => '<p>Welcome</p><p>Song one</p><div>Prayer</div>',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame("Welcome\nSong one\nPrayer", $extractor->capturedBody);
    }

    #[Test]
    public function it_holds_a_low_confidence_or_empty_extraction(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('unknown', null, 0.10, []),
        ], ['Could not identify any OoS items.']));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'hello there',
            'body_plain' => 'just checking in',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertNull($result->date);
        $this->assertNull($result->service);
        $this->assertFalse($result->shouldImport);
        $this->assertFalse($result->needsReview);
        $this->assertLessThan(0.75, $result->confidenceScore);
    }

    #[Test]
    public function it_does_not_fall_back_to_regex_date_and_service_extraction(): void
    {
        $parser = $this->parserReturning(new OosEmailItemExtractionResult(
            items: [['type' => 'song', 'title' => 'Song one']],
            confidence: 0.95,
        ));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 15 March 2026 AM',
            'body_plain' => 'Song one',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertNull($result->date);
        $this->assertNull($result->service);
        $this->assertFalse($result->shouldImport);
        $this->assertLessThan(0.75, $result->confidenceScore);
    }

    #[Test]
    public function it_preserves_unknown_llm_item_types_as_other_items(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-03-16', 0.92, [
                ['type' => 'communion', 'title' => 'Communion'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'OoS 2026-03-16 AM',
            'body_plain' => 'Communion',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame('custom', $result->items[0]['type']);
        $this->assertSame('other', $result->items[0]['metadata']['email_type']);
    }

    /**
     * @return array<string, array{subject:string}>
     */
    public static function informalDateSubjects(): array
    {
        return [
            'ISO' => ['subject' => 'OoS 2026-03-16 AM'],
            'UK numeric' => ['subject' => 'OoS 16/03/2026 AM'],
            'textual day first' => ['subject' => 'OoS Sunday 16th March AM'],
            'textual month first' => ['subject' => 'OoS March 16, 2026 AM'],
            'fully specified hyphen' => ['subject' => 'OoS 16-03-2026 AM'],
        ];
    }

    #[Test]
    #[DataProvider('informalDateSubjects')]
    public function it_accepts_the_llm_date_for_every_existing_informal_email_fixture(string $subject): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-03-16'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => $subject,
            'body_plain' => 'Song one',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame('2026-03-16', $result->date, $subject);
    }

    #[Test]
    public function it_does_not_correct_an_implausible_llm_date_with_email_regexes(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2023-06-14', 0.95, [
                ['type' => 'song', 'title' => 'Praise to the Lord'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Fwd: order of services for sunday 14th June',
            'body_plain' => "Morning order of service\nPraise to the Lord",
            'received_at' => '2026-06-12 09:00:00',
        ]));

        $this->assertSame('2023-06-14', $result->date);
        $this->assertLessThanOrEqual(0.74, $result->confidenceScore);
        $this->assertFalse($result->shouldImport);
    }

    #[Test]
    public function it_keeps_each_plans_own_date_for_multi_date_emails(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2023-12-24', 0.95, [
                ['type' => 'song', 'title' => 'Once in royal David\'s city'],
            ]),
            $this->plan('other', '2023-12-25', 0.95, [
                ['type' => 'song', 'title' => 'O come all ye faithful'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Christmas weekend services - Sunday 24 December 2023',
            'body_plain' => "Sunday morning\nOnce in royal David's city\n\nChristmas morning\nO come all ye faithful",
            'received_at' => '2023-12-22 09:00:00',
        ]));

        $this->assertSame('2023-12-24', $result->servicePlans[0]->date);
        $this->assertSame('2023-12-25', $result->servicePlans[1]->date);
    }

    #[Test]
    public function it_holds_each_out_of_window_plan_without_rewriting_its_date(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2023-06-14'),
            $this->plan('evening', '2023-06-21'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Fwd: orders of service for sunday 14th June',
            'body_plain' => "Morning order (14 June)\nPraise to the Lord\n\nNext week evening (21 June)\nAbide with me",
            'received_at' => '2026-06-12 09:00:00',
        ]));

        $this->assertSame('2023-06-14', $result->servicePlans[0]->date);
        $this->assertSame('2023-06-21', $result->servicePlans[1]->date);
        $this->assertLessThanOrEqual(0.74, $result->servicePlans[0]->confidence);
        $this->assertLessThanOrEqual(0.74, $result->servicePlans[1]->confidence);
    }

    #[Test]
    public function a_bible_verse_range_is_only_input_to_the_llm_and_not_parsed_as_a_date_locally(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', null, 0.95, [
                ['type' => 'bible_reading', 'title' => 'Luke 2:1-7'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Christmas morning order of service',
            'body_plain' => "Christmas morning\nBible Reading: Luke 2:1-7\nO come all ye faithful",
            'received_at' => '2025-12-23 09:00:00',
        ]));

        $this->assertNull($result->date);
        $this->assertFalse($result->shouldImport);
    }

    #[Test]
    public function it_uses_the_llm_service_for_a_pm_email(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('evening', '2026-03-16'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Service plan for 16 March',
            'body_plain' => "6pm service\nSong one",
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame(SermonService::Evening, $result->service);
    }

    /**
     * @return array<string, array{subject:string, extractedDate:string}>
     */
    public static function malformedDateExtractions(): array
    {
        return [
            'month overflow from scripture reference' => [
                'subject' => 'Reading: 1 Timothy 2:11-15',
                'extractedDate' => '2026-15-11',
            ],
            'impossible day for month' => [
                'subject' => 'Meeting on 31/02',
                'extractedDate' => '2026-02-31',
            ],
            'non-leap 29 February' => [
                'subject' => 'Service 29/02/2025',
                'extractedDate' => '2025-02-29',
            ],
            'iso non-existent day' => [
                'subject' => 'Order of Service 2025-02-31',
                'extractedDate' => '2025-02-31',
            ],
        ];
    }

    #[Test]
    #[DataProvider('malformedDateExtractions')]
    public function it_rejects_calendar_impossible_llm_dates(string $subject, string $extractedDate): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', $extractedDate),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => $subject,
            'body_plain' => 'Song one',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertNull($result->date, $subject);
        $this->assertFalse($result->shouldImport);
    }

    #[Test]
    public function it_accepts_a_valid_llm_leap_day(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2024-02-29'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Service 29/02/2024',
            'body_plain' => 'Song one',
            'received_at' => '2024-02-27 09:00:00',
        ]));

        $this->assertSame('2024-02-29', $result->date);
    }

    #[Test]
    public function it_rejects_a_service_outside_the_enum(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('late', '2026-03-15'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 15 March 2026',
            'body_plain' => 'Song one',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertNull($result->service);
        $this->assertFalse($result->shouldImport);
        $this->assertLessThan(0.75, $result->confidenceScore);
    }

    #[Test]
    public function it_holds_a_date_on_the_wrong_weekday_and_in_the_past(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-06-05'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 5th June 2026 AM',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-03 09:00:00',
        ]));

        $this->assertSame('2026-06-05', $result->date);
        $this->assertLessThanOrEqual(0.74, $result->confidenceScore);
        $this->assertFalse($result->shouldImport);
        $this->assertSame('2026-07-05', $result->importMetadata['date_extraction']['suggested_date']);
        $this->assertNotEmpty(array_filter(
            $result->importMetadata['warnings'],
            static fn (string $warning): bool => str_contains(strtolower($warning), 'plausib'),
        ));
    }

    #[Test]
    public function it_holds_a_date_far_in_the_future(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-08-30'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - 2026-08-30 AM',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-03 09:00:00',
        ]));

        $this->assertSame('2026-08-30', $result->date);
        $this->assertLessThanOrEqual(0.74, $result->confidenceScore);
        $this->assertFalse($result->shouldImport);
    }

    #[Test]
    public function it_leaves_a_normal_near_future_sunday_untouched(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-07-12'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertSame('2026-07-12', $result->date);
        $this->assertGreaterThanOrEqual(0.90, $result->confidenceScore);
        $this->assertTrue($result->shouldImport);
        $this->assertNull($result->importMetadata['date_extraction']['suggested_date']);
    }

    /**
     * @return array<string, array{receivedAt:string,resolvedDate:string,shouldHold:bool}>
     */
    public static function plausibilityWindowBoundaries(): array
    {
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
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', $resolvedDate),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => "Order of Service - {$resolvedDate} AM",
            'body_plain' => "Welcome\nSong one",
            'received_at' => $receivedAt,
        ]));

        $this->assertSame($resolvedDate, $result->date);

        if ($shouldHold) {
            $this->assertLessThanOrEqual(0.74, $result->confidenceScore, "{$resolvedDate} should hold");

            return;
        }

        $this->assertGreaterThan(0.74, $result->confidenceScore, "{$resolvedDate} should pass");
    }

    private function parserReturning(OosEmailItemExtractionResult $result): OosEmailParserService
    {
        return new OosEmailParserService(new class($result) implements OosEmailItemExtractor
        {
            public function __construct(
                private readonly OosEmailItemExtractionResult $result,
            ) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return $this->result;
            }
        });
    }

    /**
     * @param  list<array{service:?string,date:?string,items:array<int,array{type:string,title:string}>,confidence:float}>  $services
     * @param  list<string>  $notes
     */
    private function extraction(array $services, array $notes = []): OosEmailItemExtractionResult
    {
        $items = [];

        foreach ($services as $service) {
            $items = array_merge($items, $service['items']);
        }

        $confidence = $services === []
            ? 0.0
            : round(array_sum(array_column($services, 'confidence')) / count($services), 2);

        return new OosEmailItemExtractionResult(
            items: $items,
            confidence: $confidence,
            notes: $notes,
            services: $services,
        );
    }

    /**
     * @param  array<int, array{type:string,title:string}>|null  $items
     * @return array{service:string,date:?string,items:array<int,array{type:string,title:string}>,confidence:float}
     */
    private function plan(
        string $service,
        ?string $date,
        float $confidence = 0.95,
        ?array $items = null,
    ): array {
        return [
            'service' => $service,
            'date' => $date,
            'items' => $items ?? [['type' => 'song', 'title' => 'Song one']],
            'confidence' => $confidence,
        ];
    }
}
