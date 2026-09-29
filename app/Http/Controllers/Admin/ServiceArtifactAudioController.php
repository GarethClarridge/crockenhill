<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\HistoricStagingContext;
use App\Http\Controllers\Controller;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\Path;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a run's archived service audio to an administrator, so a review question can be
 * heard at its span before any section has been extracted.
 *
 * The audio is a private service artifact on a local disk, read exactly as the run reads it
 * (inside its historic staging context when it has one). It is served from the file with
 * byte ranges, which is what lets the player seek straight to a question's span.
 */
class ServiceArtifactAudioController extends Controller
{
    public function __invoke(MediaProcessingLog $processingLog, HistoricStagingContextRegistry $contexts): BinaryFileResponse
    {
        $artifact = collect(ServiceArtifactStorage::recordedFor($processingLog))
            ->last(static fn (array $entry): bool => $entry['kind'] === 'audio');
        $path = $artifact['path'] ?? null;

        if (! is_string($path) || ! ServiceArtifactDisk::isArtifactPath($path) || Path::isUnsafe($path)) {
            abort(404, 'No archived service audio for this run.');
        }

        $disk = $artifact['disk'] ?? ServiceArtifactDisk::name();
        $locate = static function () use ($disk, $path): ?string {
            if (config("filesystems.disks.{$disk}.driver") !== 'local' || ! Storage::disk($disk)->exists($path)) {
                return null;
            }

            return Storage::disk($disk)->path($path);
        };
        $context = $processingLog->historicStagingContext();
        $absolutePath = $context instanceof HistoricStagingContext ? $contexts->within($context, $locate) : $locate();

        if ($absolutePath === null) {
            abort(404, 'Archived service audio is not available.');
        }

        $response = response()->file($absolutePath);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
