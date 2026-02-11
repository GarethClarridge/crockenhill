<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sermon;
use App\Services\SermonStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SermonAssetController extends Controller
{
    public function __construct(private readonly SermonStorageService $storageService) {}

    /**
     * Serve audio file for a sermon
     */
    public function serveAudio(Sermon $sermon): BinaryFileResponse|RedirectResponse
    {
        if (! $sermon->audio_file_path) {
            abort(404, 'Audio file not found.');
        }

        $storageService = $this->storageService;
        $fileInfo = $storageService->getSermonFileInfo($sermon);

        if (! Storage::disk($fileInfo['disk'])->exists($fileInfo['path'])) {
            abort(404, 'Audio file not found.');
        }

        // For cloud storage, redirect to CDN URL for better performance
        if ($fileInfo['disk'] === 'do_spaces' && config('filesystems.disks.do_spaces.cdn_endpoint')) {
            return redirect($storageService->getPublicUrl($sermon));
        }

        // Fallback to Laravel serving (useful for private files or local storage)
        $path = Storage::disk($fileInfo['disk'])->path($fileInfo['path']);
        $name = basename($fileInfo['path']);

        return response()->file($path, [
            'Content-Type' => 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Serve thumbnail image for a sermon
     */
    public function serveThumbnail(Sermon $sermon): BinaryFileResponse
    {
        if (! $sermon->thumbnail_file_path) {
            abort(404, 'Thumbnail not found.');
        }

        $disk = config('thumbnail-generation.storage.disk', 'public');

        if (! Storage::disk($disk)->exists($sermon->thumbnail_file_path)) {
            abort(404, 'Thumbnail file not found.');
        }

        $path = Storage::disk($disk)->path($sermon->thumbnail_file_path);
        $name = basename($sermon->thumbnail_file_path);

        // Determine content type based on file extension
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'public, max-age=86400', // 24 hours cache for images
            'ETag' => md5_file($path),
            'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($path)).' GMT',
        ]);
    }
}
