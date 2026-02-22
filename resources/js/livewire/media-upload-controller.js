const STALL_TIMEOUT_MS = 5 * 60 * 1000;
const PROGRESS_THROTTLE_MS = 500; // 2 updates per second

const hasMediaFileDetail = (event, componentId) => {
    return event?.detail?.id === componentId && event?.detail?.property === 'mediaFile';
};

const findLivewireComponent = (componentId) => {
    return window.Livewire?.find(componentId) ?? null;
};

export const mediaUploadController = (config = {}) => {
    return {
        isDragOver: false,
        fileModifiedDate: null,
        componentId: config.componentId ?? null,
        maxFileSizeBytes: Number(config.maxFileSizeBytes ?? 0),
        maxFileSizeLabel: config.maxFileSizeLabel ?? 'N/A',
        lastProgressUpdate: 0,
        progressThrottleMs: PROGRESS_THROTTLE_MS,
        uploadTimeout: null,
        lastActivityTime: Date.now(),
        listeners: [],

        init() {
            if (! this.componentId && this.$wire?.__instance?.id) {
                this.componentId = this.$wire.__instance.id;
            }

            this.registerUploadListeners();
        },

        destroy() {
            this.clearUploadTimeout();
            this.removeUploadListeners();
        },

        registerListener(eventName, handler) {
            window.addEventListener(eventName, handler);
            this.listeners.push({ eventName, handler });
        },

        removeUploadListeners() {
            this.listeners.forEach(({ eventName, handler }) => {
                window.removeEventListener(eventName, handler);
            });
            this.listeners = [];
        },

        clearUploadTimeout() {
            if (this.uploadTimeout) {
                clearTimeout(this.uploadTimeout);
                this.uploadTimeout = null;
            }
        },

        resetUploadTimeout() {
            this.clearUploadTimeout();
            this.lastActivityTime = Date.now();

            this.uploadTimeout = setTimeout(() => {
                const elapsed = Date.now() - this.lastActivityTime;

                if (elapsed > STALL_TIMEOUT_MS * 1.5) {
                    this.resetUploadTimeout();

                    return;
                }

                this.$wire.call('handleUploadError', 'Upload stalled. Please check your connection and try again.');

                const component = findLivewireComponent(this.componentId);
                if (component) {
                    component.cancelUpload('mediaFile');
                }
            }, STALL_TIMEOUT_MS);
        },

        registerUploadListeners() {
            this.registerListener('livewire-upload-start', (event) => {
                if (! hasMediaFileDetail(event, this.componentId)) {
                    return;
                }

                this.$wire.set('isUploading', true);
                this.$wire.set('status', 'uploading');
                this.resetUploadTimeout();
            });

            this.registerListener('livewire-upload-progress', (event) => {
                if (! this.$wire.isUploading) {
                    return;
                }

                this.resetUploadTimeout();
                const now = Date.now();

                if (now - this.lastProgressUpdate < this.progressThrottleMs) {
                    return;
                }

                this.lastProgressUpdate = now;
                this.$wire.call('updateUploadProgress', Math.round(event.detail.progress));
            });

            this.registerListener('livewire-upload-finish', (event) => {
                if (! hasMediaFileDetail(event, this.componentId)) {
                    return;
                }

                this.clearUploadTimeout();
                this.$wire.call('uploadComplete');
            });

            this.registerListener('livewire-upload-error', (event) => {
                if (! hasMediaFileDetail(event, this.componentId)) {
                    return;
                }

                this.clearUploadTimeout();
                this.$wire.call('handleUploadError', `Upload failed: ${event.detail.error || 'Unknown error'}`);
            });

            this.registerListener('beforeunload', (event) => {
                if (! this.$wire.isUploading) {
                    return;
                }

                event.preventDefault();
                event.returnValue = 'Upload in progress. Are you sure you want to leave?';

                return event.returnValue;
            });
        },

        handleFileInputChange(event) {
            const file = event.target?.files?.[0];

            if (! file) {
                return;
            }

            if (this.maxFileSizeBytes > 0 && file.size > this.maxFileSizeBytes) {
                window.alert(`File too large! Max ${this.maxFileSizeLabel} allowed.`);
                event.target.value = '';

                return;
            }

            const modifiedDate = new Date(file.lastModified);
            this.fileModifiedDate = modifiedDate.toISOString().split('T')[0];
            this.$wire.set('fileModifiedDate', this.fileModifiedDate);
        },

        cancelUpload() {
            this.clearUploadTimeout();
            this.$wire.call('cancelUpload');

            const component = findLivewireComponent(this.componentId);
            if (component) {
                component.cancelUpload('mediaFile');
            }
        },
    };
};

window.mediaUploadController = mediaUploadController;
