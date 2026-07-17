<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\CommonMarkConverter;

/**
 * SafeMarkdownRenderer converts Markdown strings into clean, sanitized HTML.
 *
 * This utility service leverages the League CommonMark converter configured with safe
 * defaults to strip potentially malicious HTML input and disable unsafe links.
 * By enforcing these restrictions, it guards against Cross-Site Scripting (XSS)
 * vulnerabilities in user-supplied Markdown content rendered within public or admin areas.
 */
class SafeMarkdownRenderer
{
    /**
     * Default options used to secure CommonMark rendering.
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_OPTIONS = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ];

    /**
     * The configured CommonMark converter instance.
     */
    private readonly CommonMarkConverter $converter;

    /**
     * Initializes the Markdown renderer with default and configured safe options.
     *
     * It merges default safety settings (stripping HTML and disabling unsafe links)
     * with any additional safe options defined under the 'markdown.safe_options' config key.
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
     * Converts a raw Markdown string into safe, sanitized HTML.
     *
     * If the input is null or blank, an empty string is returned.
     *
     * @param  string|null  $markdown  The raw Markdown content to be converted.
     * @return string Converted and sanitized HTML content.
     */
    public function convert(?string $markdown): string
    {
        if (blank($markdown)) {
            return '';
        }

        return $this->converter->convert($markdown)->getContent();
    }
}
