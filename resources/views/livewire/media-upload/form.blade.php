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
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
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
                        x-on:drop.prevent="isDragOver = false"
                        x-bind:class="{
                            'border-blue-400 bg-blue-50': isDragOver,
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
                            <span class="text-blue-600 inline-flex items-center py-2">
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
                        <input type="hidden" wire:model="fileModifiedDate" x-model="fileModifiedDate" />
                        <label for="media-file" class="cursor-pointer inline-block align-middle">
                            <span class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors duration-200">
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
                    :key="'media-upload-progress-'.$this->getId()"
                />

                {{-- Upload Button (hidden during upload) --}}
                @if(!$isUploading)
                    <div class="flex justify-between items-center">
                        <a href="/church/members" class="text-gray-600 hover:text-gray-800 transition-colors duration-200">
                            ← Back to Members Area
                        </a>

                        <button
                            wire:click="uploadMedia"
                            wire:loading.attr="disabled"
                            wire:target="uploadMedia"
                            @if(!$mediaFile) disabled @endif
                            class="px-6 py-2 {{ $mediaFile ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-300 cursor-not-allowed' }} text-white rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors duration-200"
                        >
                            <span wire:loading.remove wire:target="uploadMedia">Process Media</span>
                            <span wire:loading wire:target="uploadMedia" class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Processing...
                        </span>
                    </button>
                </div>
                @endif
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
                :key="'media-upload-status-'.$this->getId()"
            />
        </div>
    @endif
</div>
