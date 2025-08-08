# Audio Transcription Size Fix - Implementation Plan

## Problem Statement

The livestream processing system has a mismatch between documented limits (50MB) and actual OpenAI Whisper API limits (25MB). A 72-minute video generates a 32MB audio file, exceeding the transcription service limit and causing processing failures.

## Current State Analysis

### Issues Identified ✅
1. **Documentation mismatch**: Config showed 50MB limit, actual OpenAI limit is 25MB
2. **Current audio extraction**: `extractAudioFromSegment()` uses 128kbps MP3 - too large for long sermons
3. **No size validation**: Audio files aren't checked against transcription limits before API calls
4. **Missing fallback strategy**: System fails completely on oversized files
5. **Chunking placeholder**: AudioTranscriptionService has unimplemented chunking method

### Key Files
- `config/sermon-processing.php` - Transcription configuration
- `app/Services/VideoStorageService.php` - Audio extraction (line 125-167)
- `app/Services/AudioTranscriptionService.php` - Transcription service (line 220-238 chunking placeholder)
- `app/Jobs/ExtractSermon.php` - Calls audio extraction (line 52-57)

## Solution Strategy

**Recommended Approach: Audio Compression (Not Chunking)**

### Why Not Chunking?
- ❌ **Context Loss**: Speech recognition works better with continuous context
- ❌ **Word Boundary Issues**: Arbitrary splits can cut words or sentences
- ❌ **Timing Complexity**: Need to track timestamps and reassemble chunks
- ❌ **Higher Error Rate**: Multiple API calls increase chances of failure

### Why Audio Compression?
- ✅ **Maintains Context**: Single audio file preserves speech flow
- ✅ **Single API Call**: Simpler error handling and processing
- ✅ **Better Accuracy**: Optimized for speech recognition requirements
- ✅ **Appropriate for Use Case**: Sermons are single, continuous audio streams

## Implementation Plan

### Phase 1: Research & Design ✅

**Optimal FFmpeg Settings for Speech Transcription:**
- **Current**: 128kbps stereo @ 44.1kHz = ~960KB/minute
- **Optimized**: 48kbps mono @ 16kHz = ~360KB/minute (62.5% smaller)
- **Fallback**: 32kbps mono @ 16kHz = ~240KB/minute (75% smaller)

**Expected Results:**
- 72-minute sermon: 32MB → 12MB (with fallback: 8MB) ✅

### Phase 2: Enhanced Configuration

**Add to `config/livestream-processing.php`:**

```php
/*
|--------------------------------------------------------------------------
| Audio Extraction for Transcription
|--------------------------------------------------------------------------
|
| Optimized audio extraction settings for transcription services.
| These settings balance file size with transcription accuracy.
|
*/
'audio_extraction' => [
    'transcription_optimized' => [
        'bitrate' => 48, // kbps - balance of quality vs size
        'sample_rate' => 16000, // Hz - adequate for speech recognition
        'channels' => 1, // mono
        'max_file_size' => 25 * 1024 * 1024, // 25MB OpenAI limit
    ],
    'fallback_compression' => [
        'bitrate' => 32, // kbps - more aggressive compression
        'sample_rate' => 16000, // Hz
        'channels' => 1, // mono
    ],
    'validation' => [
        'max_duration_minutes' => 150, // ~3.6MB at 32kbps
        'size_check_enabled' => true,
        'quality_check_enabled' => true,
    ],
],
```

### Phase 3: Enhanced VideoStorageService

**New Methods to Add:**

```php
/**
 * Extract audio optimized for transcription services
 */
public function extractOptimizedAudioFromSegment(
    string $inputVideoPath,
    LivestreamSegment $segment,
    ?string $outputFilename = null
): array

/**
 * Validate audio file size against transcription limits
 */
public function validateAudioFileSize(string $audioPath): array

/**
 * Apply fallback compression if file is too large
 */
private function compressAudioForTranscription(
    string $inputPath, 
    array $compressionSettings
): string
```

**Enhanced `extractAudioFromSegment()` Flow:**
```
1. Extract audio with optimized settings (48kbps mono @ 16kHz)
2. Validate file size against 25MB limit
3. If oversized → Apply fallback compression (32kbps)
4. If still oversized → Log error with detailed metrics
5. Return result with compression metadata
```

### Phase 4: AudioTranscriptionService Updates

**Changes Required:**

1. **Remove chunking placeholder** (lines 220-238)
2. **Add pre-validation**:
   ```php
   private function validateFileSize(string $filePath): void
   {
       $fileSize = filesize($filePath);
       $maxSize = config('sermon-processing.transcription.max_file_size');
       
       if ($fileSize > $maxSize) {
           throw new Exception(
               "Audio file too large: " . round($fileSize/1024/1024, 1) . 
               "MB (limit: " . round($maxSize/1024/1024, 1) . "MB)"
           );
       }
   }
   ```

3. **Enhanced error messages** with compression context
4. **Logging improvements** for file size metrics

### Phase 5: Integration Points

**ExtractSermon Job (`app/Jobs/ExtractSermon.php`):**
- Update to use new `extractOptimizedAudioFromSegment()`
- Handle compression metadata in processing log
- Provide clear error messages for oversized files

**Error Handling Strategy:**
```
File > 25MB after compression → 
├── Log detailed compression attempt with before/after sizes
├── Mark processing as failed with user-friendly message
├── Send admin notification with compression statistics
└── Store compression metadata for troubleshooting
```

### Phase 6: Testing Strategy

**Test Scenarios:**
1. **Normal case**: 30-minute sermon → should compress to ~5MB
2. **Large case**: 90-minute sermon → should compress to ~15MB  
3. **Edge case**: 3-hour recording → should fail gracefully
4. **Quality verification**: Ensure compressed audio transcribes accurately
5. **Error scenarios**: Missing FFmpeg, disk space issues, invalid video files
6. **Regression testing**: Ensure existing functionality still works

**Files to Create:**
- `tests/Unit/Services/VideoStorageServiceCompressionTest.php`
- `tests/Unit/Services/AudioTranscriptionServiceValidationTest.php`
- `tests/Feature/LivestreamAudioCompressionTest.php`

### Phase 7: Monitoring & Feedback

**Enhanced Logging:**
- Track compression ratios achieved (before/after sizes)
- Monitor transcription success rates for compressed files
- Alert on frequent compression failures
- Performance metrics for compression operations

**User Feedback Improvements:**
- Clear error messages: "Audio file too large (45MB) for transcription service (25MB limit)"
- Show compression attempts: "Applied audio optimization (32MB → 18MB)"
- Suggest solutions: "Consider uploading a shorter video segment"
- Progress indicators during compression

**Admin Notifications:**
- Email alerts when files exceed limits even after compression
- Weekly summary of compression statistics
- Trends in audio file sizes and processing success rates

## Implementation Checklist

### Phase 1: Configuration ✅
- [x] Fix documentation mismatch in `config/sermon-processing.php`
- [ ] Add audio extraction configuration section
- [ ] Update environment variable documentation

### Phase 2: Core Implementation
- [ ] Add optimized audio extraction method to VideoStorageService
- [ ] Implement file size validation
- [ ] Add fallback compression logic
- [ ] Update AudioTranscriptionService validation
- [ ] Remove chunking placeholder

### Phase 3: Integration
- [ ] Update ExtractSermon job to use new methods
- [ ] Enhance error handling throughout pipeline
- [ ] Add compression metadata to processing logs
- [ ] Update admin notifications

### Phase 4: Testing
- [ ] Unit tests for compression functionality
- [ ] Integration tests for full pipeline
- [ ] Performance tests for compression speed
- [ ] Quality tests for transcription accuracy

### Phase 5: Deployment
- [ ] Update production configuration
- [ ] Monitor compression ratios and success rates
- [ ] Verify transcription quality maintains acceptable levels
- [ ] Document new error scenarios and troubleshooting

## Success Metrics

**Quantitative:**
- 72-minute sermon audio files < 15MB ✅
- Transcription success rate > 95%
- Compression processing time < 30 seconds
- No silent failures on oversized files

**Qualitative:**
- Clear, actionable error messages
- Detailed logging for troubleshooting
- Maintainable, well-documented code
- User-friendly feedback throughout process

## Risk Mitigation

**Potential Issues:**
1. **Audio Quality Loss**: Mitigated by speech-optimized settings and quality testing
2. **Processing Time**: Compression adds ~10-30 seconds, acceptable for background jobs
3. **FFmpeg Compatibility**: Existing system already uses FFmpeg successfully
4. **Storage Requirements**: Compressed files actually reduce storage needs

**Rollback Plan:**
- Feature flag for new compression logic
- Ability to fall back to current 128kbps extraction
- Comprehensive logging to identify issues quickly
- Staged deployment with monitoring

## Future Enhancements

**Potential Improvements:**
1. **Adaptive Bitrate**: Automatically adjust based on content analysis
2. **Audio Preprocessing**: Noise reduction and normalization for better transcription
3. **Multiple Format Support**: Support for other transcription services with different limits
4. **Batch Processing**: Optimize for processing multiple segments efficiently

---

*Implementation Plan Created: January 2025*
*Status: Ready for Implementation*
*Estimated Development Time: 2-3 days*