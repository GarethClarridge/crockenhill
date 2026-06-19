<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SermonSourceType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSourceTypeTest extends TestCase
{
    #[Test]
    public function it_returns_correct_labels(): void
    {
        $this->assertSame('Manual', SermonSourceType::Manual->label());
        $this->assertSame('Audio Upload', SermonSourceType::AudioUpload->label());
        $this->assertSame('Video Upload', SermonSourceType::VideoUpload->label());
        $this->assertSame('Livestream', SermonSourceType::Livestream->label());
    }

    #[Test]
    public function it_correctly_identifies_livestream_source(): void
    {
        $this->assertTrue(SermonSourceType::Livestream->isFromLivestream());
        $this->assertFalse(SermonSourceType::Manual->isFromLivestream());
        $this->assertFalse(SermonSourceType::AudioUpload->isFromLivestream());
        $this->assertFalse(SermonSourceType::VideoUpload->isFromLivestream());
    }
}
