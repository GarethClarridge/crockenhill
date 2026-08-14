<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Two real temporary source copies, plus the private paths the acquisition
 * commands write to.
 *
 * The trees are genuinely on disk — real hidden files, real symlinks, real
 * bytes — because the acquisition inventory is a filesystem observation and a
 * mocked one would prove nothing. Only the two facts this container cannot
 * produce, a second failure domain and a copy that is unwritable to root, come
 * from {@see FakeHistoricSourceFilesystemInspector}.
 */
final class HistoricSourceCopyFixture
{
    /** @var list<string> */
    private array $roots = [];

    /** @var list<string> */
    private array $files = [];

    /**
     * The evidence copy keeps the documented link; the working copy holds the
     * bytes when `materialiseWorkingLink` is set, which is what the
     * `materialize_in_working_copy` disposition actually asks for.
     *
     * @return array{0:string,1:string}
     */
    public function copies(bool $materialiseWorkingLink = false, bool $awkwardNames = false): array
    {
        $base = sys_get_temp_dir().'/historic-source-'.uniqid();
        $evidence = $base.'-evidence';
        $working = $base.'-working';
        $this->roots[] = $evidence;
        $this->roots[] = $working;

        foreach ([$evidence, $working] as $root) {
            mkdir($root.'/archive', 0755, true);
            file_put_contents($root.'/.hidden-sidecar', 'metadata');
            file_put_contents($root.'/archive/recording.avi', 'historic-video');

            if ($materialiseWorkingLink && $root === $working) {
                file_put_contents($root.'/recording-link', 'historic-video');
            } else {
                symlink('archive/recording.avi', $root.'/recording-link');
            }

            if ($awkwardNames) {
                mkdir($root.'/service notes/2004', 0755, true);
                file_put_contents($root.'/service notes/2004/café.txt', 'accented');
                file_put_contents($root.'/service notes/read me.TXT', 'mixed case');
            }

            foreach ([$root, $root.'/archive', $root.'/.hidden-sidecar', $root.'/archive/recording.avi'] as $path) {
                touch($path, 1_700_000_000);
            }
        }

        return [$evidence, $working];
    }

    /** A private path the command may create, registered for cleanup. */
    public function reservedPath(string $label): string
    {
        $path = storage_path('app/private/historic-source-'.$label.'-'.uniqid().'.json');
        $this->files[] = $path;

        return $path;
    }

    /** @param array<string, mixed> $value */
    public function jsonFile(string $label, array $value): string
    {
        $path = $this->reservedPath($label);
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * Every path, type, size and mtime in one string, so a test can assert that
     * reading a copy left it exactly as it was.
     */
    public function treeSignature(string $root): string
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $type = $item->isLink() ? 'symlink' : ($item->isDir() ? 'directory' : 'file');
            $size = $type === 'file' ? (string) $item->getSize() : '-';
            $entries[] = $item->getPathname().'|'.$type.'|'.$size.'|'.$item->getMTime();
        }

        sort($entries, SORT_STRING);

        return hash('sha256', implode("\n", $entries));
    }

    public function cleanUp(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ($this->roots as $root) {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($root);
    }
}
