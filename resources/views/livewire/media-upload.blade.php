<div
    x-data="{
        isDragOver: false,
        fileModifiedDate: null
    }"
    class="max-w-4xl mx-auto p-6"
>
    {{-- Upload Form --}}
    @if($showUploadForm && $status !== 'processing')
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
                    <option value="audio">📄 Audio Only (MP3, WAV, M4A)</option>
                    <option value="video">🎬 Sermon Video (MP4, MOV, AVI, MKV)</option>
                    <option value="livestream">📺 Full Livestream (MP4, MOV, AVI, MKV)</option>
                </select>
                @error('mediaType')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
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
                                <p class="text-sm text-gray-500">Maximum file size: 5GB</p>
                            @endif
                        </div>

                        <div wire:loading wire:target="mediaFile" class="text-blue-600">
                            <svg class="animate-spin mx-auto h-8 w-8 mb-2" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <p class="text-sm">Validating file...</p>
                        </div>

                        <input
                            wire:model="mediaFile"
                            type="file"
                            id="media-file"
                            class="sr-only"
                            accept="{{ $mediaType === 'audio' ? '.mp3,.wav,.m4a' : '.mp4,.mov,.avi,.mkv' }}"
                            x-on:change="
                                if($event.target.files[0]) {
                                    console.log('File selected:', $event.target.files[0].name, 'Size:', $event.target.files[0].size);
                                    if($event.target.files[0].size > 5*1024*1024*1024) {
                                        alert('File too large! Max 5GB allowed.');
                                        return false;
                                    }
                                    // Extract file modification date
                                    const modDate = new Date($event.target.files[0].lastModified);
                                    fileModifiedDate = modDate.toISOString().split('T')[0];
                                    console.log('File modified date:', fileModifiedDate);
                                    $wire.set('fileModifiedDate', fileModifiedDate);
                                }
                            "
                        />
                        <input type="hidden" wire:model="fileModifiedDate" x-model="fileModifiedDate" />
                        <label for="media-file" class="cursor-pointer">
                            <span class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors duration-200">
                                Choose File
                            </span>
                        </label>
                    </div>
                    
                    @error('mediaFile')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Upload Button --}}
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
        </div>
    @endif

    {{-- Processing Status --}}
    @if($showProcessingStatus)
        <div class="bg-white rounded-lg shadow-md p-6" wire:poll.2s="checkProcessingStatus">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Processing Status</h2>
            
            {{-- Processing ID --}}
            @if($processingId)
                <div class="mb-4 p-3 bg-gray-50 rounded-md">
                    <p class="text-sm text-gray-600">Processing ID: <code class="text-xs bg-white px-2 py-1 rounded">{{ $processingId }}</code></p>
                </div>
            @endif

            {{-- Progress Bar --}}
            <div class="mb-6">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm font-medium text-gray-700">{{ $currentStep ?: 'Initializing...' }}</span>
                    <span class="text-sm text-gray-500">{{ $progressPercentage }}%</span>
                </div>
                
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <div 
                        class="h-3 rounded-full transition-all duration-500 ease-out {{ $status === 'failed' ? 'bg-red-500' : ($status === 'completed' ? 'bg-green-500' : 'bg-blue-500') }}"
                        style="width: {{ $progressPercentage }}%"
                    ></div>
                </div>
            </div>

            {{-- Status Messages --}}
            @if($successMessage)
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                    <div class="flex">
                        <svg class="w-5 h-5 text-green-400 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <p class="text-sm text-green-800 font-medium">Success!</p>
                            <p class="text-sm text-green-700">{{ $successMessage }}</p>
                            @if(isset($processingDetails['sermon_url']))
                                <a href="{{ $processingDetails['sermon_url'] }}" class="text-sm text-green-600 hover:text-green-800 underline">
                                    View processed sermon →
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if($errorMessage)
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
                    <div class="flex">
                        <svg class="w-5 h-5 text-red-400 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <p class="text-sm text-red-800 font-medium">Processing Error</p>
                            <p class="text-sm text-red-700">{{ $errorMessage }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Action Buttons --}}
            <div class="flex justify-between items-center">
                @if($status === 'processing')
                    <button
                        wire:click="cancelProcessing"
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors duration-200"
                    >
                        Cancel Processing
                    </button>
                @else
                    <button
                        wire:click="retryUpload"
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors duration-200"
                    >
                        Upload Another File
                    </button>
                @endif

                @if($status === 'completed')
                    <a href="{{ $processingDetails['sermon_url'] ?? '/christ/sermons' }}" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors duration-200">
                        {{ isset($processingDetails['sermon_url']) ? 'View Sermon' : 'View All Sermons' }}
                    </a>
                @endif
            </div>

        </div>

        {{-- Processing Logs Viewer --}}
        @if($processingId)
            <div class="mt-6">
                <livewire:processing-logs-viewer
                    :processing-id="$processingId"
                    :auto-refresh="$status === 'processing'"
                    :expanded="false"
                    :log-limit="20"
                />
            </div>
        @endif
    @endif
</div>