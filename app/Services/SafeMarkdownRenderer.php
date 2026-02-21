<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\CommonMarkConverter;

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

    public function convert(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }

        return $this->converter->convert($markdown)->getContent();
    }
}
