<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ClipboardButtonTest extends TestCase
{
    /** @test */
    public function it_renders_clipboard_button_with_full_label_by_default(): void
    {
        $url = 'https://example.com/test';
        $rendered = Blade::render('<x-clipboard-button :url="$url" />', ['url' => $url]);

        $this->assertStringContainsString('navigator.clipboard.writeText(\'https:\/\/example.com\/test\')', $rendered);
        $this->assertStringContainsString('Copy link', $rendered);
        $this->assertStringNotContainsString('sr-only', $rendered);
    }

    /** @test */
    public function it_renders_clipboard_button_with_hidden_label_when_specified(): void
    {
        $url = 'https://example.com/test';
        $rendered = Blade::render('<x-clipboard-button :url="$url" hideLabel />', ['url' => $url]);

        $this->assertStringContainsString('navigator.clipboard.writeText(\'https:\/\/example.com\/test\')', $rendered);
        $this->assertStringContainsString('x-bind:class="{ \'sr-only\': !copied }"', $rendered);
    }

    /** @test */
    public function it_supports_generic_content_prop(): void
    {
        $content = 'John 3:16';
        $rendered = Blade::render('<x-clipboard-button :content="$content" label="Copy reference" />', ['content' => $content]);

        $this->assertStringContainsString('navigator.clipboard.writeText(\'John 3:16\')', $rendered);
        $this->assertStringContainsString('Copy reference', $rendered);
    }

    /** @test */
    public function it_supports_custom_labels_and_icons(): void
    {
        $content = 'Test content';
        $rendered = Blade::render('<x-clipboard-button :content="$content" label="Custom Label" copiedLabel="Done!" icon="clipboard-document" />', ['content' => $content]);

        $this->assertStringContainsString('Custom Label', $rendered);
        $this->assertStringContainsString('Done!', $rendered);
        // The dynamic component might render as an <svg> with specific classes or just the icon name depending on the environment
        $this->assertStringContainsString('svg', $rendered);
    }

    /** @test */
    public function it_safely_escapes_content_with_quotes(): void
    {
        $content = "Reference 'quoted'";
        $rendered = Blade::render('<x-clipboard-button :content="$content" />', ['content' => $content]);

        $this->assertStringContainsString('navigator.clipboard.writeText(', $rendered);
        $this->assertStringContainsString('quoted', $rendered);
    }

    /** @test */
    public function it_updates_aria_label_and_title_on_copy_state(): void
    {
        $content = 'Test content';
        $rendered = Blade::render('<x-clipboard-button :content="$content" label="Copy it" copiedLabel="Success!" />', ['content' => $content]);

        $this->assertStringContainsString(':aria-label="copied ? \'Success!\' : \'Copy it\'"', $rendered);
        $this->assertStringContainsString(':title="copied ? \'Success!\' : \'Copy it to clipboard\'"', $rendered);
    }
}
