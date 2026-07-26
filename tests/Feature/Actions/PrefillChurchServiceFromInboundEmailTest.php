<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\PrefillChurchServiceFromInboundEmail;
use App\Data\OosEmailParseResult;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\InboundEmail;
use App\Models\Song;
use App\Services\Email\OosEmailParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrefillChurchServiceFromInboundEmailTest extends TestCase
{
    use RefreshDatabase;

    private PrefillChurchServiceFromInboundEmail $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(PrefillChurchServiceFromInboundEmail::class);
    }

    #[Test]
    public function it_returns_empty_array_when_email_not_found(): void
    {
        $result = $this->action->execute(99999);

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_uses_stored_parse_result_when_available(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-06-01',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        [
                            'position' => 1,
                            'type' => 'custom',
                            'title' => 'Welcome',
                            'source_title' => 'Welcome',
                            'openlp_search_title' => null,
                            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
                        ],
                        [
                            'position' => 2,
                            'type' => 'songs',
                            'title' => 'Amazing Grace',
                            'source_title' => 'Amazing Grace',
                            'openlp_search_title' => null,
                            'metadata' => null,
                        ],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertSame('2026-06-01', $result['date']);
        $this->assertSame(SermonService::Morning->value, $result['service']);
        $this->assertCount(2, $result['items']);
        $this->assertSame(ServiceSectionType::Welcome->value, $result['items'][0]['section_type']);
        $this->assertSame('Welcome', $result['items'][0]['title']);
        $this->assertSame(ServiceSectionType::Song->value, $result['items'][1]['section_type']);
        $this->assertSame('Amazing Grace', $result['items'][1]['title']);
        $this->assertNull($result['items'][0]['song_id']);
    }

    #[Test]
    public function it_calls_parser_and_stores_result_when_no_stored_parse_exists(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'body_plain' => 'Order of Service for Sunday 1 June 2026 (Morning)',
            'processing_metadata' => ['recipient' => 'oos@crockenhill.org'],
        ]);

        // Mock the parser to return a controlled result
        $this->mock(OosEmailParserService::class, function ($mock) use ($inboundEmail): void {
            $mock->shouldReceive('parse')
                ->once()
                ->with(\Mockery::on(fn ($arg) => $arg instanceof InboundEmail && $arg->id === $inboundEmail->id))
                ->andReturn(new OosEmailParseResult(
                    date: '2026-06-01',
                    service: SermonService::Morning,
                    items: [
                        [
                            'position' => 1,
                            'type' => 'custom',
                            'title' => 'Opening Prayer',
                            'source_title' => 'Opening Prayer',
                            'openlp_search_title' => null,
                            'metadata' => null,
                        ],
                    ],
                    confidenceScore: 0.85,
                    needsReview: false,
                    shouldImport: true,
                    importMetadata: [],
                ));
        });

        // Resolve the action AFTER registering the mock so it picks up the binding
        $action = app(PrefillChurchServiceFromInboundEmail::class);
        $result = $action->execute($inboundEmail->id);

        $this->assertSame('2026-06-01', $result['date'] ?? null);
        $this->assertSame(SermonService::Morning->value, $result['service'] ?? null);

        // Parser result should be stored
        $inboundEmail->refresh();
        $this->assertNotNull($inboundEmail->processing_metadata['parsing'] ?? null);
    }

    #[Test]
    public function it_infers_section_types_from_item_titles(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-06-08',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        ['position' => 1, 'type' => 'custom', 'title' => "Children's Talk", 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 3, 'type' => 'custom', 'title' => 'Notices', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 4, 'type' => 'custom', 'title' => 'Welcome to All', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 5, 'type' => 'custom', 'title' => 'Morning Sermon', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 6, 'type' => 'custom', 'title' => 'Announcements for Today', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 7, 'type' => 'custom', 'title' => 'Bible Reading', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertSame(ServiceSectionType::ChildrensTalk->value, $result['items'][0]['section_type']);
        $this->assertSame(ServiceSectionType::Prayer->value, $result['items'][1]['section_type']);
        $this->assertSame(ServiceSectionType::Notices->value, $result['items'][2]['section_type']);
        $this->assertSame(ServiceSectionType::Welcome->value, $result['items'][3]['section_type']);
        $this->assertSame(ServiceSectionType::Sermon->value, $result['items'][4]['section_type']);
        $this->assertSame(ServiceSectionType::Notices->value, $result['items'][5]['section_type']);
        $this->assertSame(ServiceSectionType::Other->value, $result['items'][6]['section_type']);
    }

    #[Test]
    public function it_prefers_metadata_section_type_over_title_inference(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-06-15',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        [
                            'position' => 1,
                            'type' => 'custom',
                            'title' => 'Welcome',
                            'source_title' => null,
                            'openlp_search_title' => null,
                            'metadata' => ['section_type' => ServiceSectionType::Sermon->value],
                        ],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        // Metadata section_type (SERMON) should win over title inference (WELCOME)
        $this->assertSame(ServiceSectionType::Sermon->value, $result['items'][0]['section_type']);
    }

    #[Test]
    public function it_skips_items_with_empty_titles(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-06-22',
                    'resolved_service' => SermonService::Evening->value,
                    'items' => [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Valid Item', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 2, 'type' => 'custom', 'title' => '', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 3, 'type' => 'custom', 'title' => '   ', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertCount(1, $result['items']);
        $this->assertSame('Valid Item', $result['items'][0]['title']);
    }

    #[Test]
    public function it_does_not_include_date_or_service_when_parsing_yields_none(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => null,
                    'resolved_service' => null,
                    'items' => [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'needs_review' => true,
                    'should_import' => false,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertArrayNotHasKey('date', $result);
        $this->assertArrayNotHasKey('service', $result);
        $this->assertArrayHasKey('items', $result);
    }

    #[Test]
    public function each_item_gets_a_unique_key(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-06-29',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Item One', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 2, 'type' => 'custom', 'title' => 'Item Two', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertCount(2, $result['items']);
        $this->assertNotSame($result['items'][0]['key'], $result['items'][1]['key']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result['items'][0]['key']
        );
    }

    #[Test]
    public function it_links_song_items_to_the_catalogue_through_email_decoration(): void
    {
        $numbered = Song::factory()->create([
            'title' => 'Sing to God',
            'canonical_key' => 'sing to god',
            'praise_number' => '98',
        ]);

        $labelled = Song::factory()->create([
            'title' => 'Restore O Lord',
            'canonical_key' => 'restore o lord',
            'praise_number' => null,
        ]);

        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-07-12',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => '98 Sing to God', 'source_title' => '98 Sing to God', 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 2, 'type' => 'songs', 'title' => 'NIP ‘Restore O Lord’', 'source_title' => 'NIP ‘Restore O Lord’', 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 3, 'type' => 'songs', 'title' => 'A song nobody has catalogued', 'source_title' => 'A song nobody has catalogued', 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertSame($numbered->id, $result['items'][0]['song_id']);
        $this->assertSame($labelled->id, $result['items'][1]['song_id']);
        $this->assertNull($result['items'][2]['song_id']);
    }

    #[Test]
    public function it_marks_only_audited_catalogue_matches_as_inferred(): void
    {
        $exact = Song::factory()->create([
            'title' => 'Amazing Grace',
            'canonical_key' => 'amazing grace',
        ]);
        $fuzzy = Song::factory()->create([
            'title' => 'How Deep the Fathers Love For Us',
            'canonical_key' => 'how deep the fathers love for us',
        ]);

        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'parsing' => [
                    'resolved_date' => '2026-07-12',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => 'Amazing Grace', 'openlp_search_title' => null, 'metadata' => null],
                        ['position' => 2, 'type' => 'songs', 'title' => 'How Deep the Fathers Love', 'source_title' => 'How Deep the Fathers Love', 'openlp_search_title' => null, 'metadata' => null],
                    ],
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertSame($exact->id, $result['items'][0]['song_id']);
        $this->assertFalse($result['items'][0]['inferred_song_link']);
        $this->assertSame($fuzzy->id, $result['items'][1]['song_id']);
        $this->assertTrue($result['items'][1]['inferred_song_link']);
    }

    #[Test]
    public function it_prefills_nothing_when_an_explicit_plan_key_is_stale(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'parsing' => [
                    'resolved_date' => '2026-07-12',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Morning welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'service_plans' => [
                        [
                            'plan_key' => 'morning:2026-07-12',
                            'service' => SermonService::Morning->value,
                            'date' => '2026-07-12',
                            'items' => [
                                ['position' => 1, 'type' => 'custom', 'title' => 'Morning welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $this->action->execute($inboundEmail->id, 'evening:2026-07-12'));
    }

    #[Test]
    public function it_titles_a_matched_song_from_the_catalogue_and_keeps_the_email_line(): void
    {
        $song = Song::factory()->create([
            'title' => 'Holy Spirit, living breath of God',
            'canonical_key' => 'holy spirit living breath of god',
        ]);

        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-07-12',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        [
                            'position' => 1,
                            'type' => 'songs',
                            'title' => 'NIP ‘Holy, Spirit, living breath of God’',
                            'source_title' => 'NIP ‘Holy, Spirit, living breath of God’',
                            'openlp_search_title' => null,
                            'metadata' => null,
                        ],
                        [
                            'position' => 2,
                            'type' => 'songs',
                            'title' => 'A song nobody has catalogued',
                            'source_title' => 'A song nobody has catalogued',
                            'openlp_search_title' => null,
                            'metadata' => null,
                        ],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertSame($song->id, $result['items'][0]['song_id']);
        $this->assertSame('Holy Spirit, living breath of God', $result['items'][0]['title']);
        $this->assertSame('NIP ‘Holy, Spirit, living breath of God’', $result['items'][0]['source_title']);

        // An unmatched song has no catalogue title to borrow, so its line stands as the title.
        $this->assertNull($result['items'][1]['song_id']);
        $this->assertSame('A song nobody has catalogued', $result['items'][1]['title']);
    }

    #[Test]
    public function it_cleans_titles_stored_by_an_earlier_parse(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-07-12',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Notices (see above)', 'source_title' => 'Notices (see above)', 'openlp_search_title' => null, 'metadata' => ['email_type' => 'notices']],
                        ['position' => 2, 'type' => 'bibles', 'title' => 'Bible Reading: Joshua 5:13-6:27', 'source_title' => 'Bible Reading: Joshua 5:13-6:27', 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        // Plans parsed before cleaning existed still review with a readable title, so an
        // operator does not have to re-parse an email to get one.
        $this->assertSame('Notices', $result['items'][0]['title']);
        $this->assertSame('Notices (see above)', $result['items'][0]['source_title']);
        $this->assertSame('Joshua 5:13-6:27', $result['items'][1]['title']);
        $this->assertSame('Bible Reading: Joshua 5:13-6:27', $result['items'][1]['source_title']);
    }

    #[Test]
    public function it_falls_back_to_the_title_as_provenance_when_the_parse_stored_none(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-07-12',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertSame('Welcome', $result['items'][0]['source_title']);
    }

    #[Test]
    public function it_keeps_a_song_id_the_parse_already_carried(): void
    {
        $parsed = Song::factory()->create(['title' => 'Sing to God', 'canonical_key' => 'sing to god']);
        $other = Song::factory()->create(['title' => 'Another Song', 'canonical_key' => 'another song']);

        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
                'parsing' => [
                    'resolved_date' => '2026-07-12',
                    'resolved_service' => SermonService::Morning->value,
                    'items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => 'Sing to God', 'source_title' => 'Sing to God', 'openlp_search_title' => null, 'song_id' => $other->id, 'metadata' => null],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = $this->action->execute($inboundEmail->id);

        $this->assertSame($other->id, $result['items'][0]['song_id']);
        $this->assertNotSame($parsed->id, $result['items'][0]['song_id']);
    }
}
