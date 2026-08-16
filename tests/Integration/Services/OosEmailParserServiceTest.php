<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Contracts\AdjudicatingOosEmailItemExtractor;
use App\Contracts\CorrectiveOosEmailItemExtractor;
use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchService\ServiceItemTitleCleaner;
use App\Services\Email\ExistingEmailImportLookup;
use App\Services\Email\OosEmailParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosEmailParserServiceTest extends TestCase
{
    use RefreshDatabase;

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

        $result = (new OosEmailParserService($extractor, new ExistingEmailImportLookup, app(ServiceItemTitleCleaner::class)))->parse(InboundEmail::factory()->make([
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
            $this->plan('morning', '2026-03-15', 0.85, [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'song', 'title' => 'How deep the Father\'s love for us'],
                ['type' => 'sermon', 'title' => 'Sermon'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Service plan for 15 March',
            'body_plain' => "10.30am service\nWelcome\nHow deep the Father's love for us\nSermon",
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame('2026-03-15', $result->date);
        $this->assertSame(SermonService::Morning, $result->service);
        $this->assertFalse($result->shouldImport);
        $this->assertTrue($result->needsReview);
        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $result->disposition);
        $this->assertSame(0.85, $result->confidenceScore);
    }

    #[Test]
    public function it_uses_html_when_plain_text_is_missing(): void
    {
        $extractor = new class($this->extraction([$this->plan('morning', '2026-03-15')])) implements OosEmailItemExtractor
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

        (new OosEmailParserService($extractor, new ExistingEmailImportLookup, app(ServiceItemTitleCleaner::class)))->parse(InboundEmail::factory()->make([
            'subject' => 'OoS 2026-03-15 AM',
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
        $this->assertTrue($result->needsReview);
        $this->assertSame(OosEmailParseDisposition::InvalidExtraction, $result->disposition);
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
            $this->plan('morning', '2026-03-15', 0.92, [
                ['type' => 'communion', 'title' => 'Communion'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'OoS 2026-03-15 AM',
            'body_plain' => 'Communion',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame('custom', $result->items[0]['type']);
        $this->assertSame('other', $result->items[0]['metadata']['email_type']);
    }

    #[Test]
    public function it_cleans_the_item_title_while_keeping_the_email_line_as_the_source_title(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-07-12', 0.95, [
                ['type' => 'notices', 'title' => 'Notices (see above)'],
                ['type' => 'children', 'title' => 'Family Talk – “Joel” (see PP)'],
                ['type' => 'bible_reading', 'title' => 'Bible Reading: Joshua 5:13-6:27'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'OoS Sunday 12 July 2026 AM',
            'body_plain' => "Notices (see above)\nFamily Talk\nBible Reading: Joshua 5:13-6:27",
            'received_at' => '2026-07-07 09:00:00',
        ]));

        $this->assertSame('Notices', $result->items[0]['title']);
        $this->assertSame('Family Talk – “Joel”', $result->items[1]['title']);
        $this->assertSame('Joshua 5:13-6:27', $result->items[2]['title']);

        // The raw line survives untouched: it is what ChurchServiceItemSyncService matches
        // an email item against an OpenLP export on.
        $this->assertSame('Notices (see above)', $result->items[0]['source_title']);
        $this->assertSame('Family Talk – “Joel” (see PP)', $result->items[1]['source_title']);
        $this->assertSame('Bible Reading: Joshua 5:13-6:27', $result->items[2]['source_title']);
    }

    #[Test]
    public function it_records_the_passage_a_reading_names(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-07-12', 0.95, [
                ['type' => 'bible_reading', 'title' => 'Bible Reading: Joshua 5:13-6:27'],
                ['type' => 'bible_reading', 'title' => 'Bible Reading'],
            ]),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'OoS Sunday 12 July 2026 AM',
            'body_plain' => 'Bible Reading: Joshua 5:13-6:27',
            'received_at' => '2026-07-07 09:00:00',
        ]));

        $this->assertSame('Joshua 5:13-6:27', $result->items[0]['metadata']['reading_reference']);
        // A reading naming no passage records none rather than an empty key.
        $this->assertNull($result->items[1]['metadata']);
    }

    /**
     * @return array<string, array{subject:string}>
     */
    public static function informalDateSubjects(): array
    {
        return [
            'ISO' => ['subject' => 'OoS 2026-03-15 AM'],
            'UK numeric' => ['subject' => 'OoS 15/03/2026 AM'],
            'textual day first' => ['subject' => 'OoS Sunday 15th March AM'],
            'textual month first' => ['subject' => 'OoS March 15, 2026 AM'],
            'fully specified hyphen' => ['subject' => 'OoS 15-03-2026 AM'],
        ];
    }

    #[Test]
    #[DataProvider('informalDateSubjects')]
    public function it_accepts_the_llm_date_for_every_existing_informal_email_fixture(string $subject): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-03-15'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => $subject,
            'body_plain' => 'Song one',
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame('2026-03-15', $result->date, $subject);
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
    public function it_holds_each_implausible_plan_without_rewriting_its_date(): void
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
            $this->plan('evening', '2026-03-15'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Service plan for 15 March',
            'body_plain' => "6pm service\nSong one",
            'received_at' => '2026-03-10 09:00:00',
        ]));

        $this->assertSame(SermonService::Evening, $result->service);
    }

    #[Test]
    public function a_single_plan_can_use_strong_evening_evidence_from_the_subject(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('evening', '2026-03-15'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Afternoon service - 5pm',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-03-14 09:00:00',
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
    public function it_holds_a_date_that_is_not_a_sunday_and_suggests_the_nearest_one(): void
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
        $this->assertSame('2026-06-07', $result->importMetadata['date_extraction']['suggested_date']);
        $this->assertNotEmpty(array_filter(
            $result->importMetadata['warnings'],
            static fn (string $warning): bool => str_contains(strtolower($warning), 'plausib'),
        ));
    }

    #[Test]
    public function it_imports_a_past_sunday_entered_by_hand_from_the_archive(): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-07-12'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Manual entry',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-26 12:00:00',
        ]));

        $this->assertSame('2026-07-12', $result->date);
        $this->assertGreaterThanOrEqual(0.90, $result->confidenceScore);
        $this->assertTrue($result->shouldImport);
        $this->assertTrue($result->importMetadata['date_extraction']['plausible']);
    }

    #[Test]
    public function it_imports_a_sunday_far_beyond_the_usual_planning_horizon(): void
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
        $this->assertTrue($result->shouldImport);
    }

    #[Test]
    public function it_holds_a_sunday_that_another_email_has_already_imported(): void
    {
        ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => SermonService::Morning,
            'source' => 'email',
            'import_metadata' => ['source_message_id' => 'the-first-email@crockenhill.org'],
        ]);

        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-07-12'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'message_id' => 'a-later-correction@crockenhill.org',
            'subject' => 'Corrected order of service - Sunday 12 July 2026',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertSame('2026-07-12', $result->date);
        $this->assertLessThanOrEqual(0.74, $result->confidenceScore);
        $this->assertFalse($result->shouldImport);
        $this->assertNotEmpty(array_filter(
            $result->importMetadata['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'already has an order of service'),
        ));
    }

    #[Test]
    public function it_only_treats_another_email_for_the_same_service_slot_as_a_duplicate(): void
    {
        ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => SermonService::Evening,
            'source' => 'email',
            'import_metadata' => ['source_message_id' => 'the-evening-email@crockenhill.org'],
        ]);

        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-07-12'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'message_id' => 'the-morning-email@crockenhill.org',
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertTrue($result->shouldImport);
        $this->assertTrue($result->importMetadata['date_extraction']['plausible']);
    }

    #[Test]
    public function it_does_not_treat_its_own_earlier_import_as_a_duplicate(): void
    {
        ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => SermonService::Morning,
            'source' => 'email',
            'import_metadata' => ['source_message_id' => 'the-only-email@crockenhill.org'],
        ]);

        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-07-12'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'message_id' => 'the-only-email@crockenhill.org',
            'subject' => 'Order of Service - Sunday 12 July 2026',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertTrue($result->shouldImport);
        $this->assertTrue($result->importMetadata['date_extraction']['plausible']);
    }

    #[Test]
    public function it_does_not_treat_a_livestream_service_as_an_email_import(): void
    {
        ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => SermonService::Morning,
            'source' => 'livestream',
            'import_metadata' => ['parse_method' => 'livestream_detection'],
        ]);

        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', '2026-07-12'),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'message_id' => 'the-plan-email@crockenhill.org',
            'subject' => 'Order of Service - Sunday 12 July 2026',
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertTrue($result->shouldImport);
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

    #[Test]
    public function it_accepts_a_headerless_single_order_when_every_item_is_grounded_in_source_lines(): void
    {
        $body = "Welcome\nAmazing Grace\nSermon";
        $parser = $this->parserReturning(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => [],
                'items' => [
                    $this->groundedItem('welcome', 'Welcome', 1),
                    $this->groundedItem('song', 'Amazing Grace', 2),
                    $this->groundedItem('sermon', 'Sermon', 3),
                ],
                'confidence' => 0.95,
            ]],
            serviceCount: 1,
            provenanceComplete: true,
        ));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => $body,
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertSame(OosEmailParseDisposition::AutoImportable, $result->disposition);
        $this->assertTrue($result->shouldImport);
        $this->assertFalse($result->needsReview);
        $this->assertSame(['Welcome', 'Amazing Grace', 'Sermon'], array_column($result->items, 'source_title'));
    }

    #[Test]
    public function it_allows_non_item_context_inside_a_service_sequence(): void
    {
        $body = "Welcome\nJohn Smith\nhttps://example.com/slides\nAmazing Grace\nSermon";
        $parser = $this->parserReturning(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => [],
                'items' => [
                    $this->groundedItem('welcome', 'Welcome', 1),
                    $this->groundedItem('song', 'Amazing Grace', 4),
                    $this->groundedItem('sermon', 'Sermon', 5),
                ],
                'confidence' => 0.95,
            ]],
            serviceCount: 1,
            ignoredLines: [
                ['line_id' => 2, 'reason' => 'context'],
                ['line_id' => 3, 'reason' => 'context'],
            ],
            provenanceComplete: true,
        ));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => $body,
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertSame(OosEmailParseDisposition::AutoImportable, $result->disposition);
    }

    /**
     * "Opening prayer" sits between two extracted items, so ignoring it may mean the model
     * dropped an item and the plan must not auto-import. It is still only a bookkeeping claim —
     * the rule cannot tell a dropped item from a deliberate aside — so since F65 the plan is held
     * for review, where a human can accept it, rather than rejected as an invalid extraction that
     * no review can reach.
     */
    #[Test]
    public function it_holds_an_item_like_line_ignored_inside_a_service_sequence_for_review(): void
    {
        $body = "Welcome\nOpening prayer\nAmazing Grace\nSermon";
        $parser = $this->parserReturning(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => [],
                'items' => [
                    $this->groundedItem('welcome', 'Welcome', 1),
                    $this->groundedItem('song', 'Amazing Grace', 3),
                    $this->groundedItem('sermon', 'Sermon', 4),
                ],
                'confidence' => 0.95,
            ]],
            serviceCount: 1,
            ignoredLines: [['line_id' => 2, 'reason' => 'context']],
            provenanceComplete: true,
        ));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => $body,
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $result->disposition);
        $this->assertTrue($result->servicePlans[0]->isManuallyImportable());
        $this->assertFalse($result->servicePlans[0]->isAutoImportable());
    }

    /**
     * F65. `service_evidence_line_ids` is optional for a single-plan email and is never written to
     * a service, so two attempts that extracted the identical order must count as agreeing even
     * when only one of them bothered to cite a boundary line. Treating that as a structural
     * disagreement held the plan permanently, and no review could resolve a conflict that did not
     * exist.
     */
    #[Test]
    public function two_attempts_differing_only_in_evidence_citations_reach_consensus(): void
    {
        $body = "Morning Service\nWelcome\nAmazing Grace\nSermon";
        $items = [
            $this->groundedItem('welcome', 'Welcome', 2),
            $this->groundedItem('song', 'Amazing Grace', 3),
            $this->groundedItem('sermon', 'Sermon', 4),
        ];
        $extraction = fn (array $evidenceLineIds): OosEmailItemExtractionResult => new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.80,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => $evidenceLineIds,
                'items' => $items,
                'confidence' => 0.80,
            ]],
            serviceCount: 1,
            ignoredLines: $evidenceLineIds === [] ? [['line_id' => 1, 'reason' => 'context']] : [],
            provenanceComplete: true,
        );
        $extractor = new class($extraction([1]), $extraction([])) implements CorrectiveOosEmailItemExtractor
        {
            public function __construct(
                private readonly OosEmailItemExtractionResult $initial,
                private readonly OosEmailItemExtractionResult $corrected,
            ) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return $this->initial;
            }

            public function correct(
                string $subject,
                string $body,
                string $receivedDate,
                OosEmailItemExtractionResult $previousExtraction,
                array $validationFailures,
            ): OosEmailItemExtractionResult {
                return $this->corrected;
            }
        };

        $result = (new OosEmailParserService(
            $extractor,
            new ExistingEmailImportLookup,
            app(ServiceItemTitleCleaner::class),
        ))->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => $body,
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertTrue($result->consensus);
        $this->assertSame(OosEmailParseDisposition::AutoImportable, $result->disposition);
    }

    /**
     * Adjudication is diagnostic, not a gate. It may pick between two disagreeing candidates and
     * the parser adopts that order, but the plan stays `ReviewRequired` and `consensus` stays
     * false — a third call shown both answers is not two independent attempts agreeing (HIR-D6).
     */
    #[Test]
    public function a_resolved_adjudication_stays_held_and_never_claims_consensus(): void
    {
        $body = "Welcome\nAmazing Grace";
        $extraction = fn (string $secondType): OosEmailItemExtractionResult => new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.80,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => [],
                'items' => [
                    $this->groundedItem('welcome', 'Welcome', 1),
                    $this->groundedItem($secondType, 'Amazing Grace', 2),
                ],
                'confidence' => 0.80,
            ]],
            serviceCount: 1,
            provenanceComplete: true,
        );
        $initial = $extraction('song');
        $corrected = $extraction('prayer');
        $thirdInterpretation = $extraction('sermon');
        $extractor = new class($initial, $corrected) implements AdjudicatingOosEmailItemExtractor
        {
            public int $adjudicationCalls = 0;

            /** @var list<string> */
            public array $categories = [];

            public OosEmailItemExtractionResult $adjudicated;

            public function __construct(
                private readonly OosEmailItemExtractionResult $initial,
                private readonly OosEmailItemExtractionResult $corrected,
            ) {
                $this->adjudicated = $initial;
            }

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return $this->initial;
            }

            public function correct(
                string $subject,
                string $body,
                string $receivedDate,
                OosEmailItemExtractionResult $previousExtraction,
                array $validationFailures,
            ): OosEmailItemExtractionResult {
                return $this->corrected;
            }

            public function adjudicate(
                string $subject,
                string $body,
                string $receivedDate,
                OosEmailItemExtractionResult $initialExtraction,
                OosEmailItemExtractionResult $correctedExtraction,
                array $disagreementCategories,
            ): OosEmailItemExtractionResult {
                $this->adjudicationCalls++;
                $this->categories = $disagreementCategories;

                return $this->adjudicated;
            }
        };

        $result = (new OosEmailParserService(
            $extractor,
            new ExistingEmailImportLookup,
            app(ServiceItemTitleCleaner::class),
        ))->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => $body,
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertSame(1, $extractor->adjudicationCalls);
        $this->assertSame(['item_type_or_order'], $extractor->categories);
        $this->assertCount(3, $result->extractionAttempts);
        $this->assertSame(['item_type_or_order'], $result->extractionAttempts[1]['disagreement_categories']);
        // Which label flipped, not merely that one did: the category alone cannot distinguish a
        // reordered service from two calls arguing over `song` and `prayer` for the same line.
        $this->assertSame(
            ['song→prayer' => 1],
            $result->extractionAttempts[1]['disagreement_item_type_changes'],
        );
        $this->assertSame('matched_candidate', $result->extractionAttempts[2]['adjudication']);
        // The adjudicated order is adopted, so a reviewer sees the resolution...
        $this->assertTrue($result->adjudicated);
        $this->assertSame('songs', $result->items[1]['type']);
        // ...but it buys no import credit: still held, still not consensus.
        $this->assertFalse($result->consensus);
        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $result->disposition);
        $this->assertSame(
            [OosEmailPlanHoldReason::AttemptDisagreement, OosEmailPlanHoldReason::LowConfidence],
            $result->servicePlans[0]->holdReasons,
        );

        $extractor->adjudicated = $thirdInterpretation;
        $unresolved = (new OosEmailParserService(
            $extractor,
            new ExistingEmailImportLookup,
            app(ServiceItemTitleCleaner::class),
        ))->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => $body,
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertFalse($unresolved->consensus);
        $this->assertFalse($unresolved->adjudicated);
        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $unresolved->disposition);
        $this->assertCount(3, $unresolved->extractionAttempts);
        $this->assertSame('third_interpretation', $unresolved->extractionAttempts[2]['adjudication']);
        // A third interpretation is discarded: the selected extraction is the corrected attempt.
        $this->assertSame('prayer', $unresolved->items[1]['section_type']);
    }

    /**
     * The gate a resolved adjudication must not open: consensus lets a sub-0.90 plan import
     * unattended, so if adjudication ever set it, every disagreeing plan in the 0.75–0.89 band
     * would import without a human.
     */
    #[Test]
    public function only_two_independent_agreeing_attempts_can_auto_import_below_the_threshold(): void
    {
        $body = "Welcome\nAmazing Grace";
        $agreed = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.80,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => [],
                'items' => [
                    $this->groundedItem('welcome', 'Welcome', 1),
                    $this->groundedItem('song', 'Amazing Grace', 2),
                ],
                'confidence' => 0.80,
            ]],
            serviceCount: 1,
            provenanceComplete: true,
        );

        $result = (new OosEmailParserService(
            new class($agreed) implements CorrectiveOosEmailItemExtractor
            {
                public function __construct(private readonly OosEmailItemExtractionResult $agreed) {}

                public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
                {
                    return $this->agreed;
                }

                public function correct(
                    string $subject,
                    string $body,
                    string $receivedDate,
                    OosEmailItemExtractionResult $previousExtraction,
                    array $validationFailures,
                ): OosEmailItemExtractionResult {
                    return $this->agreed;
                }
            },
            new ExistingEmailImportLookup,
            app(ServiceItemTitleCleaner::class),
        ))->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => $body,
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertTrue($result->consensus);
        $this->assertFalse($result->adjudicated);
        $this->assertSame(OosEmailParseDisposition::AutoImportable, $result->disposition);
        $this->assertSame([], $result->servicePlans[0]->holdReasons);
    }

    /**
     * Content reasons are a subset of all reasons, and the parser asked the validator for "all"
     * while calling the parameter `$structuralReasons` — so every invalid extraction was also
     * counted as a bookkeeping hold. The archive census then reported 21 bookkeeping holds that
     * were really content failures, 11 of them with no line-accounting finding at all.
     */
    #[Test]
    public function a_plan_whose_only_findings_are_content_ones_is_not_also_a_bookkeeping_hold(): void
    {
        $result = $this->parserReturning(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => [],
                'items' => [
                    $this->groundedItem('welcome', 'Welcome', 1),
                    $this->groundedItem('song', 'Amazing Grace', 2),
                    $this->groundedItem('song', 'Amazing Grace', 2),
                ],
                'confidence' => 0.95,
            ]],
            serviceCount: 1,
            provenanceComplete: true,
        ))->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => "Welcome\nAmazing Grace",
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertContains(OosEmailPlanHoldReason::ContentInvalid, $result->servicePlans[0]->holdReasons);
        $this->assertNotContains(OosEmailPlanHoldReason::Bookkeeping, $result->servicePlans[0]->holdReasons);
    }

    #[Test]
    public function a_bookkeeping_hold_records_the_line_and_the_items_it_sits_between(): void
    {
        $result = $this->parserReturning(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => [],
                'items' => [
                    $this->groundedItem('welcome', 'Welcome', 1),
                    $this->groundedItem('sermon', 'The Sermon', 3),
                ],
                'confidence' => 0.95,
            ]],
            serviceCount: 1,
            ignoredLines: [['line_id' => 2, 'reason' => 'context']],
            provenanceComplete: true,
        ))->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => "Welcome\nReading: Ezra 3:1-6\nSermon",
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $plan = $result->servicePlans[0];

        $this->assertContains(OosEmailPlanHoldReason::Bookkeeping, $plan->holdReasons);
        $this->assertSame([[
            'rule' => 'line_ignored_inside_item_span',
            'line_id' => 2,
            'plan_index' => 0,
            'preceding_item_type' => 'welcome',
            'following_item_type' => 'sermon',
        ]], $plan->sourceProvenance['structural_findings']);
    }

    #[Test]
    public function it_retries_the_real_july_twelfth_shape_when_diagnostics_and_service_plans_disagree(): void
    {
        $body = $this->julyTwelfthBody();
        $corrected = $this->julyTwelfthExtraction($body);
        $initialServices = $corrected->services;
        $initialServices[] = [
            'service' => 'other',
            'date' => null,
            'service_evidence_line_ids' => [$this->lineId($body, 'Notices')],
            'items' => $this->itemsForSection($body, 'Notices', 'Sunday morning'),
            'confidence' => 0.45,
        ];
        $initial = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.72,
            notes: ['The email contains a morning and evening order.'],
            services: $initialServices,
            serviceCount: 2,
            provenanceComplete: true,
        );
        $extractor = new class($initial, $corrected) implements CorrectiveOosEmailItemExtractor
        {
            public int $extractCalls = 0;

            public int $correctionCalls = 0;

            public function __construct(
                private readonly OosEmailItemExtractionResult $initial,
                private readonly OosEmailItemExtractionResult $corrected,
            ) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $this->extractCalls++;

                return $this->initial;
            }

            public function correct(
                string $subject,
                string $body,
                string $receivedDate,
                OosEmailItemExtractionResult $previousExtraction,
                array $validationFailures,
            ): OosEmailItemExtractionResult {
                $this->correctionCalls++;

                return $this->corrected;
            }
        };
        $parser = new OosEmailParserService(
            $extractor,
            new ExistingEmailImportLookup,
            app(ServiceItemTitleCleaner::class),
        );

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Manual entry',
            'body_plain' => $body,
            'received_at' => '2026-07-26 12:52:15',
        ]));

        $this->assertSame(1, $extractor->extractCalls);
        $this->assertSame(1, $extractor->correctionCalls);
        $this->assertCount(2, $result->servicePlans);
        $this->assertSame(2, count($result->extractionAttempts));
        $this->assertSame(12, count($result->servicePlans[0]->items));
        $this->assertSame(10, count($result->servicePlans[1]->items));
        $this->assertSame('Family Talk – “Joel” (see PP)', $result->servicePlans[0]->items[4]['source_title']);
        $this->assertSame('Family Talk – “Joel”', $result->servicePlans[0]->items[4]['title']);
        $this->assertSame('Bible Reading: Joshua 5:13-6:27', $result->servicePlans[0]->items[6]['source_title']);
        $this->assertSame('Joshua 5:13-6:27', $result->servicePlans[0]->items[6]['title']);
        $this->assertNotContains(SermonService::Other, array_map(
            static fn ($plan) => $plan->service,
            $result->servicePlans,
        ));
    }

    #[Test]
    public function it_holds_a_parse_when_a_corrective_retry_still_merges_separate_item_lines(): void
    {
        $body = "Welcome\n\nSermon\n\nAmazing Grace\n\nClosing prayer";
        $invalid = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            services: [[
                'service' => 'morning',
                'date' => '2026-07-12',
                'service_evidence_line_ids' => [],
                'items' => [
                    $this->groundedItem('welcome', 'Welcome', 1),
                    $this->groundedItem('sermon', 'Sermon / Amazing Grace', [3, 5], continuation: true),
                    $this->groundedItem('prayer', 'Closing prayer', 7),
                ],
                'confidence' => 0.95,
            ]],
            serviceCount: 1,
            provenanceComplete: true,
        );
        $extractor = new class($invalid) implements CorrectiveOosEmailItemExtractor
        {
            public int $correctionCalls = 0;

            public function __construct(
                private readonly OosEmailItemExtractionResult $result,
            ) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return $this->result;
            }

            public function correct(
                string $subject,
                string $body,
                string $receivedDate,
                OosEmailItemExtractionResult $previousExtraction,
                array $validationFailures,
            ): OosEmailItemExtractionResult {
                $this->correctionCalls++;

                return $this->result;
            }
        };
        $parser = new OosEmailParserService(
            $extractor,
            new ExistingEmailImportLookup,
            app(ServiceItemTitleCleaner::class),
        );

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order of Service - Sunday 12 July 2026 AM',
            'body_plain' => $body,
            'received_at' => '2026-07-10 09:00:00',
        ]));

        $this->assertSame(1, $extractor->correctionCalls);
        $this->assertSame(OosEmailParseDisposition::InvalidExtraction, $result->disposition);
        $this->assertFalse($result->shouldImport);
        $this->assertTrue($result->needsReview);
        $this->assertNotEmpty($result->validationReasons);
    }

    /**
     * @return array<string, array{resolvedDate:string,shouldHold:bool}>
     */
    public static function weekdayBoundaries(): array
    {
        return [
            'sunday' => ['resolvedDate' => '2026-07-05', 'shouldHold' => false],
            'the sunday before' => ['resolvedDate' => '2026-06-28', 'shouldHold' => false],
            'monday after' => ['resolvedDate' => '2026-07-06', 'shouldHold' => true],
            'saturday before' => ['resolvedDate' => '2026-07-04', 'shouldHold' => true],
        ];
    }

    #[Test]
    #[DataProvider('weekdayBoundaries')]
    public function it_holds_every_resolved_date_that_is_not_a_sunday(string $resolvedDate, bool $shouldHold): void
    {
        $parser = $this->parserReturning($this->extraction([
            $this->plan('morning', $resolvedDate),
        ]));

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => "Order of Service - {$resolvedDate} AM",
            'body_plain' => "Welcome\nSong one",
            'received_at' => '2026-07-01 09:00:00',
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
        }, new ExistingEmailImportLookup, app(ServiceItemTitleCleaner::class));
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

    /**
     * @param  int|list<int>  $sourceLineIds
     * @return array{type:string,title:string,source_line_ids:list<int>,continuation:bool}
     */
    private function groundedItem(
        string $type,
        string $title,
        int|array $sourceLineIds,
        bool $continuation = false,
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'source_line_ids' => is_int($sourceLineIds) ? [$sourceLineIds] : $sourceLineIds,
            'continuation' => $continuation,
        ];
    }

    private function julyTwelfthBody(): string
    {
        return <<<'TEXT'
Sunday 12th July 2026

Notices

Refreshments
Eve service – 6pm – 1 Tim.3:1-7
Monday - QCM @ 7:30pm
HG’s, BB, CC.
Wed – Slade @ 7:30pm (start 8pm) – Roman Catholicism (see slide)
Next Sun 10, 10:30 and 6.

Sunday morning

Welcome

Notices (see above)

98 Sing to God

Prayer

Family Talk – “Joel” (see PP)

NIP ‘Holy, Spirit, living breath of God’

Bible Reading: Joshua 5:13-6:27

Prayer

548 ‘How sure the Scriptures are’

Sermon

NIP On the cross he took

Closing prayer

Sunday evening

Welcome

Call to worship

Opening prayer

146 ‘I’ll praise my maker’

Bible Reading: 1 Timothy 3:1-17

Sermon

NIP ‘Restore O Lord

Prayers

765 ‘My times are in your hand’

Closing prayer
TEXT;
    }

    private function julyTwelfthExtraction(string $body): OosEmailItemExtractionResult
    {
        return new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            notes: ['Two service orders found.'],
            services: [
                [
                    'service' => 'morning',
                    'date' => '2026-07-12',
                    'service_evidence_line_ids' => [$this->lineId($body, 'Sunday morning')],
                    'items' => $this->itemsForSection($body, 'Sunday morning', 'Sunday evening'),
                    'confidence' => 0.95,
                ],
                [
                    'service' => 'evening',
                    'date' => '2026-07-12',
                    'service_evidence_line_ids' => [$this->lineId($body, 'Sunday evening')],
                    'items' => $this->itemsForSection($body, 'Sunday evening'),
                    'confidence' => 0.95,
                ],
            ],
            serviceCount: 2,
            ignoredLines: array_map(
                static fn (int $lineId): array => ['line_id' => $lineId, 'reason' => 'context'],
                $this->lineIdsBefore($body, 'Sunday morning'),
            ),
            provenanceComplete: true,
        );
    }

    /**
     * @return list<array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>
     */
    private function itemsForSection(string $body, string $heading, ?string $nextHeading = null): array
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $start = $this->lineId($body, $heading);
        $end = $nextHeading === null ? count($lines) + 1 : $this->lineId($body, $nextHeading);
        $items = [];

        foreach ($lines as $index => $line) {
            $lineId = $index + 1;
            $title = trim($line);

            if ($lineId <= $start || $lineId >= $end || $title === '') {
                continue;
            }

            $type = match (true) {
                str_contains(strtolower($title), 'reading:') => 'bible_reading',
                str_contains(strtolower($title), 'sermon') => 'sermon',
                str_contains(strtolower($title), 'prayer') || $title === 'Prayers' => 'prayer',
                str_contains(strtolower($title), 'notices') => 'notices',
                str_contains(strtolower($title), 'welcome') => 'welcome',
                str_contains(strtolower($title), 'family talk') => 'childrens_talk',
                preg_match('/^(?:NIP|\d{2,3}\s)/u', $title) === 1 => 'song',
                default => 'other',
            };

            $items[] = $this->groundedItem($type, $title, $lineId);
        }

        return $items;
    }

    private function lineId(string $body, string $text): int
    {
        foreach (preg_split('/\R/', $body) ?: [] as $index => $line) {
            if (trim($line) === $text) {
                return $index + 1;
            }
        }

        $this->fail("Could not find source line '{$text}'.");
    }

    /**
     * @return list<int>
     */
    private function lineIdsBefore(string $body, string $heading): array
    {
        $headingLineId = $this->lineId($body, $heading);
        $lineIds = [];

        foreach (preg_split('/\R/', $body) ?: [] as $index => $line) {
            $lineId = $index + 1;

            if ($lineId >= $headingLineId) {
                break;
            }

            if (trim($line) !== '') {
                $lineIds[] = $lineId;
            }
        }

        return $lineIds;
    }
}
