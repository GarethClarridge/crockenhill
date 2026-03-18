<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MediaUploadScenario
{
    /**
     * @param  list<string>  $disks
     */
    public static function fakeDisks(array $disks = ['local', 'public']): void
    {
        foreach ($disks as $disk) {
            Storage::fake($disk);
        }
    }

    public static function fakeAudio(
        string $filename = 'sermon.mp3',
        int $kilobytes = 100,
        string $mime = 'audio/mpeg'
    ): UploadedFile {
        return UploadedFile::fake()->create($filename, $kilobytes, $mime);
    }

    public static function fakeVideo(
        string $filename = 'sermon.mp4',
        int $kilobytes = 100,
        string $mime = 'video/mp4'
    ): UploadedFile {
        return UploadedFile::fake()->create($filename, $kilobytes, $mime);
    }

    public static function withContent(
        string $filename,
        string $content,
        ?string $mime = null
    ): UploadedFile {
        return UploadedFile::fake()
            ->createWithContent($filename, $content)
            ->mimeType($mime ?? self::mimeTypeFor($filename));
    }

    public static function makeLivewireUpload(
        UploadedFile $upload,
        ?string $filename = null,
        ?string $mime = null
    ): UploadedFile {
        $path = $upload->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Unable to read the source upload for Livewire.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the source upload contents for Livewire.');
        }

        return self::withContent(
            filename: $filename ?? $upload->getClientOriginalName(),
            content: $contents,
            mime: $mime ?? $upload->getMimeType(),
        );
    }

    private static function mimeTypeFor(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/m4a',
            'mp4' => 'video/mp4',
            'zip', 'osz' => 'application/zip',
            default => 'application/octet-stream',
        };
    }
}
