# Thumbnail Generation Implementation Plan

## Overview

This document outlines the implementation plan for adding thumbnail generation with title, date, and graphics overlay to the existing media processing architecture.

## 1. Integration Architecture

**Best Integration Point**: Add thumbnail generation to the `VideoProcessingService` after video processing is complete. This ensures we can generate thumbnails for both livestream segments and direct sermon videos.

**Key Integration Benefits**:
- Leverages existing FFmpeg infrastructure (`VideoSegmentationService`)
- Fits seamlessly into current processing pipelines (both segmented and direct)
- Uses established storage patterns and error handling
- Integrates with the `ProcessingStatusContract` for consistent API responses

## 2. Thumbnail Extraction Service Design

**New Service**: `ThumbnailGenerationService`

**Core Features**:
- **Smart Frame Selection**: Extract thumbnail from 60 seconds in, avoiding last 120 seconds (as per ffmpeg command)
- **Duration Handling**: Automatically adjust timing for shorter videos
- **Quality Optimization**: Generate thumbnails at optimal resolution (1280x720) for web display
- **Fallback Strategy**: Use mid-point extraction for very short videos

**FFmpeg Integration**:
```bash
# Optimized command for service implementation:
DURATION=$(ffprobe -v quiet -show_entries format=duration -of csv=p=0 input.mp4)
PROCESS_TIME=$(echo "$DURATION - 120" | bc)
ffmpeg -ss 60 -i input.mp4 -t $PROCESS_TIME -vf "thumbnail,scale=1280:720" -frames:v 1 thumbnail.jpg
```

## 3. Overlay System Design

**Two-Phase Approach** (Recommended):
1. **Phase 1**: Generate base thumbnail with FFmpeg
2. **Phase 2**: Add overlay using PHP's Imagick/GD (more flexible for text and graphics)

**Overlay Components**:
- **Title**: Sermon title with intelligent text wrapping
- **Date**: Formatted service date
- **Service Type**: Morning/Evening/Other indicator
- **Church Branding**: Logo and visual identity
- **Video Duration**: If available

**Design Features**:
- **Responsive Text Sizing**: Auto-adjust based on title length
- **Brand Consistency**: Match existing church website styling
- **Accessibility**: High contrast text for readability

## 4. Database Schema Changes

**Add to `sermons` table**:
```php
$table->string('thumbnail_path')->nullable();
$table->timestamp('thumbnail_generated_at')->nullable();
$table->json('thumbnail_metadata')->nullable(); // Store generation settings, dimensions, etc.
```

**Benefits**:
- Simple addition to existing table
- Maintains relational integrity
- Supports metadata for future enhancements (multiple thumbnail sizes, etc.)

## 5. Processing Pipeline Integration

**For Livestream Processing** (`VideoProcessingService::processWithSegmentation`):
- Add thumbnail generation step after sermon extraction
- Extract thumbnail from extracted sermon video, not full livestream

**For Direct Video Processing** (`VideoProcessingService::processDirectly`):
- Generate thumbnail after video storage and metadata extraction
- Use full video for thumbnail extraction

**Status Tracking**:
- Add "thumbnail_generation" step to processing status updates
- Integrate with existing `StandardProcessingResponse` structure

## 6. Storage Strategy

**Storage Location**: `storage/app/public/sermons/thumbnails/`
**Naming Convention**: `{sermon-id}-thumbnail.jpg`
**Multiple Sizes**: Generate web (1280x720) and mobile (640x360) versions

**CDN Ready**: Store in public disk for potential CDN integration
**Backup Strategy**: Include thumbnails in existing backup procedures

## 7. API Integration

**New Endpoints**:
- `GET /api/sermons/{id}/thumbnail` - Retrieve sermon thumbnail
- `POST /api/sermons/{id}/thumbnail/regenerate` - Regenerate thumbnail (admin only)

**Existing Endpoint Enhancement**:
- Add thumbnail URL to sermon data in all existing API responses
- Include thumbnail generation status in processing status responses

## 8. Error Handling & Fallbacks

**Graceful Degradation**:
- **Default Thumbnail**: Church-branded fallback image if generation fails
- **Retry Logic**: Automatic retry with different timing parameters
- **Manual Override**: Admin ability to upload custom thumbnails

**Monitoring**:
- Log thumbnail generation success/failure rates
- Alert on repeated failures
- Track generation performance metrics

## 9. Performance Optimizations

**Background Processing**: Generate thumbnails asynchronously using Laravel queues
**Caching**: Cache thumbnail URLs to reduce database queries
**Lazy Loading**: Generate thumbnails only when first requested (optional)

## 10. Implementation Benefits

**User Experience**:
- Enhanced sermon browsing with visual previews
- Improved social media sharing (Open Graph meta tags)
- Better mobile experience with visual navigation

**Technical Benefits**:
- Builds on proven architecture patterns
- Maintains consistency with existing processing pipelines
- Leverages established FFmpeg expertise
- Provides foundation for future video features (chapter thumbnails, preview clips)

## Implementation Timeline

### Phase 1: Core Thumbnail Extraction
1. Create `ThumbnailGenerationService`
2. Implement basic FFmpeg thumbnail extraction
3. Add database schema changes
4. Integrate with existing processing pipelines

### Phase 2: Overlay System
1. Implement overlay generation using Imagick/GD
2. Create church branding templates
3. Add title, date, and service type overlays
4. Implement responsive text sizing

### Phase 3: API and Frontend Integration
1. Add thumbnail endpoints
2. Update existing API responses
3. Integrate thumbnails into frontend displays
4. Add admin regeneration capabilities

### Phase 4: Optimization and Monitoring
1. Implement background processing
2. Add performance monitoring
3. Optimize for CDN delivery
4. Add error tracking and alerting

This design integrates seamlessly with the existing TALL stack architecture while providing a robust, scalable thumbnail generation system that enhances the user experience for the church's sermon library.