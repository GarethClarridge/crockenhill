<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonPublicationState;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricStagingUrlGuard;
use App\Services\Song\SongVideoService;
use App\Support\Path;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SongVideoController extends Controller
{
    public function __invoke(SongVideo $songVideo, SongVideoService $videos): RedirectResponse|BinaryFileResponse
    {
        abort_unless($songVideo->publication_state === SermonPublicationState::Published, 404);
        abort_if(blank($songVideo->video_file_path) || Path::isUnsafe($songVideo->video_file_path), 404);

        $diskName = $videos->assetDisk($songVideo);
        abort_if(HistoricStagingUrlGuard::isStagingDisk($diskName), 404);
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($songVideo->video_file_path), 404);

        $headers = ['Cache-Control' => 'private, no-store'];

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return response()->file($disk->path($songVideo->video_file_path), [
                ...$headers,
                'Content-Type' => 'video/mp4',
            ])->setPrivate();
        }

        abort_unless($disk->providesTemporaryUrls(), 404);

        return redirect()->away($disk->temporaryUrl($songVideo->video_file_path, now()->addMinutes(5)), 302, $headers);
    }
}
