const PROGRESS_THROTTLE_MS = 500; // 2 updates per second

const hasMediaFileDetail = (event, componentId) => {
    return event?.detail?.id === componentId && event?.detail?.property === 'mediaFile';
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
        processingTriggered: false,
        uploadCancelled: false,
        uploadInProgress: false,
        uploadProgressValue: 0,
        currentFileName: null,
        listeners: [],

        init() {
            if (! this.componentId && this.$wire?.__instance?.id) {
                this.componentId = this.$wire.__instance.id;
            }

            this.registerUploadListeners();
        },

        destroy() {
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

        setUploadProgressVisibility(isVisible) {
            const progress = this.$root.querySelector('[data-upload-progress]');

            if (progress) {
                progress.hidden = ! isVisible;
            }
        },

        updateUploadProgressDisplay(progress) {
            const progressBar = this.$root.querySelector('[data-upload-progress-bar]');
            const progressValue = this.$root.querySelector('[data-upload-progress-value]');

            if (progressBar) {
                progressBar.style.width = `${progress}%`;
                progressBar.setAttribute('aria-valuenow', String(progress));
            }

            if (progressValue) {
                progressValue.textContent = String(progress);
            }
        },

        registerUploadListeners() {
            this.registerListener('livewire-upload-start', (event) => {
                if (! hasMediaFileDetail(event, this.componentId)) {
                    return;
                }

                this.processingTriggered = false;
                this.uploadCancelled = false;
                this.uploadInProgress = true;
                this.uploadProgressValue = 0;
                this.setUploadProgressVisibility(true);
                this.updateUploadProgressDisplay(0);
            });

            this.registerListener('livewire-upload-progress', (event) => {
                if (! hasMediaFileDetail(event, this.componentId)) {
                    return;
                }

                this.uploadProgressValue = Math.round(event.detail.progress);
                this.updateUploadProgressDisplay(this.uploadProgressValue);

                if (this.$wire.status !== 'uploading') {
                    return;
                }

                const now = Date.now();

                if (now - this.lastProgressUpdate < this.progressThrottleMs) {
                    return;
                }

                this.lastProgressUpdate = now;
                this.$wire.call('updateUploadProgress', this.uploadProgressValue);
            });

            this.registerListener('livewire-upload-finish', (event) => {
                if (! hasMediaFileDetail(event, this.componentId)) {
                    return;
                }

                if (this.uploadCancelled || this.processingTriggered) {
                    return;
                }

                this.processingTriggered = true;
                this.uploadInProgress = false;
                this.setUploadProgressVisibility(false);
                this.$wire.call('uploadComplete');
            });

            this.registerListener('livewire-upload-error', (event) => {
                if (! hasMediaFileDetail(event, this.componentId)) {
                    return;
                }

                this.processingTriggered = false;
                this.uploadInProgress = false;
                this.setUploadProgressVisibility(false);
                this.$wire.call('handleUploadError', `Upload failed: ${event.detail.error || 'Unknown error'}`);
            });

            this.registerListener('beforeunload', (event) => {
                if (! this.uploadInProgress) {
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
            this.currentFileName = file.name;
            const fileName = this.$root.querySelector('[data-upload-file-name]');

            if (fileName) {
                fileName.textContent = file.name;
            }
            this.fileModifiedDate = modifiedDate.toISOString().split('T')[0];
            this.$wire.set('fileModifiedDate', this.fileModifiedDate);
        },

        cancelUpload() {
            this.uploadCancelled = true;
            this.processingTriggered = false;
            this.uploadInProgress = false;
            this.setUploadProgressVisibility(false);
            this.$wire.$cancelUpload('mediaFile', () => {
                this.$wire.call('cancelUpload');
            });
        },

        handleDrop(event) {
            const file = event.dataTransfer?.files?.[0];

            if (! file) {
                return;
            }

            if (this.maxFileSizeBytes > 0 && file.size > this.maxFileSizeBytes) {
                window.alert(`File too large! Max ${this.maxFileSizeLabel} allowed.`);
                return;
            }

            const modifiedDate = new Date(file.lastModified);
            this.currentFileName = file.name;
            this.fileModifiedDate = modifiedDate.toISOString().split('T')[0];
            this.$wire.set('fileModifiedDate', this.fileModifiedDate);
            this.$wire.upload('mediaFile', file, () => {}, () => {}, (progress) => {});
        },

    };
};

window.mediaUploadController = mediaUploadController;
