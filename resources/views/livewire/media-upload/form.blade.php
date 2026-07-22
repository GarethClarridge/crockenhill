<div
    x-data="mediaUploadController({
        componentId: @js($this->getId()),
        maxFileSizeBytes: @js($maxFileSizeBytes ?? 0),
        maxFileSizeLabel: @js($maxFileSize ?? 'N/A')
    })"
    x-init="init()"
    x-on:media-upload-cancel.window="cancelUpload"
>
    <x-admin.form-shell title="Upload recording" description="Audio, sermon video, or full livestream — processing starts automatically.">
        <x-slot:actions>
            {{-- This page stays reachable when service tracking is disabled (gated only by
                 the Sermon create Gate), but the services hub 404s in that state --}}
            @if((bool) config('service-tracking.enabled', true))
                <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                    Back to services
                </x-button>
            @else
                <x-button link="{{ route('members.home') }}" variant="outline" inline>
                    Back to members area
                </x-button>
            @endif
        </x-slot:actions>

        {{-- Upload Form --}}
        @if(
            in_array($status, [\App\Enums\UploadState::Idle, \App\Enums\UploadState::Uploading], true)
            || ($status === \App\Enums\UploadState::Failed && $processingId === null && $tempFilePath === null)
        )
            @if($contextChurchService)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-cbc-teal/30 bg-cbc-teal/5 px-4 py-3">
                    <p class="text-sm text-gray-700">
                        Uploading for <strong>{{ $contextChurchService->date->format('j F Y') }} — {{ $contextChurchService->service->label() }}</strong>
                    </p>
                    <x-button link="{{ route('admin.services.upload-recording') }}" variant="outline" size="sm" inline>
                        Wrong service?
                    </x-button>
                </div>
            @endif

            <x-card heading="Recording">
                <div class="space-y-6">
                    {{-- Media Type Selection --}}
                    <div>
                        <x-select
                            label="Media type"
                            required
                            wire:model.live="mediaType"
                            placeholder="Select media type..."
                            :options="[
                                ['id' => 'audio', 'name' => 'Audio only'],
                                ['id' => 'video', 'name' => 'Sermon video'],
                                ['id' => 'livestream', 'name' => 'Full livestream'],
                            ]"
                        />

                        {{-- Dynamic descriptions based on selection --}}
                        <div class="mt-2 text-sm text-gray-600">
                            @if($mediaType === 'audio')
                                <p><strong>Audio:</strong> Direct sermon recording → Transcription → AI Analysis → Sermon Record</p>
                            @elseif($mediaType === 'video')
                                <p><strong>Video:</strong> Pre-edited sermon video → Audio Extraction → Transcription → AI Analysis → Sermon Record</p>
                            @elseif($mediaType === 'livestream')
                                <p><strong>Livestream:</strong> Full service recording → Segmentation → Audio Extraction → Transcription → AI Analysis → Sermon Record</p>
                            @endif
                        </div>
                    </div>

                    {{-- Service Selection: defaults per media type (livestream → morning,
                         video/audio → evening) and overrides automatic detection. --}}
                    @if($mediaType && ! $contextChurchService)
                        <div>
                            <x-select
                                label="Service"
                                required
                                wire:model.live="serviceOverride"
                                :options="[
                                    ['id' => 'morning', 'name' => 'Morning'],
                                    ['id' => 'evening', 'name' => 'Evening'],
                                ]"
                            />
                            <p class="mt-2 text-sm text-gray-600">Which service this recording is for. We pre-select based on the media type — change it if it's wrong.</p>
                            @error('serviceOverride')
                                <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    @if($mediaType === 'video' && config('media-processing.video_auto_trim.enabled', true))
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <x-toggle
                                label="Auto-trim to sermon"
                                hint="Use this for roughly clipped sermon videos with a short song, prayer, or notices at the start or end. Leave it off for already-clean sermon clips. Ambiguous runs may pause for manual review."
                                wire:model.live="autoTrimVideo" />

                            <div class="mt-3 text-sm text-gray-600">
                                @if($autoTrimVideo)
                                    <p><strong>Auto-trim:</strong> Segment recording → Classify sermon bounds → Extract trimmed sermon video → Transcription → AI Analysis</p>
                                    <p class="mt-1">Choose <strong>Full livestream</strong> instead when you are uploading the entire service and want the full livestream workflow.</p>
                                @else
                                    <p><strong>Whole sermon video:</strong> Upload the video as-is when it already starts and ends in the right place.</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- File Upload Area --}}
                    @if($mediaType)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2" for="media-file">
                                @if($mediaType === 'audio')
                                    Upload sermon audio file <span class="text-red-500">*</span>
                                @elseif($mediaType === 'video')
                                    Upload sermon video file <span class="text-red-500">*</span>
                                @elseif($mediaType === 'livestream')
                                    Upload full livestream file <span class="text-red-500">*</span>
                                @else
                                    Upload media file <span class="text-red-500">*</span>
                                @endif
                            </label>

                            {{-- Drag and Drop Area --}}
                            <div
                                x-on:dragover.prevent="isDragOver = true"
                                x-on:dragleave.prevent="isDragOver = false"
                                x-on:drop.prevent="isDragOver = false; handleDrop($event)"
                                x-bind:class="{
                                    'border-cbc-teal bg-cbc-teal/5': isDragOver,
                                    'border-gray-300': !isDragOver
                                }"
                                class="border-2 border-dashed rounded-lg p-8 text-center hover:border-gray-400 transition-colors duration-200"
                            >
                                <div wire:loading.remove wire:target="mediaFile">
                                    <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>

                                    @if($mediaFile)
                                        <p class="text-sm text-gray-600 mb-2">File selected: <strong>{{ $mediaFile->getClientOriginalName() }}</strong></p>
                                        <p class="text-sm text-gray-500">Ready to upload</p>
                                    @else
                                        <p class="text-lg text-gray-600 mb-2">Drop your file here or click to browse</p>
                                        <p class="text-sm text-gray-500">Maximum file size: {{ $maxFileSize ?? 'N/A' }}</p>
                                        <p class="text-sm text-gray-500">Accepted formats: {{ $allowedExtensions ?? 'N/A' }}</p>
                                    @endif
                                </div>

                                <div wire:loading wire:target="mediaFile" class="inline-block align-middle mt-4 mr-4">
                                    <span class="text-cbc-teal inline-flex items-center py-2">
                                        <svg class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span class="text-sm leading-none">Validating file...</span>
                                    </span>
                                </div>

                                <input
                                    wire:model="mediaFile"
                                    type="file"
                                    id="media-file"
                                    class="sr-only"
                                    accept="{{ $acceptAttribute }}"
                                    x-on:change="handleFileInputChange($event)"
                                />
                                <input type="hidden" x-model="fileModifiedDate" />
                                <label for="media-file" class="cursor-pointer inline-block align-middle">
                                    <span class="mt-4 inline-flex items-center rounded-md bg-cbc-teal px-4 py-2 text-white transition-colors duration-200 hover:bg-cbc-teal-dark focus-within:ring-2 focus-within:ring-cbc-teal focus-within:ring-offset-2">
                                        Choose file
                                    </span>
                                </label>
                            </div>

                            @error('mediaFile')
                                <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        @include('livewire.media-upload.progress', [
                            'currentFileName' => $originalFileName ?? ($mediaFile ? $mediaFile->getClientOriginalName() : 'file'),
                        ])

                        <p class="text-sm text-gray-500">
                            Processing starts automatically after the upload finishes.
                        </p>
                    @endif
                </div>
            </x-card>
        @endif

        {{-- Processing Status --}}
        @if(! in_array($status, [\App\Enums\UploadState::Idle, \App\Enums\UploadState::Uploading], true))
            <div
                x-data="{
                    source: null,
                    terminalStatuses: ['completed', 'failed', 'cancelled', 'manual_review'],
                    init() {
                        if (! @js($processingId)) return;
                        this.source = new EventSource('/api/media/processing/' + @js($processingId) + '/stream');
                        this.source.addEventListener('progress', (event) => {
                            if (event.data === '</stream>') {
                                this.close();
                                return;
                            }
                            $wire.checkProcessingStatus();
                            try {
                                const payload = JSON.parse(event.data);
                                if (payload && this.terminalStatuses.includes(payload.status)) {
                                    this.close();
                                }
                            } catch (e) {
                                // Non-JSON keepalive — ignore.
                            }
                        });
                        this.source.onerror = () => this.close();
                    },
                    close() {
                        if (this.source) {
                            this.source.close();
                            this.source = null;
                        }
                    },
                    destroy() {
                        this.close();
                    },
                }"
            >
                @include('livewire.media-upload.status')
            </div>
        @endif

        <x-slot:sidebar>
            <x-card heading="Upload notes">
                <div class="space-y-3 text-sm text-gray-600">
                    <p><strong>Audio only</strong> for a clean sermon recording, <strong>Sermon video</strong> for a pre-edited clip, <strong>Full livestream</strong> for the whole service.</p>
                    <p>Livestream filenames like <code class="rounded bg-gray-100 px-1">2026-02-22 AM.mp4</code> let the pipeline match the recording to its service automatically.</p>
                    <p>You can leave this page once the upload finishes — processing continues in the background and progress is shown on the service page.</p>
                </div>
            </x-card>
        </x-slot:sidebar>
    </x-admin.form-shell>
</div>
