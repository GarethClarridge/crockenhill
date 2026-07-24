<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\CommonMarkConverter;

/**
 * Safely renders Markdown content into HTML, acting as a security barrier against XSS.
 *
 * This utility service converts potentially untrusted Markdown text (e.g., user inputs or
 * parsed external content) into clean, renderable HTML blocks. To protect against
 * Cross-Site Scripting (XSS) and injection vulnerabilities, it enforces a strict
 * "secure-by-default" strategy by:
 * - Stripping out all raw block-level and inline HTML input tags (unless overridden by config).
 * - Restricting permitted link protocols to block unsafe URI schemes (such as `javascript:`,
 *   `data:`, or `vbscript:`).
 *
 * Security configurations can be customized globally via the `markdown.safe_options`
 * configuration key, which merges with the default restrictive options.
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
     * Initializes the Markdown converter with standard defensive security settings.
     *
     * Merges custom options defined under `markdown.safe_options` configuration key
     * with the restrictive defaults to allow safe customization.
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
     * Converts potentially untrusted Markdown text into safe and sanitized HTML.
     *
     * This method handles Cross-Site Scripting (XSS) prevention by invoking the underlying CommonMark converter
     * configured with strict HTML input stripping and unsafe link blocklists. Raw HTML
     * tag elements within the markdown are fully removed (or neutralized), and any links
     * utilizing dangerous URI protocols are sanitized.
     *
     * Blank, null, or empty string inputs are gracefully handled and return an empty string
     * to prevent downstream rendering errors.
     *
     * @param  string|null  $markdown  The raw, potentially untrusted Markdown string to convert.
     * @return string The sanitized, rendered HTML block.
     */
    public function convert(?string $markdown): string
    {
        if (blank($markdown)) {
            return '';
        }

        return $this->converter->convert($markdown)->getContent();
    }
}
