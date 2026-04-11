<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessMediaRequest;
use App\Models\Sermon;
use App\Services\UnifiedMediaProcessor;
use App\Services\VideoProcessingOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SermonAdminController extends Controller
{
    public function __construct(
        private readonly UnifiedMediaProcessor $mediaProcessor,
    ) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Sermon $sermon): RedirectResponse
    {
        $this->authorize('delete', $sermon);

        $sermonId = $sermon->id;
        $sermonTitle = $sermon->title;

        $sermon->delete();

        \Illuminate\Support\Facades\Log::warning('Sermon deleted by admin', [
            'admin_id' => auth()->id(),
            'sermon_id' => $sermonId,
            'sermon_title' => $sermonTitle,
        ]);

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
                return redirect()
                    ->route('sermons.index')
                    ->with('message', "Processing started for \"{$file->getClientOriginalName()}\". Processing ID: {$result->processingId}");
            } else {
                return redirect()
                    ->back()
                    ->with('error', $result->message);
            }

        } catch (\Exception $e) {
            Log::error('Sermon upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);

            return redirect()
                ->back()
                ->with('error', 'An error occurred during upload. Please try again or contact support.');
        }
    }

    public function destroyWithDate(int $year, int $month, Sermon $sermon): RedirectResponse
    {
        $this->assertDateMatchesUrl($year, $month, $sermon);

        return $this->destroy($sermon);
    }

    private function assertDateMatchesUrl(int $year, int $month, Sermon $sermon): void
    {
        if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
            abort(404, 'Sermon not found for the specified date.');
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
