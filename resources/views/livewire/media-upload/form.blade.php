<div
    x-data="mediaUploadController({
        componentId: @js($this->getId()),
        maxFileSizeBytes: @js($maxFileSizeBytes ?? 0),
        maxFileSizeLabel: @js($maxFileSize ?? 'N/A')
    })"
    x-init="init()"
    class="mx-auto max-w-4xl p-6"
>
    {{-- Upload Form --}}
    @if($showUploadForm && !in_array($status, ['processing', 'completed']))
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Upload Media</h2>
            
            {{-- Media Type Selection --}}
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2" for="media-type">
                    Media Type <span class="text-red-500">*</span>
                </label>
                <select 
                    wire:model.live="mediaType"
                    id="media-type" 
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-cbc-teal focus:border-cbc-teal"
                    required
                >
                    <option value="">Select media type...</option>
                    <option value="audio">📄 Audio Only</option>
                    <option value="video">🎬 Sermon Video</option>
                    <option value="livestream">📺 Full Livestream</option>
                </select>
                @error('mediaType')
                    <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror

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

            @if($mediaType === 'video' && config('media-processing.video_auto_trim.enabled', true))
                <div class="mb-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <x-toggle
                        label="Auto-trim to sermon"
                        hint="Use this for roughly clipped sermon videos with a short song, prayer, or notices at the start or end. Leave it off for already-clean sermon clips. Ambiguous runs may pause for manual review."
                        wire:model.live="autoTrimVideo" />

                    <div class="mt-3 text-sm text-gray-600">
                        @if($autoTrimVideo)
                            <p><strong>Auto-trim:</strong> Segment recording → Classify sermon bounds → Extract trimmed sermon video → Transcription → AI Analysis</p>
                            <p class="mt-1">Choose <strong>Full Livestream</strong> instead when you are uploading the entire service and want the full livestream workflow.</p>
                        @else
                            <p><strong>Whole sermon video:</strong> Upload the video as-is when it already starts and ends in the right place.</p>
                        @endif
                    </div>
                </div>
            @endif

            {{-- File Upload Area --}}
            @if($mediaType)
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        @if($mediaType === 'audio')
                            Upload Sermon Audio File <span class="text-red-500">*</span>
                        @elseif($mediaType === 'video')
                            Upload Sermon Video File <span class="text-red-500">*</span>
                        @elseif($mediaType === 'livestream')
                            Upload Full Livestream File <span class="text-red-500">*</span>
                        @else
                            Upload Media File <span class="text-red-500">*</span>
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
                            <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" stroke="currentColor" fill="none" viewBox="0 0 48 48">
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
                                <svg class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
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
                            <span class="mt-4 inline-flex items-center px-4 py-2 bg-cbc-teal text-white rounded-md hover:bg-cbc-teal-dark transition-colors duration-200">
                                Choose File
                            </span>
                        </label>
                    </div>
                    
                    @error('mediaFile')
                        <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <livewire:media-upload.progress
                    :is-uploading="$isUploading"
                    :status="$status"
                    :upload-progress="$uploadProgress"
                    :current-file-name="$originalFileName ?? ($mediaFile ? $mediaFile->getClientOriginalName() : 'file')"
                    :form-component-id="$this->getId()"
                    :key="'media-upload-progress-'.$this->getId()"
                />

                <div class="flex items-center justify-between">
                    <a href="/church/members" wire:navigate class="text-gray-600 hover:text-gray-800 transition-colors duration-200">
                        ← Back to Members Area
                    </a>

                    <p class="text-sm text-gray-500">
                        Processing starts automatically after the upload finishes.
                    </p>
                </div>
            @endif
        </div>
    @endif

    {{-- Processing Status --}}
    @if($showProcessingStatus)
        <div wire:poll.2s="checkProcessingStatus">
            <livewire:media-upload.status
                :processing-id="$processingId"
                :status="$status"
                :current-step="$currentStep"
                :progress-percentage="$progressPercentage"
                :success-message="$successMessage"
                :error-message="$errorMessage"
                :cancelled-message="$cancelledMessage"
                :manual-review-message="$manualReviewMessage"
                :manual-review-url="$manualReviewUrl"
                :form-component-id="$this->getId()"
                :key="'media-upload-status-'.$this->getId()"
            />
        </div>
    @endif
</div>
