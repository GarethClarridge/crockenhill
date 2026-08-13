<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonService;
use App\Services\Song\OpenLpServiceParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class OpenLpServiceParserTest extends TestCase
{
    private OpenLpServiceParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new OpenLpServiceParser;
    }

    #[Test]
    public function test_parses_valid_osz_file(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader(
                        title: 'O How The Grace Of God #749',
                        searchTitle: 'o how the grace of god 749@ 749 o how the grace of god',
                    )
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::presentationHeader('Notices.pptx')
                ),
            ])
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('2024-11-17', $result->date);
        $this->assertSame(SermonService::Morning, $result->service);
        $this->assertCount(2, $result->items);
        $this->assertFalse($result->needsReview);
    }

    #[Test]
    public function test_extracts_date_and_morning_from_am_filename(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('2024-11-17', $result->date);
        $this->assertSame(SermonService::Morning, $result->service);
    }

    #[Test]
    public function test_extracts_date_and_evening_from_pm_filename(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 PM.osz',
            osjName: '2024-11-17 PM.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('2024-11-17', $result->date);
        $this->assertSame(SermonService::Evening, $result->service);
    }

    #[Test]
    public function test_extracts_date_from_legacy_day_month_year_filename(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '01-Jan-23 AM.osz',
            osjName: '01-Jan-23 AM.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('2023-01-01', $result->date);
        $this->assertSame(SermonService::Morning, $result->service);
    }

    #[Test]
    public function test_extracts_date_from_textual_day_month_year_filename(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: 'Family Talk 26 May 2024.osz',
            osjName: 'Family Talk 26 May 2024.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('2024-05-26', $result->date);
        $this->assertSame(SermonService::Other, $result->service);
    }

    #[Test]
    public function test_unknown_slot_defaults_to_other(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-01-07.osz',
            osjName: '2024-01-07.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('2024-01-07', $result->date);
        $this->assertSame(SermonService::Other, $result->service);
    }

    #[Test]
    public function test_fails_when_no_date_can_be_inferred(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: 'service.osz',
            osjName: 'service.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $this->expectException(ValidationException::class);

        $this->parser->parse($upload);
    }

    #[Test]
    public function test_extracts_song_items_with_data_title_as_search_key(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader(
                        title: 'Jesus Shall Reign #491(i)',
                        searchTitle: 'jesus shall reign 491 i @ 491 i jesus shall reign',
                    )
                ),
            ])
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('jesus shall reign 491 i @ 491 i jesus shall reign', $result->items[0]['openlp_search_title']);
    }

    #[Test]
    public function test_extracts_bible_reference_from_footer(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::bibleHeader(
                        verboseTitle: 'Luke 15:1-32 New International Version (Anglicised), Copyright line',
                        reference: 'Luke 15:1-32',
                    )
                ),
            ])
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('Luke 15:1-32', $result->items[0]['title']);
        $this->assertSame('Luke 15:1-32 New International Version (Anglicised), Copyright line', $result->items[0]['source_title']);
    }

    #[Test]
    public function test_preserves_item_order(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::presentationHeader('Notices.pptx')
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader(
                        title: 'Song One',
                        searchTitle: 'song one@',
                    )
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('Reading')
                ),
            ])
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('presentations', $result->items[0]['type']);
        $this->assertSame('songs', $result->items[1]['type']);
        $this->assertSame('custom', $result->items[2]['type']);
        $this->assertSame(1, $result->items[0]['position']);
        $this->assertSame(2, $result->items[1]['position']);
        $this->assertSame(3, $result->items[2]['position']);
    }

    #[Test]
    public function test_rejects_non_zip_file(): void
    {
        $upload = UploadedFile::fake()->create('service.txt', 1, 'text/plain');

        $this->expectException(ValidationException::class);

        $this->parser->parse($upload);
    }

    #[Test]
    public function test_rejects_zip_without_osj(): void
    {
        $upload = OpenLpArchiveFactory::makeUploadWithoutOsj();

        $this->expectException(ValidationException::class);

        $this->parser->parse($upload);
    }

    #[Test]
    public function test_skips_openlp_core_metadata_entry(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
            ])
        );

        $result = $this->parser->parse($upload);

        $this->assertCount(1, $result->items);
        $this->assertSame('songs', $result->items[0]['type']);
    }

    #[Test]
    public function test_falls_back_to_osj_filename_for_date(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: 'Sunday Service.osz',
            osjName: '2024-11-17 PM.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('2024-11-17', $result->date);
        $this->assertSame(SermonService::Evening, $result->service);
        $this->assertSame('embedded_filename', $result->importMetadata['parse_method']);
    }

    #[Test]
    public function test_filename_osj_mismatch_lowers_confidence(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 PM.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertSame(SermonService::Morning, $result->service);
        $this->assertTrue($result->importMetadata['filename_mismatch']);
        $this->assertLessThan(1.0, $result->importMetadata['confidence_score']);
        $this->assertTrue($result->needsReview);
    }

    #[Test]
    public function test_embedded_osj_without_slot_information_does_not_count_as_a_mismatch(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2023-02-05 PM.osz',
            osjName: 'Service 2023-02-05 17-54.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertSame(SermonService::Evening, $result->service);
        $this->assertFalse($result->importMetadata['filename_mismatch']);
        $this->assertFalse($result->needsReview);
    }

    #[Test]
    public function test_sets_needs_review_when_confidence_below_threshold(): void
    {
        config()->set('service-tracking.confidence.review_below', 0.95);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: 'Sunday Service.osz',
            osjName: '2024-11-17 PM.osj',
            payload: OpenLpArchiveFactory::payload()
        );

        $result = $this->parser->parse($upload);

        $this->assertTrue($result->needsReview);
    }

    #[Test]
    public function test_extracts_presentation_items(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::presentationHeader('Notices2024Looped.pptx')
                ),
            ])
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('presentations', $result->items[0]['type']);
        $this->assertSame('Notices2024Looped.pptx', $result->items[0]['title']);
    }

    #[Test]
    public function test_extracts_custom_items(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('Reading', 'Reading')
                ),
            ])
        );

        $result = $this->parser->parse($upload);

        $this->assertSame('custom', $result->items[0]['type']);
        $this->assertSame('Reading', $result->items[0]['title']);
        $this->assertSame('Reading', $result->items[0]['metadata']['theme']);
    }

    #[Test]
    public function test_handles_empty_service_items(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(payload: OpenLpArchiveFactory::payload());

        $result = $this->parser->parse($upload);

        $this->assertCount(0, $result->items);
    }
}
