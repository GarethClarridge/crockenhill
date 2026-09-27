<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Exceptions\VideoProcessingException;
use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Traits\RequiresFfmpeg;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Media\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoExtractionService
{
    use RequiresFfmpeg;

    /**
     * Video codecs the delivered `.mp4` can carry to every browser the site
     * serves. Deliberately narrow: anything else is re-encoded to H.264, the
     * only universally decodable baseline.
     */
    private const DELIVERABLE_VIDEO_CODECS = ['h264'];

    /**
     * Audio codecs an `.mp4` carries natively, so a re-encode can copy them
     * through untouched rather than spending a second generation of lossy
     * compression. Deliberately narrow for the same reason.
     */
    private const MP4_NATIVE_AUDIO_CODECS = ['aac'];

    /** Presentation times read back as text differ from their packets by rounding. */
    private const TIMESTAMP_EPSILON_SECONDS = 0.001;

    /** One frame at 24 fps, the longest frame a cut assumes when it cannot read one. */
    private const DEFAULT_FRAME_SECONDS = 1 / 24;

    /**
     * How far a cut's streams may run from its span. A 40-minute weekly cut ran
     * 60 ms long; the census defects ran to seconds.
     */
    private const LENGTH_TOLERANCE_SECONDS = 0.1;

    /** One AAC frame at 44.1 kHz, the step an output-seek copy of the sound lands on. */
    private const AUDIO_FRAME_SECONDS = 0.025;

    /** Packets read either side of a span, enough to reach its keyframes. */
    private const PACKET_WINDOW_PAD_SECONDS = 30.0;

    private string $tempDisk;

    private string $permanentDisk;

    private string $audioPath;

    /**
     * Probe answers already paid for, keyed by file identity and question.
     *
     * Every `extractSegmentAsFile()` call asks ffprobe the same two or three
     * questions about the same unchanged source, and a section-candidate run
     * calls it once per section. Each answer costs a process spawn and a header
     * read across the staging drive. The key carries size and mtime as well as
     * the path so a rewritten file cannot be answered from a stale entry.
     *
     * @var array<string, string|float|null>
     */
    private array $probeCache = [];

    public function __construct(
        private readonly AudioCompressionService $audioCompressor,
        private readonly StorageAdapterHelper $storageHelper
    ) {
        $this->ffmpeg = $storageHelper->createFFMpeg();

        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
        $this->permanentDisk = config('media-processing.storage.sermon_disk', 'public');
        $this->audioPath = config('media-processing.storage.paths.audio', 'sermons/audio');
    }

    /**
     * Extract video segment with stream copy (no re-encoding) - primary method.
     *
     * @param  string  $inputPath  Path to the original video file
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  array<string, mixed>  $options  Extraction options
     * @return string|UploadedFile Based on options['return_type']
     */
    public function extractSegment(string $inputPath, object $segment, array $options = []): string|UploadedFile
    {
        $returnType = $options['return_type'] ?? 'file_path';
        $outputFilename = $options['output_filename'] ?? null;

        if ($returnType === 'uploaded_file') {
            return $this->extractSegmentAsUpload($inputPath, $segment, $outputFilename);
        }

        return $this->extractSegmentAsFile($inputPath, $segment, $outputFilename);
    }

    /**
     * Extract video segment and return as file path (for storage operations).
     *
     * @param  string  $inputPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @param  bool  $deferRender  Smart-cut a source the bitrate rule would re-encode, leaving
     *                             {@see renderForDelivery()} to re-encode the stored cut later
     * @return string The relative path to the extracted video file
     *
     * @throws VideoProcessingException If output file was not created
     * @throws \Exception For underlying system or FFmpeg errors
     */
    public function extractSegmentAsFile(string $inputPath, object $segment, ?string $outputFilename = null, bool $deferRender = false): string
    {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        try {
            // Use Laravel storage disk for consistency with other file operations
            $tempDisk = config('media-processing.storage.temp_disk', 'local');
            $relativePath = 'temp/'.Str::uuid().'.mp4';
            $tempPath = Storage::disk($tempDisk)->path($relativePath);

            // Ensure temp directory exists using storage disk
            Storage::disk($tempDisk)->makeDirectory(dirname($relativePath));

            /**
             * A stream copy inherits the source bitrate, which is right for the
             * current recording setup but wasteful for camera-original material.
             * Deciding here rather than in the caller keeps the weekly upload and
             * the historic import on one rule.
             */
            if ($this->shouldReencodeSource($inputPath, $deferRender)) {
                return $this->extractSegmentWithReencoding($inputPath, $segment, $outputFilename);
            }

            $plan = $this->smartCutPlan($inputPath, (float) $startTime, (float) $endTime);

            if ($plan === null) {
                return $this->extractSegmentWithReencoding($inputPath, $segment, $outputFilename);
            }

            try {
                $this->writeSmartCut($inputPath, (float) $startTime, $plan, $tempPath);
            } catch (VideoProcessingException $exception) {
                Log::warning('Smart cut failed, attempting fallback to re-encoding', [
                    'input_path' => $inputPath,
                    'start_time' => $startTime,
                    'error' => $exception->getMessage(),
                ]);
                Storage::disk($tempDisk)->delete($relativePath);

                return $this->extractSegmentWithReencoding($inputPath, $segment, $outputFilename);
            }

            // Check if file was created - Storage::exists expects a disk-relative path
            if (! $this->fileExists($relativePath, $tempDisk)) {
                throw new VideoProcessingException('Output file was not created: '.$tempPath);
            }

            if (! $this->cutIsAligned($tempPath, $plan['expected_duration'], $plan['frame_seconds'])) {
                Log::warning('Smart cut did not start picture and sound together for its span; re-encoding instead', [
                    'input_path' => $inputPath,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);
                Storage::disk($tempDisk)->delete($relativePath);

                return $this->extractSegmentWithReencoding($inputPath, $segment, $outputFilename);
            }

            Log::info('Video segment extracted with a smart cut (copied between its keyframes)', [
                'input_path' => $inputPath,
                'output_path' => $tempPath,
                'relative_path' => $relativePath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'keyframe' => $plan['keyframe'],
                'last_keyframe' => $plan['last_keyframe'],
                'copied_frames' => $plan['copy_frames'],
                'output_size' => $this->getFileSize($relativePath, $tempDisk),
            ]);

            return $relativePath;

        } catch (\Exception $e) {
            Log::error('Failed to extract video segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            throw $e;
        }
    }

    /**
     * Extract and hard-join multiple spans using FFmpeg concat demuxer.
     *
     * @param  string  $inputPath  Absolute path to the source video
     * @param  array<int, array{start_time: float, end_time: float}>  $segments  List of spans to join
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return string The relative path to the concatenated video file
     *
     * @throws VideoProcessingException If concatenation fails or no valid segments provided
     */
    public function extractConcatenatedSegmentAsFile(
        string $inputPath,
        array $segments,
        ?string $outputFilename = null,
        bool $deferRender = false,
    ): string {
        $normalizedSegments = collect($segments)
            ->filter(fn (array $segment): bool => $segment['end_time'] > $segment['start_time'])
            ->values();

        if ($normalizedSegments->isEmpty()) {
            throw new VideoProcessingException('No valid segments provided for concatenation');
        }

        if ($normalizedSegments->count() === 1) {
            $segment = $normalizedSegments->first();

            return $this->extractSegmentAsFile(
                $inputPath,
                (object) [
                    'start_time' => (float) $segment['start_time'],
                    'end_time' => (float) $segment['end_time'],
                ],
                $outputFilename,
                $deferRender,
            );
        }

        $tempDisk = config('media-processing.storage.temp_disk', 'local');
        $concatFileRelativePath = 'temp/concat/'.Str::uuid().'.txt';
        $concatFileAbsolutePath = Storage::disk($tempDisk)->path($concatFileRelativePath);
        $outputRelativePath = 'temp/'.($outputFilename ?? Str::uuid().'.mp4');
        $outputAbsolutePath = Storage::disk($tempDisk)->path($outputRelativePath);
        $clipRelativePaths = [];

        Storage::disk($tempDisk)->makeDirectory(dirname($concatFileRelativePath));
        Storage::disk($tempDisk)->makeDirectory(dirname($outputRelativePath));

        try {
            foreach ($normalizedSegments as $index => $segment) {
                $clipRelativePaths[] = $this->extractSegmentAsFile(
                    $inputPath,
                    (object) [
                        'start_time' => (float) $segment['start_time'],
                        'end_time' => (float) $segment['end_time'],
                    ],
                    'concat-part-'.$index.'-'.Str::uuid().'.mp4',
                    $deferRender,
                );
            }

            $concatListContent = $this->buildConcatListContent($clipRelativePaths, $tempDisk);
            file_put_contents($concatFileAbsolutePath, $concatListContent);

            $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path');
            $clipAbsolutePaths = array_map(
                static fn (string $clipRelativePath): string => Storage::disk($tempDisk)->path($clipRelativePath),
                $clipRelativePaths,
            );

            /**
             * Each part now opens on a short re-encode, so its parameter sets differ
             * from the copied frames after it. The MPEG-TS join carries them in-band
             * before every keyframe; a copied `.mp4` join keeps only the first
             * part's, which a stricter decoder applies to frames they do not fit.
             */
            try {
                $this->joinThroughTransportStream($clipAbsolutePaths, $outputAbsolutePath, withAudio: true);
                $concatReturnCode = 0;
            } catch (VideoProcessingException $exception) {
                $concatReturnCode = 1;
                Log::warning('FFmpeg concat stream copy failed; retrying with re-encode fallback', [
                    'error' => $exception->getMessage(),
                ]);
            }

            if ($concatReturnCode !== 0) {

                $fallbackCommand = [
                    $ffmpegPath,
                    '-f', 'concat',
                    '-safe', '0',
                    '-i', escapeshellarg($concatFileAbsolutePath),
                    '-c:v', 'libx264',
                    '-c:a', 'aac',
                    '-y',
                    escapeshellarg($outputAbsolutePath),
                ];

                $fallbackCommandString = implode(' ', $fallbackCommand);
                exec($fallbackCommandString.' 2>&1', $fallbackOutput, $fallbackReturnCode);

                if ($fallbackReturnCode !== 0) {
                    throw new VideoProcessingException('FFmpeg concat failed: '.implode("\n", $fallbackOutput));
                }
            }

            if (! $this->fileExists($outputRelativePath, $tempDisk)) {
                throw new VideoProcessingException('Concatenated output file was not created');
            }

            $partSeconds = array_map(
                fn (string $clipAbsolutePath): ?float => $this->cutStreams($clipAbsolutePath)['video']['duration'] ?? null,
                $clipAbsolutePaths,
            );

            if (! in_array(null, $partSeconds, true)
                && ! $this->cutIsAligned(
                    $outputAbsolutePath,
                    array_sum($partSeconds),
                    self::DEFAULT_FRAME_SECONDS,
                    self::LENGTH_TOLERANCE_SECONDS * count($partSeconds),
                )
            ) {
                throw new VideoProcessingException('Joined spans do not start picture and sound together for the length of their parts');
            }

            return $outputRelativePath;
        } finally {
            foreach ($clipRelativePaths as $clipRelativePath) {
                Storage::disk($tempDisk)->delete($clipRelativePath);
            }

            if (file_exists($concatFileAbsolutePath)) {
                unlink($concatFileAbsolutePath);
            }
        }
    }

    /**
     * Extract video segment and return as UploadedFile (for processing pipelines).
     *
     * @param  string  $inputPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return UploadedFile The extracted segment as a Laravel UploadedFile
     *
     * @throws VideoProcessingException If extraction fails or times are invalid
     */
    public function extractSegmentAsUpload(string $inputPath, object $segment, ?string $outputFilename = null): UploadedFile
    {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        if ($startTime >= $endTime) {
            throw new VideoProcessingException('Invalid segment times: start time must be less than end time');
        }

        $duration = $endTime - $startTime;

        Log::info('Extracting video segment as UploadedFile', [
            'input_path' => $inputPath,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
        ]);

        // Generate unique filename for extracted segment
        $segmentFilename = 'sermon_segment_'.Str::uuid().'.mp4';
        $segmentPath = 'temp/'.$segmentFilename;
        $fullSegmentPath = Storage::disk($this->tempDisk)->path($segmentPath);

        // Ensure temp directory exists
        $directory = dirname($fullSegmentPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        try {
            // Try stream copy first
            $extractedPath = $this->extractSegmentAsFile($inputPath, $segment, $outputFilename);

            // Move to expected location if needed
            if ($extractedPath !== $fullSegmentPath) {
                rename($extractedPath, $fullSegmentPath);
            }

            Log::info('Video segment extracted successfully', [
                'original_video' => $inputPath,
                'extracted_segment' => $fullSegmentPath,
                'segment_size' => filesize($fullSegmentPath),
                'duration' => $duration,
            ]);

            // Create UploadedFile from the extracted segment
            $originalBasename = pathinfo($inputPath, PATHINFO_FILENAME);
            $uploadedFile = new UploadedFile(
                $fullSegmentPath,
                $originalBasename.'_sermon_segment.mp4',
                'video/mp4',
                null,
                true // Mark as test file to skip validation
            );

            return $uploadedFile;

        } catch (\Exception $e) {
            // Clean up partial file if extraction failed
            if (file_exists($fullSegmentPath)) {
                unlink($fullSegmentPath);
            }

            Log::error('Failed to extract video segment as UploadedFile', [
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw new VideoProcessingException('Failed to extract video segment: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * FFmpeg's seek arguments for a copy of the sound, split either side of `-i`.
     *
     * Only the sound uses these, where every packet stands alone and an output
     * seek trims to within one audio frame. The picture copy must not: an output
     * seek drops packets by decode time, so a keyframe followed by B-frames is
     * dropped and the copy starts a whole GOP late (see writeSmartCut()).
     *
     * The cost of asking for it naively is that FFmpeg demuxes the whole file up
     * to the cut point first. Measured against a 4.7 GiB source, a 300 s copy at
     * offset 2400 s took 60 s, of which under a second was the copying — and
     * `prepare_section_publication_candidates` pays it once per section, on songs
     * averaging 29 minutes into a service.
     *
     * So seek twice. A coarse input seek lands a fixed pad short of the target,
     * skipping the prefix read; the fine output seek then trims that pad exactly
     * as before, and only the pad is ever demuxed and discarded. The pad only has
     * to exceed the source's GOP length for the coarse seek to land before the
     * keyframe the output seek would have chosen, at which point the two-stage
     * output is not merely equivalent but *byte-identical*: verified over three
     * corpus sources at offsets of 600 s, 2400 s and 3600 s, where the same
     * request took 60 s one way and 0.58 s the other.
     *
     * @return array{input: list<string>, output: list<string>}
     */
    private function streamCopySeekArguments(float $startTime): array
    {
        $pad = (float) config('media-processing.video_extraction.copy_seek_prefix_seconds', 30.0);

        // Too close to the start for a coarse seek to buy anything, or the pad is
        // switched off: keep the single output seek this branch has always used.
        if ($pad <= 0.0 || $startTime <= $pad) {
            return ['input' => [], 'output' => ['-ss', (string) $startTime]];
        }

        return [
            'input' => ['-ss', (string) ($startTime - $pad)],
            'output' => ['-ss', (string) $pad],
        ];
    }

    /**
     * Fallback method using re-encoding when stream copy fails
     */
    private function extractSegmentWithReencoding(string $inputPath, object $segment, ?string $outputFilename = null): string
    {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;
        $duration = $endTime - $startTime;

        Log::info('Using re-encoding for video extraction', [
            'input_path' => $inputPath,
            'start_time' => $startTime,
            'duration' => $duration,
        ]);

        /**
         * This writes through the configured temp disk and returns a disk-relative
         * path, matching {@see extractSegmentAsFile()}. It previously wrote to
         * storage_path('app/temp') and returned an absolute path, which ignored
         * `media-processing.storage.temp_disk` and handed callers a path shape
         * they store verbatim in `processing_log.video_file_path`.
         */
        $tempDisk = config('media-processing.storage.temp_disk', 'local');
        $relativePath = 'temp/'.Str::uuid().'.mp4';
        $tempPath = Storage::disk($tempDisk)->path($relativePath);

        Storage::disk($tempDisk)->makeDirectory(dirname($relativePath));

        try {
            $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path');
            $workDirectory = $this->makeWorkDirectory($tempPath);

            try {
                $videoPath = "{$workDirectory}/video.mp4";

                /**
                 * `-ss` precedes `-i` so FFmpeg seeks the input rather than decoding
                 * and discarding everything before the start point. Since FFmpeg 2.1
                 * an input seek still decodes from the preceding keyframe, so a
                 * re-encode stays frame-exact — the output is byte-identical, it just
                 * skips work that grows with the segment's offset.
                 *
                 * That holds only for encoded streams. An input seek starts every
                 * *copied* stream at the preceding keyframe, so the picture is cut
                 * here on its own and the sound beside it: copied with the audio,
                 * 23 song clips carried the item before them.
                 */
                $this->runFfmpeg([
                    $ffmpegPath,
                    '-ss', $this->seconds((float) $startTime),
                    '-i', escapeshellarg($inputPath),
                    '-t', $this->seconds((float) $duration),
                    '-map', '0:v:0',
                    '-an',
                    ...$this->videoEncoderArguments(),
                    '-avoid_negative_ts', 'make_zero',
                    '-y', escapeshellarg($videoPath),
                ], 're-encode');

                $expected = $this->expectedCut($inputPath, (float) $startTime, (float) $endTime);

                $this->muxWithSourceAudio($inputPath, (float) $startTime, $expected['expected_duration'], $videoPath, $tempPath, $workDirectory);
            } finally {
                $this->removeWorkDirectory($workDirectory);
            }

            if (! $this->fileExists($relativePath, $tempDisk)) {
                throw new VideoProcessingException('Re-encoded output file was not created: '.$tempPath);
            }

            if (! $this->cutIsAligned($tempPath, $expected['expected_duration'], $expected['frame_seconds'])) {
                throw new VideoProcessingException(sprintf(
                    'Re-encoded cut does not start picture and sound together for its span (%s to %s s): %s',
                    $startTime,
                    $endTime,
                    $tempPath,
                ));
            }

            Log::info('Video segment extracted with re-encoding', [
                'input_path' => $inputPath,
                'output_path' => $tempPath,
                'relative_path' => $relativePath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'output_size' => $this->getFileSize($relativePath, $tempDisk),
            ]);

            return $relativePath;

        } catch (\Exception $e) {
            Log::error('Re-encoding extraction failed', [
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            throw $e;
        }
    }

    /**
     * Re-encode the picture of a cut whose render was deferred, in place.
     *
     * The corpus re-run cuts a source above the bitrate threshold with a smart cut
     * and renders it here later, from the stored cut rather than the source, so a
     * render needs neither the recording nor its staging context (plan §4.0, "cut
     * now, render later"). Only the picture is encoded: the sound is copied, which
     * keeps a published song's enhanced sound, and timestamps pass through, so the
     * cut stays aligned.
     *
     * The cut is replaced only once the render has kept every frame, the sound's
     * exact packets and the cut's length, and has come under the threshold; a
     * render that stays above it would be deferred for ever. A cut already at or
     * under the threshold, or whose bitrate cannot be read, is left alone, so a
     * repeat render skips what it has done.
     *
     * @param  string  $path  Absolute path to a stored cut on a local disk
     * @return bool Whether the cut was re-encoded
     *
     * @throws VideoProcessingException When the render fails or does not verify; the cut is untouched
     */
    public function renderForDelivery(string $path): bool
    {
        $thresholdMbps = (float) config('media-processing.video_extraction.reencode_above_mbps', 0.0);
        $cutMbps = $this->readSourceBitrateMbps($path);

        if ($thresholdMbps <= 0.0 || $cutMbps === null || $cutMbps <= $thresholdMbps) {
            return false;
        }

        $workDirectory = $this->makeWorkDirectory($path);
        $renderedPath = "{$workDirectory}/rendered.".pathinfo($path, PATHINFO_EXTENSION);

        try {
            $this->runFfmpeg([
                (string) config('media-processing.ffmpeg.ffmpeg_path'),
                '-i', escapeshellarg($path),
                '-map', '0:v:0',
                '-map', '0:a?',
                ...$this->videoEncoderArguments(),
                '-c:a', 'copy',
                '-movflags', '+faststart',
                '-y', escapeshellarg($renderedPath),
            ], 'render');

            $refusal = $this->renderRefusal($path, $renderedPath, $thresholdMbps);

            if ($refusal !== null) {
                throw new VideoProcessingException("Render of {$path} refused: {$refusal}");
            }

            rename($renderedPath, $path);
        } finally {
            $this->removeWorkDirectory($workDirectory);
        }

        Log::info('Rendered a deferred cut for delivery', [
            'path' => $path,
            'cut_mbps' => round($cutMbps, 2),
            'rendered_bytes' => @filesize($path) ?: null,
        ]);

        return true;
    }

    /**
     * Why a render may not replace its cut, or null when it may.
     */
    private function renderRefusal(string $cutPath, string $renderedPath, float $thresholdMbps): ?string
    {
        $cut = $this->cutStreams($cutPath);
        $rendered = $this->cutStreams($renderedPath);

        if (! isset($cut['video'], $rendered['video'])) {
            return 'the picture could not be measured';
        }

        if (abs($cut['video']['duration'] - $rendered['video']['duration']) > self::LENGTH_TOLERANCE_SECONDS) {
            return sprintf('the picture ran %.3f s, not %.3f s', $rendered['video']['duration'], $cut['video']['duration']);
        }

        $cutFrames = $this->videoPacketCount($cutPath);

        if ($cutFrames === null || $cutFrames !== $this->videoPacketCount($renderedPath)) {
            return 'the frame count changed';
        }

        if (isset($cut['audio']) && $this->audioPacketHash($cutPath) !== $this->audioPacketHash($renderedPath)) {
            return 'the sound changed';
        }

        $renderedMbps = $this->readSourceBitrateMbps($renderedPath);

        if ($renderedMbps === null || $renderedMbps > $thresholdMbps) {
            return sprintf('it is still above the %s Mbps threshold', $thresholdMbps);
        }

        return null;
    }

    private function videoPacketCount(string $path): ?int
    {
        $output = [];
        exec(implode(' ', [
            (string) config('media-processing.ffmpeg.ffprobe_path'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-count_packets',
            '-show_entries', 'stream=nb_read_packets',
            '-of', 'default=nw=1:nk=1',
            escapeshellarg($path),
        ]).' 2>/dev/null', $output, $returnCode);

        $count = trim(implode('', $output));

        return $returnCode === 0 && ctype_digit($count) ? (int) $count : null;
    }

    /**
     * A digest of the sound's packets, which a copy carries unchanged.
     */
    private function audioPacketHash(string $path): ?string
    {
        $output = [];
        exec(implode(' ', [
            (string) config('media-processing.ffmpeg.ffmpeg_path'),
            '-v', 'error',
            '-i', escapeshellarg($path),
            '-map', '0:a:0',
            '-c', 'copy',
            '-f', 'hash',
            '-hash', 'sha256',
            '-',
        ]).' 2>/dev/null', $output, $returnCode);

        $hash = trim(implode('', $output));

        return $returnCode === 0 && $hash !== '' ? $hash : null;
    }

    /**
     * Whether this source must be re-encoded rather than stream-copied.
     *
     * Two independent reasons, checked in that order of severity. A video codec
     * the delivery container cannot carry is a correctness failure: VP9 muxes
     * into `.mp4` without any error, but AVFoundation reports the result
     * unplayable, so Safari and every iOS browser silently refuse it. A bitrate
     * far above what delivery needs is merely wasteful.
     *
     * A cut that defers its render skips the bitrate rule: it is smart-cut now
     * and {@see renderForDelivery()} re-encodes it later. The codec rule still
     * applies, because a smart cut copies the source's own codec.
     *
     * Both rules act only on positive information. An unreadable codec, an
     * unreadable bitrate, an unset threshold or a probe error all leave the
     * existing stream-copy behaviour in place, because a blind re-encode of
     * every source is a worse default than shipping the occasional oversized
     * extract.
     */
    private function shouldReencodeSource(string $inputPath, bool $deferRender = false): bool
    {
        $videoCodec = $this->probeStreamCodec($inputPath, 'v');

        if ($videoCodec !== null && ! in_array($videoCodec, self::DELIVERABLE_VIDEO_CODECS, true)) {
            Log::info('Re-encoding video extract: source video codec is not deliverable', [
                'input_path' => $inputPath,
                'source_video_codec' => $videoCodec,
                'deliverable_codecs' => self::DELIVERABLE_VIDEO_CODECS,
            ]);

            return true;
        }

        if ($deferRender) {
            return false;
        }

        $thresholdMbps = (float) config('media-processing.video_extraction.reencode_above_mbps', 0.0);

        if ($thresholdMbps <= 0.0) {
            return false;
        }

        $bitrateMbps = $this->probeSourceBitrateMbps($inputPath);

        if ($bitrateMbps === null || $bitrateMbps <= $thresholdMbps) {
            return false;
        }

        Log::info('Re-encoding video extract: source bitrate exceeds threshold', [
            'input_path' => $inputPath,
            'source_bitrate_mbps' => round($bitrateMbps, 2),
            'threshold_mbps' => $thresholdMbps,
        ]);

        return true;
    }

    /**
     * Where a smart cut may copy, or null when the whole span must be re-encoded.
     *
     * A stream copy starts the picture at the next keyframe while the sound starts
     * on time: 89 historic song clips opened on a frozen frame and 12 sermons lost
     * over 3 s of picture (ruling 3a, 2026-09-14). So only the frames from the
     * first keyframe at or after the start up to the last keyframe at or before
     * the end are copied, counted exactly because a copy's `-t` overshoots by the
     * B-frames it has reordered. The stretch either side is re-encoded.
     *
     * Refused, so the caller re-encodes: a source whose packets cannot be read; a
     * span holding no whole GOP; and a source with leading pictures (open GOP),
     * whose first frames after a keyframe reference the GOP before it. A join
     * across those decoded as garbage in testing, 226 frames of a 34 s cut. Every
     * historic and weekly H.264 source probed was closed-GOP.
     *
     * @return array{keyframe: float, last_keyframe: float, copy_frames: int, opening_start: float, opening_frames: int, closing_frames: int, cut_end: float, expected_duration: float, frame_seconds: float}|null
     */
    private function smartCutPlan(string $inputPath, float $startTime, float $endTime): ?array
    {
        $packets = $this->videoPackets($inputPath, $startTime, $endTime);

        if ($packets === []) {
            return null;
        }

        $geometry = $this->cutGeometry($packets, $startTime, $endTime);
        $keyframe = null;
        $lastKeyframe = null;
        $latestKeyframe = null;

        foreach ($packets as [$timestamp, $isKeyframe]) {
            if ($isKeyframe) {
                $latestKeyframe = $timestamp;

                if ($keyframe === null && $timestamp >= $startTime - self::TIMESTAMP_EPSILON_SECONDS) {
                    $keyframe = $timestamp;
                }

                if ($timestamp <= $geometry['cut_end'] + self::TIMESTAMP_EPSILON_SECONDS) {
                    $lastKeyframe = $timestamp;
                }

                continue;
            }

            if ($latestKeyframe !== null && $timestamp < $latestKeyframe - self::TIMESTAMP_EPSILON_SECONDS) {
                return null;
            }
        }

        if ($keyframe === null || $lastKeyframe === null || $lastKeyframe <= $keyframe + self::TIMESTAMP_EPSILON_SECONDS) {
            return null;
        }

        $framesFrom = static fn (float $from, float $until): array => array_values(array_filter(
            array_column($packets, 0),
            static fn (float $timestamp): bool => $timestamp >= $from - self::TIMESTAMP_EPSILON_SECONDS
                && $timestamp < $until - self::TIMESTAMP_EPSILON_SECONDS,
        ));

        $openingFrames = $framesFrom($startTime, $keyframe);
        $closingFrames = $framesFrom($lastKeyframe, $geometry['cut_end']);

        return [
            'keyframe' => $keyframe,
            'last_keyframe' => $lastKeyframe,
            'copy_frames' => count($framesFrom($keyframe, $lastKeyframe)),
            'opening_start' => $openingFrames === [] ? $keyframe : min($openingFrames),
            'opening_frames' => count($openingFrames),
            'closing_frames' => count($closingFrames),
            ...$geometry,
        ];
    }

    /**
     * How long a cut of the span should run, and how long one frame lasts,
     * read from the source's own packets.
     *
     * @return array{cut_end: float, expected_duration: float, frame_seconds: float}
     */
    private function expectedCut(string $inputPath, float $startTime, float $endTime): array
    {
        $packets = $this->videoPackets($inputPath, $startTime, $endTime);

        if ($packets === []) {
            return [
                'cut_end' => $endTime,
                'expected_duration' => max(0.0, $endTime - $startTime),
                'frame_seconds' => self::DEFAULT_FRAME_SECONDS,
            ];
        }

        return $this->cutGeometry($packets, $startTime, $endTime);
    }

    /**
     * A span that runs past the end of its source can only hold what the source
     * holds, so the cut ends at the earlier of the two.
     *
     * @param  non-empty-list<array{0: float, 1: bool}>  $packets
     * @return array{cut_end: float, expected_duration: float, frame_seconds: float}
     */
    private function cutGeometry(array $packets, float $startTime, float $endTime): array
    {
        $timestamps = array_column($packets, 0);
        sort($timestamps);

        $count = count($timestamps);
        $frameSeconds = $count > 1
            ? ($timestamps[$count - 1] - $timestamps[0]) / ($count - 1)
            : self::DEFAULT_FRAME_SECONDS;
        $cutEnd = min($endTime, $timestamps[$count - 1] + $frameSeconds);

        return [
            'cut_end' => $cutEnd,
            'expected_duration' => max(0.0, $cutEnd - $startTime),
            'frame_seconds' => $frameSeconds > 0.0 ? $frameSeconds : self::DEFAULT_FRAME_SECONDS,
        ];
    }

    /**
     * The source's video packets around a span, in decode order, as
     * `[presentation time, is keyframe]`, timed from the start of the file.
     *
     * Read over the span and a pad either side only, which cost about a second
     * for a 40-minute sermon on a weekly recording.
     *
     * ffprobe reads and reports the container's own timestamps, while a span and
     * FFmpeg's `-ss` count from the file's first timestamp. On a source that does
     * not start at zero the two disagree by that start, which planned a cut 1.4 s
     * off its keyframes, so both the window and the answers are shifted by it.
     *
     * @return list<array{0: float, 1: bool}>
     */
    private function videoPackets(string $inputPath, float $startTime, float $endTime): array
    {
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');

        if (! is_string($ffprobePath) || $ffprobePath === '') {
            return [];
        }

        $fileStart = $this->probeFileStartSeconds($inputPath);

        $command = implode(' ', [
            $ffprobePath,
            '-v', 'error',
            '-read_intervals', escapeshellarg(sprintf(
                '%s%%%s',
                $this->seconds($fileStart + max(0.0, $startTime - self::PACKET_WINDOW_PAD_SECONDS)),
                $this->seconds($fileStart + $endTime + self::PACKET_WINDOW_PAD_SECONDS),
            )),
            '-select_streams', 'v:0',
            '-show_entries', 'packet=pts_time,flags',
            '-of', 'csv=p=0',
            escapeshellarg($inputPath),
        ]);

        $output = [];
        exec($command.' 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $packets = [];

        foreach ($output as $line) {
            [$timestamp, $flags] = array_pad(explode(',', trim($line), 2), 2, '');

            if (is_numeric($timestamp)) {
                $packets[] = [(float) $timestamp - $fileStart, str_contains($flags, 'K')];
            }
        }

        return $packets;
    }

    /**
     * The file's first timestamp, from which FFmpeg counts `-ss`, cached like the
     * other probes. Zero when it cannot be read, which is what most sources hold.
     */
    private function probeFileStartSeconds(string $inputPath): float
    {
        $key = $this->probeCacheKey($inputPath, 'format:start_time');

        if ($key !== null && array_key_exists($key, $this->probeCache)) {
            return (float) $this->probeCache[$key];
        }

        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');
        $start = 0.0;

        if (is_string($ffprobePath) && $ffprobePath !== '') {
            $output = [];
            exec(implode(' ', [
                $ffprobePath,
                '-v', 'error',
                '-show_entries', 'format=start_time',
                '-of', 'default=nw=1:nk=1',
                escapeshellarg($inputPath),
            ]).' 2>/dev/null', $output, $returnCode);

            $read = trim(implode('', $output));
            $start = $returnCode === 0 && is_numeric($read) ? (float) $read : 0.0;
        }

        if ($key !== null) {
            $this->probeCache[$key] = $start;
        }

        return $start;
    }

    /**
     * Write a smart cut of the span: the opening re-encoded up to the first
     * keyframe, the whole GOPs after it copied, the closing re-encoded from the
     * last keyframe, the pieces joined, and the sound cut beside them.
     *
     * @param  array{keyframe: float, last_keyframe: float, copy_frames: int, opening_start: float, opening_frames: int, closing_frames: int, cut_end: float, expected_duration: float, frame_seconds: float}  $plan
     *
     * @throws VideoProcessingException
     */
    private function writeSmartCut(string $inputPath, float $startTime, array $plan, string $outputPath): void
    {
        $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path');
        $workDirectory = $this->makeWorkDirectory($outputPath);

        try {
            $parts = [];

            /*
             * Every piece seeks half a frame before its first frame and is counted
             * in frames, never timed. A seek or `-t` falling on a frame's own time
             * kept or lost that frame on rounding alone: on a source starting at
             * 1.378 s the opening took the keyframe too and showed it twice, and a
             * `-t` ending half a frame past the last frame still dropped it.
             */
            $halfFrame = $plan['frame_seconds'] / 2;

            if ($plan['opening_frames'] > 0) {
                $parts[] = $this->encodeVideoPart($inputPath, $plan['opening_start'] - $halfFrame, $plan['opening_frames'], "{$workDirectory}/opening.mp4");
            }

            /*
             * An input seek, never an output one. An output seek on a copy drops
             * packets by decode time, and a keyframe followed by B-frames decodes
             * before it shows: the keyframe at 15.00 s decodes at 14.92 s, so it
             * was dropped and the copy began a whole GOP late while running exactly
             * as long. An input seek lands on the keyframe at or before its target,
             * and half a frame past a keyframe that is always the keyframe itself.
             */
            $copiedPath = "{$workDirectory}/copied.mp4";

            $this->runFfmpeg([
                $ffmpegPath,
                '-ss', $this->seconds($plan['keyframe'] + $halfFrame),
                '-i', escapeshellarg($inputPath),
                '-map', '0:v:0',
                '-an',
                '-frames:v', (string) $plan['copy_frames'],
                '-c', 'copy',
                '-avoid_negative_ts', 'make_zero',
                '-y', escapeshellarg($copiedPath),
            ], 'stream copy');
            $parts[] = $copiedPath;

            if ($plan['closing_frames'] > 0) {
                $parts[] = $this->encodeVideoPart(
                    $inputPath,
                    $plan['last_keyframe'] - $halfFrame,
                    $plan['closing_frames'],
                    "{$workDirectory}/closing.mp4",
                );
            }

            $videoPath = "{$workDirectory}/video.mp4";
            $this->joinThroughTransportStream($parts, $videoPath, withAudio: false);
            $this->muxWithSourceAudio($inputPath, $startTime, $plan['expected_duration'], $videoPath, $outputPath, $workDirectory);
        } finally {
            $this->removeWorkDirectory($workDirectory);
        }
    }

    /**
     * Re-encode one stretch of picture to join copied frames from the same source.
     *
     * The pixel format and track timescale follow the source so the join changes
     * as little as it can between the encoded and the copied frames.
     *
     * @throws VideoProcessingException
     */
    private function encodeVideoPart(string $inputPath, float $startTime, int $frames, string $outputPath): string
    {
        $pixelFormat = $this->probeStreamEntry($inputPath, 'v', 'pix_fmt');
        $timeBase = $this->probeStreamEntry($inputPath, 'v', 'time_base');
        $timescale = $timeBase !== null && preg_match('#^1/(\d+)$#', $timeBase, $matches) === 1
            ? ['-video_track_timescale', $matches[1]]
            : [];

        $this->runFfmpeg([
            (string) config('media-processing.ffmpeg.ffmpeg_path'),
            '-ss', $this->seconds($startTime),
            '-i', escapeshellarg($inputPath),
            '-frames:v', (string) $frames,
            '-map', '0:v:0',
            '-an',
            ...$this->videoEncoderArguments(),
            ...($pixelFormat !== null ? ['-pix_fmt', escapeshellarg($pixelFormat)] : []),
            '-fps_mode', 'passthrough',
            ...$timescale,
            '-y', escapeshellarg($outputPath),
        ], 'part re-encode');

        return $outputPath;
    }

    /**
     * @return list<string>
     */
    private function videoEncoderArguments(): array
    {
        return [
            '-c:v', 'libx264',
            '-crf', (string) (int) config('media-processing.video_extraction.reencode_crf', 23),
            '-preset', (string) config('media-processing.video_extraction.reencode_preset', 'medium'),
        ];
    }

    /**
     * Join MP4 pieces through MPEG-TS, where each piece's H.264 parameter sets
     * travel in-band before its keyframes. A copied `.mp4` join keeps only the
     * first piece's, and a re-encoded opening's differ from the copied frames.
     *
     * @param  list<string>  $partPaths
     *
     * @throws VideoProcessingException
     */
    private function joinThroughTransportStream(array $partPaths, string $outputPath, bool $withAudio): void
    {
        if (count($partPaths) === 1) {
            rename($partPaths[0], $outputPath);

            return;
        }

        $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path');
        $transportPaths = [];

        try {
            foreach ($partPaths as $index => $partPath) {
                $transportPath = "{$outputPath}.part-{$index}.ts";

                $this->runFfmpeg([
                    $ffmpegPath,
                    '-i', escapeshellarg($partPath),
                    '-c', 'copy',
                    '-bsf:v', 'h264_mp4toannexb',
                    '-f', 'mpegts',
                    '-y', escapeshellarg($transportPath),
                ], 'transport stream remux');

                $transportPaths[] = $transportPath;
            }

            $this->runFfmpeg([
                $ffmpegPath,
                '-i', escapeshellarg('concat:'.implode('|', $transportPaths)),
                '-c', 'copy',
                ...($withAudio ? ['-bsf:a', 'aac_adtstoasc'] : []),
                '-avoid_negative_ts', 'make_zero',
                '-movflags', '+faststart',
                '-y', escapeshellarg($outputPath),
            ], 'transport stream join');
        } finally {
            foreach ($transportPaths as $transportPath) {
                if (file_exists($transportPath)) {
                    unlink($transportPath);
                }
            }
        }
    }

    /**
     * Put the source's sound for the span beside a cut picture.
     *
     * The sound is always cut on its own. AAC, which the `.mp4` carries, is copied
     * with an output seek, which trims it to within one audio frame; anything else
     * is encoded from an exact start. A source whose audio codec cannot be read
     * keeps its picture alone.
     *
     * @throws VideoProcessingException
     */
    private function muxWithSourceAudio(
        string $inputPath,
        float $startTime,
        float $duration,
        string $videoPath,
        string $outputPath,
        string $workDirectory,
    ): void {
        $audioCodec = $this->probeStreamCodec($inputPath, 'a');

        if ($audioCodec === null) {
            rename($videoPath, $outputPath);

            return;
        }

        $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path');
        $audioPath = "{$workDirectory}/audio.m4a";

        if (in_array($audioCodec, self::MP4_NATIVE_AUDIO_CODECS, true)) {
            $seek = $this->streamCopySeekArguments($startTime);

            $this->runFfmpeg([
                $ffmpegPath,
                ...$seek['input'],
                '-i', escapeshellarg($inputPath),
                ...$seek['output'],
                '-t', $this->seconds($duration),
                '-map', '0:a:0',
                '-c:a', 'copy',
                '-y', escapeshellarg($audioPath),
            ], 'audio copy');
        } else {
            $this->runFfmpeg([
                $ffmpegPath,
                '-ss', $this->seconds($startTime),
                '-i', escapeshellarg($inputPath),
                '-t', $this->seconds($duration),
                '-map', '0:a:0',
                '-c:a', 'aac',
                '-y', escapeshellarg($audioPath),
            ], 'audio encode');
        }

        $this->runFfmpeg([
            $ffmpegPath,
            '-i', escapeshellarg($videoPath),
            '-i', escapeshellarg($audioPath),
            '-map', '0:v:0',
            '-map', '1:a:0',
            '-c', 'copy',
            '-movflags', '+faststart',
            '-y', escapeshellarg($outputPath),
        ], 'mux');
    }

    /**
     * Whether a cut starts picture and sound together and runs for its span.
     *
     * This is the measure the §4.1b duration censuses used: each stream's start
     * and length. The defects ran to seconds; the tolerances allow container
     * rounding (a 40-minute weekly cut ran 60 ms long). A cut that cannot be
     * measured is let through, as every rule here acts only on what it can read.
     */
    private function cutIsAligned(
        string $path,
        float $expectedSeconds,
        float $frameSeconds,
        float $lengthTolerance = self::LENGTH_TOLERANCE_SECONDS,
    ): bool {
        $streams = $this->cutStreams($path);

        if (! isset($streams['video'])) {
            Log::warning('Cut alignment could not be measured', ['path' => $path]);

            return true;
        }

        if (abs($streams['video']['duration'] - $expectedSeconds) > $lengthTolerance) {
            return false;
        }

        if (! isset($streams['audio'])) {
            return true;
        }

        return abs($streams['video']['start'] - $streams['audio']['start']) <= $frameSeconds + self::AUDIO_FRAME_SECONDS
            && abs($streams['audio']['duration'] - $expectedSeconds) <= $lengthTolerance;
    }

    /**
     * @return array{video?: array{start: float, duration: float}, audio?: array{start: float, duration: float}}
     */
    private function cutStreams(string $path): array
    {
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');

        if (! is_string($ffprobePath) || $ffprobePath === '') {
            return [];
        }

        $output = [];
        exec(implode(' ', [
            $ffprobePath,
            '-v', 'error',
            '-show_entries', 'stream=codec_type,start_time,duration',
            '-of', 'csv=p=0',
            escapeshellarg($path),
        ]).' 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $streams = [];

        foreach ($output as $line) {
            $fields = explode(',', trim($line));

            if (count($fields) < 3
                || ! in_array($fields[0], ['video', 'audio'], true)
                || isset($streams[$fields[0]])
                || ! is_numeric($fields[1])
                || ! is_numeric($fields[2])
            ) {
                continue;
            }

            $streams[$fields[0]] = ['start' => (float) $fields[1], 'duration' => (float) $fields[2]];
        }

        return $streams;
    }

    /**
     * One stream entry of the source's first video or audio stream, cached like
     * the codec probe, or null when it cannot be read.
     *
     * @param  'a'|'v'  $streamType
     */
    private function probeStreamEntry(string $inputPath, string $streamType, string $entry): ?string
    {
        $key = $this->probeCacheKey($inputPath, "entry:{$streamType}:{$entry}");

        if ($key !== null && array_key_exists($key, $this->probeCache)) {
            /** @var string|null $cached */
            $cached = $this->probeCache[$key];

            return $cached;
        }

        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');
        $value = null;

        if (is_string($ffprobePath) && $ffprobePath !== '') {
            $output = [];
            exec(implode(' ', [
                $ffprobePath,
                '-v', 'error',
                '-select_streams', "{$streamType}:0",
                '-show_entries', "stream={$entry}",
                '-of', 'default=nw=1:nk=1',
                escapeshellarg($inputPath),
            ]).' 2>/dev/null', $output, $returnCode);

            $read = trim(implode('', $output));
            $value = $returnCode === 0 && $read !== '' ? $read : null;
        }

        if ($key !== null) {
            $this->probeCache[$key] = $value;
        }

        return $value;
    }

    /**
     * @param  list<string>  $arguments  Already shell-escaped where needed
     *
     * @throws VideoProcessingException
     */
    private function runFfmpeg(array $arguments, string $step): void
    {
        $output = [];
        exec(implode(' ', $arguments).' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new VideoProcessingException("FFmpeg {$step} failed: ".implode("\n", $output));
        }
    }

    /**
     * Seconds as FFmpeg reads them: fixed-point, never PHP's exponent form.
     */
    private function seconds(float $seconds): string
    {
        return rtrim(rtrim(sprintf('%.6F', $seconds), '0'), '.');
    }

    private function makeWorkDirectory(string $besidePath): string
    {
        $workDirectory = dirname($besidePath).'/cut-'.Str::uuid();

        if (! is_dir($workDirectory)) {
            mkdir($workDirectory, 0755, true);
        }

        return $workDirectory;
    }

    private function removeWorkDirectory(string $workDirectory): void
    {
        foreach (glob("{$workDirectory}/*") ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($workDirectory)) {
            rmdir($workDirectory);
        }
    }

    /**
     * Codec name of the source's first video or audio stream, or null when
     * ffprobe is unavailable, errors, or reports nothing usable.
     *
     * Unlike `format=bit_rate` this answers for the sources that matter most:
     * the historic webm corpus reports no container bitrate at all, yet names
     * its codec readily.
     *
     * @param  'a'|'v'  $streamType
     */
    private function probeStreamCodec(string $inputPath, string $streamType): ?string
    {
        $key = $this->probeCacheKey($inputPath, 'codec:'.$streamType);

        if ($key !== null && array_key_exists($key, $this->probeCache)) {
            /** @var string|null $cached */
            $cached = $this->probeCache[$key];

            return $cached;
        }

        $codec = $this->readStreamCodec($inputPath, $streamType);

        if ($key !== null) {
            $this->probeCache[$key] = $codec;
        }

        return $codec;
    }

    /**
     * The uncached read behind {@see probeStreamCodec()}.
     *
     * @param  'a'|'v'  $streamType
     */
    private function readStreamCodec(string $inputPath, string $streamType): ?string
    {
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');

        if (! is_string($ffprobePath) || $ffprobePath === '') {
            return null;
        }

        $command = implode(' ', [
            $ffprobePath,
            '-v', 'error',
            '-select_streams', "{$streamType}:0",
            '-show_entries', 'stream=codec_name',
            '-of', 'default=nw=1:nk=1',
            escapeshellarg($inputPath),
        ]);

        $output = [];
        exec($command.' 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0) {
            return null;
        }

        $codec = trim(implode('', $output));

        return $codec === '' ? null : $codec;
    }

    /**
     * Overall container bitrate of the source, in Mbps, or null when unreadable.
     */
    private function probeSourceBitrateMbps(string $inputPath): ?float
    {
        $key = $this->probeCacheKey($inputPath, 'bitrate');

        if ($key !== null && array_key_exists($key, $this->probeCache)) {
            /** @var float|null $cached */
            $cached = $this->probeCache[$key];

            return $cached;
        }

        $bitrate = $this->readSourceBitrateMbps($inputPath);

        if ($key !== null) {
            $this->probeCache[$key] = $bitrate;
        }

        return $bitrate;
    }

    /**
     * Identity of a probe answer, or null when the file cannot be stat'd — in
     * which case nothing is cached and the probe runs as it always did, rather
     * than caching against a path whose contents we cannot pin down.
     */
    private function probeCacheKey(string $inputPath, string $question): ?string
    {
        $size = @filesize($inputPath);
        $modified = @filemtime($inputPath);

        if ($size === false || $modified === false) {
            return null;
        }

        return $inputPath.'|'.$size.'|'.$modified.'|'.$question;
    }

    /**
     * The uncached read behind {@see probeSourceBitrateMbps()}.
     */
    private function readSourceBitrateMbps(string $inputPath): ?float
    {
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');

        if (! is_string($ffprobePath) || $ffprobePath === '') {
            return null;
        }

        $command = implode(' ', [
            $ffprobePath,
            '-v', 'error',
            '-show_entries', 'format=bit_rate',
            '-of', 'default=nw=1:nk=1',
            escapeshellarg($inputPath),
        ]);

        exec($command.' 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0) {
            return null;
        }

        $raw = trim(implode('', $output));

        if (! is_numeric($raw) || (float) $raw <= 0.0) {
            return null;
        }

        return (float) $raw / 1_000_000;
    }

    /**
     * Extract audio from a video segment.
     *
     * @param  string  $inputVideoPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  array<string, mixed>  $compressionOptions  Technical options (bitrate, channels)
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return string The storage path to the extracted audio file
     *
     * @throws \Exception If extraction or S3 upload fails
     */
    public function extractAudio(
        string $inputVideoPath,
        object $segment,
        array $compressionOptions = [],
        ?string $outputFilename = null
    ): string {
        try {
            $startTime = $segment->startTime ?? $segment->start_time ?? 0;
            $endTime = $segment->endTime ?? $segment->end_time ?? 0;
            $duration = $endTime - $startTime;

            $outputFilename = $outputFilename ?: Str::uuid().'_sermon.mp3';

            // Get processing paths based on storage type
            $pathInfo = $this->getProcessingOutputPath($outputFilename);
            $processingPath = $pathInfo['processing_path'];
            $permanentPath = $pathInfo['permanent_path'];
            $useS3Processing = $pathInfo['use_temp_processing'];

            /** @var Video $video */
            $video = $this->requireFfmpeg()->open($inputVideoPath);

            $format = new Mp3;

            // Apply compression options if provided
            if (! empty($compressionOptions)) {
                $format->setAudioKiloBitrate($compressionOptions['bitrate'] ?? 128);
                if (isset($compressionOptions['channels'])) {
                    $format->setAudioChannels($compressionOptions['channels']);
                }
            } else {
                $format->setAudioKiloBitrate(128);
            }

            $startTimeCode = TimeCode::fromSeconds($startTime);
            $durationTimeCode = TimeCode::fromSeconds($duration);

            $video->clip($startTimeCode, $durationTimeCode)->save($format, $processingPath);

            // Handle S3 upload if needed
            if ($useS3Processing) {
                $this->uploadToPermanentStorage($processingPath, $permanentPath);
                // Clean up temporary file
                $this->cleanupTemporaryFile($processingPath);
                Log::info('Audio extracted and uploaded to S3', [
                    'input_path' => $inputVideoPath,
                    'permanent_path' => $permanentPath,
                    'start_time' => $startTime,
                    'duration' => $duration,
                    'compression_options' => $compressionOptions,
                ]);
            } else {
                Log::info('Audio extracted from video segment', [
                    'input_path' => $inputVideoPath,
                    'output_path' => $permanentPath,
                    'start_time' => $startTime,
                    'duration' => $duration,
                    'compression_options' => $compressionOptions,
                ]);
            }

            return $permanentPath;

        } catch (\Exception $e) {
            $segmentStart = null;
            if (property_exists($segment, 'startTime')) {
                $segmentStart = $segment->startTime;
            } elseif (property_exists($segment, 'start_time')) {
                $segmentStart = $segment->start_time;
            }

            Log::error('Failed to extract audio from video segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputVideoPath,
                'segment_start' => $segmentStart,
                'compression_options' => $compressionOptions,
            ]);

            throw $e;
        }
    }

    /**
     * Extract optimized audio from segment with compression validation.
     * Delegates to AudioCompressionService; passes its own S3 upload handler.
     *
     * @param  string  $inputVideoPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @param  string|null  $permanentDisk  Optional disk name override
     * @param  string|null  $audioPath  Optional destination directory override
     * @return array{
     *     audio_path: string,
     *     full_path: string,
     *     original_size: int,
     *     final_size: int,
     *     compression_applied: bool,
     *     compression_ratio: float,
     *     valid_for_transcription: bool
     * }
     *
     * @throws \Exception If extraction fails
     */
    public function extractOptimizedAudio(
        string $inputVideoPath,
        object $segment,
        ?string $outputFilename = null,
        ?string $permanentDisk = null,
        ?string $audioPath = null,
    ): array {
        $resolvedPermanentDisk = $permanentDisk ?? $this->permanentDisk;

        return $this->audioCompressor->extractOptimizedAudio(
            $inputVideoPath,
            $segment,
            $outputFilename,
            $resolvedPermanentDisk,
            $audioPath,
            fn (string $localFilePath, string $permanentPath): string => $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $resolvedPermanentDisk)
        );
    }

    /**
     * Upload a local file to permanent storage with exponential-backoff retry.
     */
    private function uploadToPermanentStorage(string $localFilePath, string $permanentPath): string
    {
        return $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $this->permanentDisk);
    }

    private function cleanupTemporaryFile(string $filePath): void
    {
        $this->storageHelper->cleanupTempFile($filePath);
    }

    /**
     * Get the appropriate output path - temporary for S3 disks, direct for local disks.
     *
     * @return array{processing_path: string, permanent_path: string, use_temp_processing: bool}
     */
    private function getProcessingOutputPath(string $filename): array
    {
        return $this->storageHelper->getProcessingOutputPath(
            $filename,
            $this->audioPath,
            $this->permanentDisk,
            $this->tempDisk,
            'temp/audio_extraction'
        );
    }

    private function fileExists(string $filePath, string $disk): bool
    {
        return $this->storageHelper->fileExists($filePath, $disk);
    }

    private function getFileSize(string $filePath, string $disk): int
    {
        return $this->storageHelper->fileSize($filePath, $disk);
    }

    /**
     * @param  array<int, string>  $clipRelativePaths
     */
    private function buildConcatListContent(array $clipRelativePaths, string $disk): string
    {
        $lines = [];

        foreach ($clipRelativePaths as $clipRelativePath) {
            $absolutePath = Storage::disk($disk)->path($clipRelativePath);
            $safeAbsolutePath = str_replace("'", "'\\''", $absolutePath);
            $lines[] = "file '{$safeAbsolutePath}'";
        }

        return implode("\n", $lines)."\n";
    }
}
