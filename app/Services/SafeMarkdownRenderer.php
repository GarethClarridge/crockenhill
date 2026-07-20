<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\CommonMarkConverter;

/**
 * Service class SafeMarkdownRenderer
 *
 * Provides safe HTML rendering of Markdown input, applying strong Cross-Site Scripting (XSS)
 * prevention measures. This class acts as a secure wrapper around League\CommonMark\CommonMarkConverter.
 *
 * Security Strategy & XSS Prevention:
 * 1. HTML Input Strip: By default, the service is configured to strip all inline and block-level
 *    HTML tags from the raw Markdown input. This prevents malicious markup/scripts from executing
 *    (e.g., `<script>`, `<iframe>`, `<onload>` attributes, etc.).
 * 2. Blocking Unsafe Links: Unsafe URI schemes are blocked to prevent javascript execution and
 *    protocol hijacking. This strips out hazardous schemas like `javascript:`, `file:`, `data:`,
 *    or `vbscript:` within links and images, allowing only safe schemas like `http:`, `https:`,
 *    `mailto:`, or relative URLs.
 * 3. Configuration Customization: Default security parameters can be selectively overridden
 *    via configuration values under `markdown.safe_options`, enabling flexibility where necessary
 *    while remaining secure by default.
 */
class SafeMarkdownRenderer
{
    /**
     * Default secure configuration options.
     *
     * - 'html_input': 'strip' -> Removes any HTML tags present in the input Markdown to prevent code injection.
     * - 'allow_unsafe_links': false -> Disables rendering of links with unsafe schemes (e.g. `javascript:`).
     *
     * @var array{html_input: string, allow_unsafe_links: bool}
     */
    private const DEFAULT_OPTIONS = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ];

    /**
     * The underlying CommonMark converter instance.
     */
    private readonly CommonMarkConverter $converter;

    /**
     * Initialize the SafeMarkdownRenderer service.
     *
     * Resolves customized safe options configured under `markdown.safe_options`
     * and merges them over the strict defaults to build the CommonMarkConverter.
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
     * Convert Markdown content into safe HTML.
     *
     * Translates Markdown syntax to HTML markup while ensuring all configured
     * sanitization and safety filters are applied. Null or blank inputs are
     * gracefully returned as empty strings.
     *
     * @param  string|null  $markdown  The raw Markdown content to convert.
     * @return string The sanitized and rendered safe HTML string.
     */
    public function convert(?string $markdown): string
    {
        if (blank($markdown)) {
            return '';
        }

        return $this->converter->convert($markdown)->getContent();
    }
}
