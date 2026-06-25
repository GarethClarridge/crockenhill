<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Path;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    #[Test]
    public function it_treats_plain_relative_paths_as_safe(): void
    {
        $this->assertFalse(Path::isUnsafe('sermons/audio.mp3'));
        $this->assertFalse(Path::isUnsafe('thumbnails/image.jpg'));
        $this->assertFalse(Path::isUnsafe('file.pdf'));
    }

    #[Test]
    public function it_flags_relative_traversal_as_unsafe(): void
    {
        $this->assertTrue(Path::isUnsafe('../foo'));
        $this->assertTrue(Path::isUnsafe('sermons/../../etc/passwd'));
        $this->assertTrue(Path::isUnsafe('..'));
    }

    #[Test]
    public function it_flags_absolute_paths_as_unsafe(): void
    {
        $this->assertTrue(Path::isUnsafe('/foo'));
        $this->assertTrue(Path::isUnsafe('/'));
    }

    #[Test]
    public function it_flags_windows_paths_as_unsafe(): void
    {
        $this->assertTrue(Path::isUnsafe('\\foo'));
        $this->assertTrue(Path::isUnsafe('\\'));
    }

    #[Test]
    public function it_flags_uri_schemes_as_unsafe(): void
    {
        $this->assertTrue(Path::isUnsafe('http://example.com'));
        $this->assertTrue(Path::isUnsafe('file:///etc/passwd'));
        $this->assertTrue(Path::isUnsafe('php://input'));
    }

    #[Test]
    public function it_identifies_already_resolvable_urls(): void
    {
        $this->assertTrue(Path::isAlreadyResolvableUrl('http://example.com/img.png'));
        $this->assertTrue(Path::isAlreadyResolvableUrl('https://example.com/img.png'));
        $this->assertTrue(Path::isAlreadyResolvableUrl('//cdn.example.com/img.png'));
        $this->assertTrue(Path::isAlreadyResolvableUrl('/storage/img.png'));
    }

    #[Test]
    public function it_treats_plain_relative_paths_as_not_already_resolvable(): void
    {
        $this->assertFalse(Path::isAlreadyResolvableUrl('preachers/img.png'));
        $this->assertFalse(Path::isAlreadyResolvableUrl('img.png'));
    }
}
