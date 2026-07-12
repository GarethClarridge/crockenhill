<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClipboardButtonTest extends TestCase
{
    #[Test]
    public function it_renders_clipboard_button_with_full_label_by_default(): void
    {
        $url = 'https://example.com/test';
        $rendered = Blade::render('<x-clipboard-button :url="$url" />', ['url' => $url]);

        $this->assertStringContainsString('navigator.clipboard.writeText(textToCopy)', $rendered);
        $this->assertStringContainsString('const textToCopy = \'https:\/\/example.com\/test\'', $rendered);
        $this->assertStringContainsString('Copy link', $rendered);
        $this->assertStringNotContainsString('sr-only', $rendered);
        // Accessibility: ensure it has type="button" to prevent form submission
        $this->assertStringContainsString('type="button"', $rendered);
    }

    #[Test]
    public function it_renders_clipboard_button_with_hidden_label_when_specified(): void
    {
        $url = 'https://example.com/test';
        $rendered = Blade::render('<x-clipboard-button :url="$url" hideLabel />', ['url' => $url]);

        $this->assertStringContainsString('navigator.clipboard.writeText(textToCopy)', $rendered);
        $this->assertStringContainsString('class="sr-only"', $rendered);
    }

    #[Test]
    public function it_supports_generic_content_prop(): void
    {
        $content = 'John 3:16';
        $rendered = Blade::render('<x-clipboard-button :content="$content" label="Copy reference" />', ['content' => $content]);

        $this->assertStringContainsString('navigator.clipboard.writeText(textToCopy)', $rendered);
        $this->assertStringContainsString('const textToCopy = \'John 3:16\'', $rendered);
        $this->assertStringContainsString('Copy reference', $rendered);
    }

    #[Test]
    public function it_supports_js_content_prop(): void
    {
        $rendered = Blade::render('<x-clipboard-button jsContent="test()" />');

        $this->assertStringContainsString('const textToCopy = test()', $rendered);
    }

    #[Test]
    public function it_supports_custom_labels_and_icons(): void
    {
        $content = 'Test content';
        $rendered = Blade::render('<x-clipboard-button :content="$content" label="Custom Label" copiedLabel="Done!" icon="clipboard-document" />', ['content' => $content]);

        $this->assertStringContainsString('Custom Label', $rendered);
        $this->assertStringContainsString('Done!', $rendered);
        $this->assertStringContainsString('svg', $rendered);
    }

    #[Test]
    public function it_safely_escapes_content_with_quotes(): void
    {
        $content = "Reference 'quoted'";
        $rendered = Blade::render('<x-clipboard-button :content="$content" />', ['content' => $content]);

        $this->assertStringContainsString('navigator.clipboard.writeText(', $rendered);
        $this->assertStringContainsString('quoted', $rendered);
    }

    #[Test]
    public function it_updates_title_and_span_content_on_copy_state(): void
    {
        $content = 'Test content';
        $rendered = Blade::render('<x-clipboard-button :content="$content" label="Copy it" copiedLabel="Success!" />', ['content' => $content]);

        // Redundant aria-label on button should be gone to avoid double announcement with aria-live span
        $this->assertStringNotContainsString(':aria-label=', $rendered);
        $this->assertStringContainsString(':title="copied ? \'Success!\' : \'Copy it to clipboard\'"', $rendered);
        $this->assertStringContainsString('x-text="copied ? \'Success!\' : \'Copy it\'"', $rendered);
    }

    #[Test]
    public function it_handles_unsupported_browser_state_via_alpine(): void
    {
        $rendered = Blade::render('<x-clipboard-button content="test" />');

        // It should hide itself if navigator.clipboard is not available
        $this->assertStringContainsString('x-show="navigator.clipboard"', $rendered);
        // It should guard the click handler
        $this->assertStringContainsString('if (!navigator.clipboard) return;', $rendered);
    }

    #[Test]
    public function it_has_accessible_announcements_for_state_changes(): void
    {
        $rendered = Blade::render('<x-clipboard-button content="test" />');

        // It should have aria-live for announcing "Copied!"
        $this->assertStringContainsString('aria-live="polite"', $rendered);
    }

    #[Test]
    public function it_has_visible_focus_indicator_classes(): void
    {
        $rendered = Blade::render('<x-clipboard-button content="test" />');

        $this->assertStringContainsString('focus-visible:ring-2', $rendered);
        $this->assertStringContainsString('focus-visible:ring-cbc-teal', $rendered);
        $this->assertStringContainsString('focus-visible:ring-offset-2', $rendered);
    }
}
