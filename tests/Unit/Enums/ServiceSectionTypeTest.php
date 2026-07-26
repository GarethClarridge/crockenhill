<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ServiceSectionType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionTypeTest extends TestCase
{
    #[Test]
    public function it_returns_correct_labels(): void
    {
        $this->assertSame('Welcome', ServiceSectionType::Welcome->label());
        $this->assertSame('Prayer', ServiceSectionType::Prayer->label());
        $this->assertSame('Notices', ServiceSectionType::Notices->label());
        $this->assertSame('Song', ServiceSectionType::Song->label());
        $this->assertSame("Children's Talk", ServiceSectionType::ChildrensTalk->label());
        $this->assertSame('Bible Reading', ServiceSectionType::BibleReading->label());
        $this->assertSame('Sermon', ServiceSectionType::Sermon->label());
        $this->assertSame('Other', ServiceSectionType::Other->label());
    }

    #[Test]
    public function it_infers_type_from_title(): void
    {
        $this->assertSame(ServiceSectionType::Welcome, ServiceSectionType::inferFromTitle('Welcome to our service'));
        $this->assertSame(ServiceSectionType::Prayer, ServiceSectionType::inferFromTitle('Opening Prayer'));
        $this->assertSame(ServiceSectionType::Notices, ServiceSectionType::inferFromTitle('Weekly Notices'));
        $this->assertSame(ServiceSectionType::Notices, ServiceSectionType::inferFromTitle('Announcements'));
        $this->assertSame(ServiceSectionType::ChildrensTalk, ServiceSectionType::inferFromTitle("Children's Corner"));
        $this->assertSame(ServiceSectionType::ChildrensTalk, ServiceSectionType::inferFromTitle('Family Talk - "Joel"'));
        $this->assertSame(ServiceSectionType::BibleReading, ServiceSectionType::inferFromTitle('Bible Reading'));
        $this->assertSame(ServiceSectionType::Sermon, ServiceSectionType::inferFromTitle('Morning Sermon'));
        $this->assertSame(ServiceSectionType::Sermon, ServiceSectionType::inferFromTitle('Today\'s Message'));
        $this->assertSame(ServiceSectionType::Other, ServiceSectionType::inferFromTitle('Communion'));
    }

    #[Test]
    public function it_is_case_insensitive_when_inferring_from_title(): void
    {
        $this->assertSame(ServiceSectionType::Welcome, ServiceSectionType::inferFromTitle('WELCOME'));
        $this->assertSame(ServiceSectionType::Prayer, ServiceSectionType::inferFromTitle('prayer'));
    }
}
