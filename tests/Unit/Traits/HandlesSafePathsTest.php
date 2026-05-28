<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Traits\HandlesSafePaths;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlesSafePathsTest extends TestCase
{
    private object $traitInstance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traitInstance = new class
        {
            use HandlesSafePaths;

            public function is_unsafe_path(string $path): bool
            {
                return $this->isUnsafePath($path);
            }
        };
    }

    #[Test]
    public function it_identifies_safe_relative_paths(): void
    {
        $this->assertFalse($this->traitInstance->is_unsafe_path('sermons/audio.mp3'));
        $this->assertFalse($this->traitInstance->is_unsafe_path('thumbnails/image.jpg'));
        $this->assertFalse($this->traitInstance->is_unsafe_path('file.pdf'));
    }

    #[Test]
    public function it_identifies_traversal_paths_as_unsafe(): void
    {
        $this->assertTrue($this->traitInstance->is_unsafe_path('../secrets.php'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('sermons/../../etc/passwd'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('..'));
    }

    #[Test]
    public function it_identifies_absolute_paths_as_unsafe(): void
    {
        $this->assertTrue($this->traitInstance->is_unsafe_path('/etc/passwd'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('\\Windows\\System32'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('/'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('\\'));
    }

    #[Test]
    public function it_identifies_uri_schemes_as_unsafe(): void
    {
        $this->assertTrue($this->traitInstance->is_unsafe_path('http://example.com'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('https://example.com'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('ftp://example.com'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('php://input'));
        $this->assertTrue($this->traitInstance->is_unsafe_path('://'));
    }
}
