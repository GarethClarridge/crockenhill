<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Reads the YAML-ish frontmatter block a markdown corpus file opens with.
 *
 * Deliberately not a YAML parser. The OoS corpus's frontmatter is flat
 * `key: value` lines written by one generator, and every consumer here wants a
 * handful of scalar strings — `date`, `service`, `title`, `source_subject`. A
 * real YAML dependency would accept structure the corpus never contains and
 * would have to be trusted with curation-authority values.
 */
class MarkdownFrontmatter
{
    /**
     * The frontmatter keys, in document order. Reads only as far as the closing
     * `---`, so a large body costs nothing.
     *
     * @return array<string, string> empty when the file opens with no frontmatter block
     */
    public function read(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read markdown source: {$path}");
        }

        return $this->parse($contents);
    }

    /** @return array<string, string> */
    public function parse(string $contents): array
    {
        if (preg_match('/\A---\R(.*?)\R---(?:\R|\z)/s', $contents, $matches) !== 1) {
            return [];
        }

        $frontmatter = [];

        foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $parts) === 1) {
                $frontmatter[$parts[1]] = trim($parts[2], " \"'");
            }
        }

        return $frontmatter;
    }

    /** Everything after the frontmatter block, or the whole file when there is none. */
    public function body(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read markdown source: {$path}");
        }

        return $this->bodyFromContents($contents);
    }

    /** Everything after the frontmatter block, or the whole supplied contents. */
    public function bodyFromContents(string $contents): string
    {
        return (string) preg_replace('/\A---\R.*?\R---(?:\R|\z)/s', '', $contents, 1);
    }
}
