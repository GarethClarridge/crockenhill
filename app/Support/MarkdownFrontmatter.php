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
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read markdown source: {$path}");
        }

        try {
            if (rtrim((string) fgets($handle), "\r\n") !== '---') {
                return [];
            }

            $frontmatter = [];

            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '---') {
                    break;
                }

                if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $matches) === 1) {
                    $frontmatter[$matches[1]] = trim($matches[2], " \"'");
                }
            }

            return $frontmatter;
        } finally {
            fclose($handle);
        }
    }

    /** Everything after the frontmatter block, or the whole file when there is none. */
    public function body(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read markdown source: {$path}");
        }

        return (string) preg_replace('/\A---\R.*?\R---\R/s', '', $contents, 1);
    }
}
