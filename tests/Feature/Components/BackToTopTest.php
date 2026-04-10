<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackToTopTest extends TestCase
{
    #[Test]
    public function it_exists_as_a_file(): void
    {
        $this->assertTrue(file_exists(resource_path('views/components/back-to-top.blade.php')));
    }

    #[Test]
    public function it_renders_the_correct_alpine_directives(): void
    {
        $rendered = Blade::render('<x-back-to-top />');

        $this->assertStringContainsString('x-on:scroll.window.throttle.100ms="show = window.scrollY > 400"', $rendered);
        $this->assertStringContainsString('aria-label="Back to top"', $rendered);
        $this->assertStringContainsString('window.scrollTo({ top: 0, behavior: \'smooth\' })', $rendered);
    }
}
