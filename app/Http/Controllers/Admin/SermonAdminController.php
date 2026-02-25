<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PreacherSource;
use App\Enums\SermonService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessMediaRequest;
use App\Http\Requests\UpdateSermonRequest;
use App\Models\Sermon;
use App\Services\PreacherResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SermonAdminController extends Controller
{
    public function __construct(private readonly PreacherResolutionService $preacherResolutionService) {}

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Sermon $sermon): View
    {
        $this->authorize('update', $sermon);

        $series = array_unique(\App\Models\Sermon::pluck('series')->all()); // Used FQCN for Sermon

        // Breadcrumbs removed

        return view('sermons.edit', [
            'sermon' => $sermon,
            'series' => $series,
            'heading' => 'Edit this sermon',
            'description' => '<meta name="description" content="Edit this sermon.">',
            // 'breadcrumbs'   => $breadcrumbs, // Removed
            'content' => '',
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Sermon $sermon, UpdateSermonRequest $request): RedirectResponse
    {
        // Gate check removed, handled by UpdateSermonRequest

        $validatedData = $request->validated();

        $sermon->title = $validatedData['title'];
        $sermon->date = Carbon::parse($validatedData['date']);
        $sermon->service = SermonService::from($validatedData['service']);
        $sermon->slug = Str::slug($validatedData['title']); // Update slug if title changes
        $sermon->series = $validatedData['series'] ?? null;
        $sermon->reference = $validatedData['reference'] ?? null;
        $resolvedPreacher = $this->preacherResolutionService->resolve($validatedData['preacher']);
        $sermon->preacher = $resolvedPreacher->name;
        $sermon->preacher_id = $resolvedPreacher->id;
        $sermon->preacher_source = PreacherSource::MANUAL;
        $sermon->preacher_confidence = null;
        $sermon->needs_preacher_review = false;

        // The 'points' attribute from $validatedData will be a JSON string or null.
        // The Sermon model's $casts property will automatically convert this JSON string
        // to an array when $sermon->points is assigned and saved.
        // Update points only if the key exists in validated data (meaning it was submitted and passed validation)
        if (array_key_exists('points', $validatedData)) {
            // Explicitly decode JSON string to array here.
            // If $validatedData['points'] is null, json_decode(null, true) is null.
            // If $validatedData['points'] is a valid JSON string, it's decoded to an array.
            $sermon->points = $validatedData['points'] ? json_decode($validatedData['points'], true) : null;
        }

        // Update summary if provided
        if (array_key_exists('summary', $validatedData)) {
            $sermon->summary = $validatedData['summary'];
        }

        // Update visibility toggles (checkboxes return '1' when checked, null when unchecked)
        $sermon->show_summary = $request->has('show_summary');
        $sermon->show_points = $request->has('show_points');

        if ($sermon->save()) {
            return redirect()->route('sermonIndex')->with('message', '"'.$sermon->title.'" successfully updated!');
        } else {
            // Log the failure or add more specific error handling
            return redirect()->back()->withInput()->with('error', 'There was a problem saving the sermon. Please try again.');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Sermon $sermon): RedirectResponse
    {
        $this->authorize('delete', $sermon);

        $sermon->delete();

        return redirect()->route('sermonIndex')->with('message', 'Sermon successfully deleted!');
    }

    /**
     * Show the simple upload for creating a new resource.
     */
    public function upload(): View
    {
        $this->authorize('create', Sermon::class);

        return view('sermons.upload', [
            'heading' => 'Upload sermon',
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

            // Use unified media processor like API
            $processor = app(\App\Services\UnifiedMediaProcessor::class);

            $result = $processor->process($type, $file);

            if ($result->success) {
                return redirect()
                    ->route('sermonIndex')
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

    /**
     * Show the form for editing the specified resource with date validation.
     */
    public function editWithDate(int $year, int $month, Sermon $sermon): View
    {
        // Validate that the sermon's date matches the URL parameters
        if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
            abort(404, 'Sermon not found for the specified date.');
        }

        return $this->edit($sermon);
    }

    /**
     * Update the specified resource in storage with date validation.
     */
    public function updateWithDate(int $year, int $month, Sermon $sermon, UpdateSermonRequest $request): RedirectResponse
    {
        // Validate that the sermon's date matches the URL parameters
        if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
            abort(404, 'Sermon not found for the specified date.');
        }

        return $this->update($sermon, $request);
    }

    /**
     * Remove the specified resource from storage with date validation.
     */
    public function destroyWithDate(int $year, int $month, Sermon $sermon): RedirectResponse
    {
        // Validate that the sermon's date matches the URL parameters
        if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
            abort(404, 'Sermon not found for the specified date.');
        }

        return $this->destroy($sermon);
    }
}
