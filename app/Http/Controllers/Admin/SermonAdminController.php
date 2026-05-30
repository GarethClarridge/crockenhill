<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessMediaRequest;
use App\Models\Sermon;
use App\Services\UnifiedMediaProcessor;
use App\Services\VideoProcessingOptions;
use App\Traits\SanitizesLogData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SermonAdminController extends Controller
{
    use SanitizesLogData;

    public function __construct(
        private readonly UnifiedMediaProcessor $mediaProcessor,
    ) {}

    /**
     * Remove the specified resource from storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function destroy(Sermon $sermon): RedirectResponse
    {
        $this->authorize('delete', $sermon);

        Log::warning('Sermon deleted by admin', [
            'admin_id' => auth()->id(),
            'sermon_id' => $sermon->id,
            'title' => $this->sanitizeForLog((string) $sermon->title),
        ]);

        $sermon->delete();

        return redirect()->route('sermons.index')->with('message', 'Sermon successfully deleted!');
    }

    /**
     * Show the simple upload for creating a new resource.
     */
    public function upload(): View
    {
        $this->authorize('create', Sermon::class);

        return view('sermons.upload', [
            'heading' => 'Upload sermon',
            'description' => 'Upload sermon media for processing.',
            'content' => '',
            'links' => collect(),
        ]);
    }

    /**
     * Process media upload through unified processing service
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     * Stack traces are sanitized to prevent information leakage.
     */
    public function processMedia(ProcessMediaRequest $request): RedirectResponse
    {
        $validatedData = $request->validated();

        try {
            $file = $request->file('file');
            $type = $validatedData['type'];
            $options = $this->processingOptions($validatedData);

            $result = $options === []
                ? $this->mediaProcessor->process($type, $file)
                : $this->mediaProcessor->process($type, $file, options: $options);

            if ($result->success) {
                Log::warning('Media processing initiated by admin', [
                    'admin_id' => auth()->id(),
                    'type' => $type,
                    'filename' => $this->sanitizeForLog($file->getClientOriginalName()),
                    'processing_id' => $this->sanitizeForLog((string) $result->processingId),
                ]);

                return redirect()
                    ->route('sermons.index')
                    ->with('message', "Processing started for \"{$file->getClientOriginalName()}\". Processing ID: {$result->processingId}");
            }

            return redirect()
                ->back()
                ->with('error', $result->message);

        } catch (\Exception $e) {
            Log::error('Sermon upload failed', [
                'error' => $this->sanitizeForLog($e->getMessage()),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
                'user_id' => $request->user()?->id,
            ]);

            return redirect()
                ->back()
                ->with('error', 'An error occurred during upload. Please try again or contact support.');
        }
    }

    /**
     * @param  array<string, mixed>  $validatedData
     * @return array<string, mixed>
     */
    private function processingOptions(array $validatedData): array
    {
        return VideoProcessingOptions::forMediaType(
            is_string($validatedData['type'] ?? null) ? $validatedData['type'] : null,
            (bool) ($validatedData['auto_trim'] ?? false),
            isset($validatedData['video_processing_mode']) && is_string($validatedData['video_processing_mode'])
                ? $validatedData['video_processing_mode']
                : null
        );
    }
}
