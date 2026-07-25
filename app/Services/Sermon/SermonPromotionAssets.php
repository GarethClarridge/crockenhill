<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Data\ThumbnailMetadata;
use App\Models\Sermon;
use App\Support\Path;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SermonPromotionAssets
{
    /** @var list<string> */
    public const array KINDS = [
        'audio',
        'video',
        'transcript',
        'thumbnail',
        'plain_thumbnail',
        'card_thumbnail',
        'overlay_thumbnail',
        'candidate_plain',
        'candidate_card',
        'candidate_overlay',
    ];

    /**
     * @return list<array{kind: string, path: string}>
     */
    public function referencesForSermon(Sermon $sermon): array
    {
        $references = $this->rawReferencesForSermon($sermon);

        $uniqueReferences = [];

        foreach ($references as $reference) {
            $this->guardPortablePath($reference['path']);
            $uniqueReferences[$reference['kind'].'|'.$reference['path']] = $reference;
        }

        return array_values($uniqueReferences);
    }

    /**
     * @return list<string>
     */
    public function pathsForSermon(Sermon $sermon): array
    {
        return array_values(array_unique(array_column($this->rawReferencesForSermon($sermon), 'path')));
    }

    /**
     * @return list<array{kind: string, path: string}>
     */
    private function rawReferencesForSermon(Sermon $sermon): array
    {
        $references = [
            ['kind' => 'audio', 'path' => $sermon->audio_file_path],
            ['kind' => 'video', 'path' => $sermon->video_file_path],
            ['kind' => 'transcript', 'path' => $sermon->transcript_file_path],
            ['kind' => 'thumbnail', 'path' => $sermon->thumbnail_file_path],
        ];

        $metadata = $sermon->thumbnail_metadata;

        if ($metadata instanceof ThumbnailMetadata) {
            $references[] = ['kind' => 'plain_thumbnail', 'path' => $metadata->plainThumbnailPath];
            $references[] = ['kind' => 'card_thumbnail', 'path' => $metadata->cardThumbnailPath];
            $references[] = ['kind' => 'overlay_thumbnail', 'path' => $metadata->overlayThumbnailPath];

            foreach ($metadata->thumbnailCandidates as $candidate) {
                $references[] = ['kind' => 'candidate_plain', 'path' => $candidate['plain_path']];
                $references[] = ['kind' => 'candidate_card', 'path' => $candidate['card_path'] ?? null];
                $references[] = ['kind' => 'candidate_overlay', 'path' => $candidate['overlay_path'] ?? null];
            }
        }

        return array_values(array_filter(
            $references,
            static fn (array $reference): bool => is_string($reference['path']) && $reference['path'] !== '',
        ));
    }

    /**
     * @return list<array{kind: string, path: string, size: int, sha256: string}>
     */
    public function manifestForSermon(Sermon $sermon): array
    {
        $manifest = [];

        foreach ($this->referencesForSermon($sermon) as $reference) {
            $disk = $this->diskFor($reference['kind']);
            $path = $reference['path'];

            if (! Storage::disk($disk)->exists($path)) {
                throw new RuntimeException("Referenced {$reference['kind']} asset is missing.");
            }

            $size = Storage::disk($disk)->size($path);

            if ($size < 0) {
                throw new RuntimeException("Referenced {$reference['kind']} asset has an invalid size.");
            }

            $manifest[] = [
                'kind' => $reference['kind'],
                'path' => $path,
                'size' => $size,
                'sha256' => $this->hash($disk, $path),
            ];
        }

        return $manifest;
    }

    /**
     * @param  list<array{kind: string, path: string, size: int, sha256: string}>  $assets
     */
    public function verify(array $assets, bool $verifyHashes): void
    {
        foreach ($assets as $asset) {
            $this->guardPortablePath($asset['path']);
            $disk = $this->diskFor($asset['kind']);

            try {
                if (! Storage::disk($disk)->exists($asset['path'])) {
                    throw new RuntimeException("Referenced {$asset['kind']} asset is missing.");
                }

                if (Storage::disk($disk)->size($asset['path']) !== $asset['size']) {
                    throw new RuntimeException("Referenced {$asset['kind']} asset size does not match the bundle.");
                }

                if ($verifyHashes && ! hash_equals($asset['sha256'], $this->hash($disk, $asset['path']))) {
                    throw new RuntimeException("Referenced {$asset['kind']} asset hash does not match the bundle.");
                }
            } catch (RuntimeException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Referenced {$asset['kind']} asset could not be verified.",
                    previous: $exception,
                );
            }
        }
    }

    public function diskFor(string $kind): string
    {
        return match ($kind) {
            'audio', 'video' => (string) config('media-processing.storage.sermon_disk', 'public'),
            'transcript' => (string) config(
                'media-processing.storage.transcript_disk',
                config('media-processing.storage.sermon_disk', 'public'),
            ),
            'thumbnail', 'plain_thumbnail', 'card_thumbnail', 'overlay_thumbnail',
            'candidate_plain', 'candidate_card', 'candidate_overlay' => (string) config('thumbnail-generation.storage.disk', 'public'),
            default => throw new RuntimeException('Promotion bundle contains an unsupported asset kind.'),
        };
    }

    private function guardPortablePath(string $path): void
    {
        if ($path === '' || Path::isUnsafe($path)) {
            throw new RuntimeException('Promotion bundles may reference only safe, shared storage paths.');
        }
    }

    private function hash(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('Referenced asset could not be opened for hashing.');
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
