<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceItemSemanticTypeTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_returns_explicit_section_type_if_set(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => ServiceSectionType::SERMON,
            'type' => 'songs',
            'title' => 'Opening Prayer',
        ]);

        $this->assertSame(ServiceSectionType::SERMON, $item->semanticSectionType());
    }

    #[Test]
    public function it_ignores_legacy_metadata_section_type_when_no_explicit_property_is_set(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => ['section_type' => 'prayer'],
            'type' => 'songs',
            'title' => 'Notices',
        ]);

        $this->assertSame(ServiceSectionType::SONG, $item->semanticSectionType());
    }

    #[Test]
    public function it_returns_song_type_if_type_is_songs(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'songs',
            'title' => 'Random Title',
        ]);

        $this->assertSame(ServiceSectionType::SONG, $item->semanticSectionType());
    }

    #[Test]
    public function it_returns_bible_reading_type_if_type_is_bibles(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'bibles',
            'title' => 'Random Title',
        ]);

        $this->assertSame(ServiceSectionType::BIBLE_READING, $item->semanticSectionType());
    }

    #[Test]
    public function it_infers_childrens_talk_from_title(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'other',
            'title' => 'Children\'s Address',
        ]);

        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $item->semanticSectionType());
    }

    #[Test]
    public function it_infers_prayer_from_title(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'other',
            'title' => 'Intercessory Prayer',
        ]);

        $this->assertSame(ServiceSectionType::PRAYER, $item->semanticSectionType());
    }

    #[Test]
    public function it_infers_notices_from_title(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'other',
            'title' => 'Church Notices',
        ]);

        $this->assertSame(ServiceSectionType::NOTICES, $item->semanticSectionType());

        $item->title = 'Weekly Announcements';
        $this->assertSame(ServiceSectionType::NOTICES, $item->semanticSectionType());
    }

    #[Test]
    public function it_infers_welcome_from_title(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'other',
            'title' => 'Welcome and Introduction',
        ]);

        $this->assertSame(ServiceSectionType::WELCOME, $item->semanticSectionType());
    }

    #[Test]
    public function it_infers_sermon_from_title(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'other',
            'title' => 'Morning Sermon',
        ]);

        $this->assertSame(ServiceSectionType::SERMON, $item->semanticSectionType());

        $item->title = 'The Message';
        $this->assertSame(ServiceSectionType::SERMON, $item->semanticSectionType());
    }

    #[Test]
    public function it_falls_back_to_other_if_no_match(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'other',
            'title' => 'Benediction',
        ]);

        $this->assertSame(ServiceSectionType::OTHER, $item->semanticSectionType());
    }

    #[Test]
    public function it_is_case_insensitive_when_matching_type(): void
    {
        $item = ChurchServiceItem::factory()->make([
            'section_type' => null,
            'metadata' => null,
            'type' => 'SONGS',
            'title' => 'Random',
        ]);

        $this->assertSame(ServiceSectionType::SONG, $item->semanticSectionType());
    }
}
