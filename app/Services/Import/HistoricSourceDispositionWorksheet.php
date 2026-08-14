<?php

declare(strict_types=1);

namespace App\Services\Import;

use FilesystemIterator;
use Normalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * The complete path list an operator must adjudicate before a source copy can
 * be certified.
 *
 * {@see HistoricSourceAcquisitionVerifier::inventory()} refuses any path with no
 * disposition — which is correct, and which means it cannot be used to discover
 * the paths in the first place. This walks the tree the one way that works
 * before the decisions exist, and
 * `DraftHistoricSourceDispositionsCommandTest::it_enumerates_exactly_the_paths_the_acquisition_gate_inventories()`
 * pins the two walks together so a divergence surfaces in the suite rather than
 * on the acquisition host.
 *
 * Nothing here decides anything. Every drafted disposition is null, because a
 * default disposition is how a path nobody looked at ends up signed for.
 *
 * Delete alongside the acquisition verifier once the archive is imported and
 * its custody artifacts have moved to long-term custody (G9/WP10).
 */
final class HistoricSourceDispositionWorksheet
{
    public const int Version = 1;

    public const string Format = 'crockenhill-historic-source-disposition-worksheet';

    /**
     * Read-only. The copies are proved non-writable by the acquisition gate, so
     * drafting must never need to write into one.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the tree cannot be enumerated exactly
     */
    public function draft(string $root): array
    {
        $resolvedRoot = realpath($root);

        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot)) {
            throw new RuntimeException("Historic source copy does not exist: {$root}");
        }

        $paths = [];
        $collisions = [];
        $readErrors = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($item->getPathname(), strlen($resolvedRoot) + 1),
            );
            $collisionKey = mb_strtolower(
                class_exists(Normalizer::class)
                    ? (Normalizer::normalize($relative, Normalizer::FORM_C) ?: $relative)
                    : $relative,
            );

            if (isset($collisions[$collisionKey]) && $collisions[$collisionKey] !== $relative) {
                throw new RuntimeException(
                    "Historic source contains a case or Unicode-normalisation collision: {$relative} and "
                    ."{$collisions[$collisionKey]} cannot both be preserved on a case-insensitive destination."
                );
            }

            $collisions[$collisionKey] = $relative;
            $observed = $this->observe($item, $relative, $resolvedRoot);

            if ($observed['read_error'] !== null) {
                $readErrors[] = "{$relative} ({$observed['read_error']})";
            }

            $paths[$relative] = ['observed' => $observed, 'disposition' => null];
        }

        if ($readErrors !== []) {
            throw new RuntimeException(
                'Historic source copy has unreadable paths, so no complete inventory exists: '
                .implode(', ', $readErrors)
            );
        }

        ksort($paths, SORT_STRING);

        return [
            'format' => self::Format,
            'version' => self::Version,
            'copy_root_sha256' => hash('sha256', $resolvedRoot),
            'drafted_at' => now()->utc()->toIso8601String(),
            'path_count' => count($paths),
            /**
             * A written reason per disposition *class*, not per path. The custody
             * schema allows a path only `disposition` and `xattrs`, so the
             * reasons D5 requires live here — and a tree with tens of thousands
             * of files needs a defensible reason per decision, not per file.
             */
            'disposition_reasons' => [],
            'paths' => $paths,
        ];
    }

    /**
     * @param  array<string, mixed>  $worksheet
     * @return array<string, string> the adjudicated disposition for each path
     *
     * @throws RuntimeException when any path or disposition class is unresolved
     */
    public function decisions(array $worksheet): array
    {
        if (($worksheet['format'] ?? null) !== self::Format
            || ($worksheet['version'] ?? null) !== self::Version
            || ! is_array($worksheet['paths'] ?? null)
            || ! is_array($worksheet['disposition_reasons'] ?? null)) {
            throw new RuntimeException('Historic source disposition worksheet has an unsupported or incomplete schema.');
        }

        $decisions = [];
        $undecided = [];

        foreach ($worksheet['paths'] as $relative => $path) {
            if (! is_string($relative) || $relative === '' || ! is_array($path)) {
                throw new RuntimeException('Historic source disposition worksheet contains an invalid path entry.');
            }

            $disposition = $path['disposition'] ?? null;

            if (! is_string($disposition) || trim($disposition) === '') {
                $undecided[] = $relative;

                continue;
            }

            $decisions[$relative] = trim($disposition);
        }

        if ($undecided !== []) {
            throw new RuntimeException(
                'Historic source disposition worksheet leaves paths undecided: '.implode(', ', $undecided)
            );
        }

        if ($decisions === []) {
            throw new RuntimeException('Historic source disposition worksheet decides nothing.');
        }

        $this->assertEveryDispositionHasAReason($decisions, $worksheet['disposition_reasons']);
        ksort($decisions, SORT_STRING);

        return $decisions;
    }

    /**
     * @param  array<string, string>  $decisions
     * @param  array<mixed>  $reasons
     */
    private function assertEveryDispositionHasAReason(array $decisions, array $reasons): void
    {
        $unexplained = [];

        foreach (array_unique(array_values($decisions)) as $disposition) {
            $reason = $reasons[$disposition] ?? null;

            if (! is_string($reason) || trim($reason) === '') {
                $unexplained[] = $disposition;
            }
        }

        if ($unexplained !== []) {
            throw new RuntimeException(
                'Historic source dispositions carry no written reason: '.implode(', ', $unexplained)
            );
        }
    }

    /**
     * Informational only — what the operator needs in order to decide. The
     * figures the gate binds itself to are re-observed by the verifier's own
     * inventory, never copied from here.
     *
     * @return array<string, mixed>
     */
    private function observe(SplFileInfo $item, string $relative, string $resolvedRoot): array
    {
        $path = $item->getPathname();
        $type = $item->isLink() ? 'symlink' : ($item->isDir() ? 'directory' : ($item->isFile() ? 'file' : 'other'));
        $size = null;
        $linkTarget = null;
        $readError = null;

        if ($type === 'symlink') {
            $observedTarget = readlink($path);

            if (is_string($observedTarget)) {
                $linkTarget = $observedTarget;
                $resolved = realpath($path);

                if (! is_string($resolved)
                    || ($resolved !== $resolvedRoot && ! str_starts_with($resolved, $resolvedRoot.'/'))) {
                    $readError = 'link_escapes_or_dangles';
                }
            } else {
                $readError = 'readlink_failed';
            }
        }

        if ($type === 'file') {
            try {
                $observedSize = $item->getSize();
                $size = is_int($observedSize) ? $observedSize : null;

                if (! $item->isReadable()) {
                    $readError = 'unreadable';
                }
            } catch (Throwable) {
                $readError = 'read_failed';
            }
        }

        if ($type === 'other') {
            $readError = 'unsupported_file_type';
        }

        $stat = lstat($path);

        return [
            'type' => $type,
            'link_target' => $linkTarget,
            'byte_size' => $size,
            'mode' => substr(sprintf('%o', $item->getPerms()), -4),
            'hard_link_count' => is_array($stat) ? $stat['nlink'] : null,
            'modified_at' => $item->getMTime(),
            'read_error' => $readError,
        ];
    }
}
