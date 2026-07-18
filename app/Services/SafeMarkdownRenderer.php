<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\CommonMarkConverter;

/**
 * Utility service to safely render Markdown content into HTML.
 *
 * Implements a strict security strategy to prevent Cross-Site Scripting (XSS)
 * and other injection attacks when rendering user-supplied or external content:
 * 1. Strips all raw HTML tags input via CommonMark's `html_input => strip` option.
 * 2. Blocks unsafe link protocols (e.g., javascript:, data:) by setting
 *    `allow_unsafe_links => false`, ensuring only secure/standard schemes are allowed.
 */
class SafeMarkdownRenderer
{
    /**
     * @var array<string, mixed>
     */
    private const DEFAULT_OPTIONS = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ];

    private readonly CommonMarkConverter $converter;

    /**
     * Initializes the SafeMarkdownRenderer service.
     *
     * Merges default high-security options with any application-specific overrides
     * defined in the `config/markdown.php` configuration file under the `safe_options` key.
     */
    public function __construct()
    {
        $configuredOptions = config('markdown.safe_options', []);

        if (! is_array($configuredOptions)) {
            $configuredOptions = [];
        }

        /** @var array<string, mixed> $options */
        $options = array_replace(self::DEFAULT_OPTIONS, $configuredOptions);
        $this->converter = new CommonMarkConverter($options);
    }

    /**
     * Convert Markdown content safely to HTML.
     *
     * Processes the supplied markdown string and returns the rendered HTML content.
     * Handles empty or null inputs gracefully by returning an empty string.
     *
     * @param  string|null  $markdown  The markdown formatted string to render
     * @return string The rendered safe HTML string
     */
    public function convert(?string $markdown): string
    {
        if (blank($markdown)) {
            return '';
        }

        return $this->converter->convert($markdown)->getContent();
    }
}
