<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\TalkType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TalkTypeTest extends TestCase
{
    #[Test]
    public function only_the_sermon_is_a_sermon(): void
    {
        $this->assertTrue(TalkType::Sermon->isSermon());
        $this->assertSame(
            [TalkType::ChildrensTalk, TalkType::PartnerUpdate, TalkType::Testimony],
            TalkType::nonSermon(),
        );
    }

    #[Test]
    public function every_type_has_singular_and_plural_labels(): void
    {
        $this->assertSame('Testimony', TalkType::Testimony->label());
        $this->assertSame('Testimonies', TalkType::Testimony->pluralLabel());
        $this->assertSame("Children's Talks", TalkType::ChildrensTalk->pluralLabel());
    }
}
