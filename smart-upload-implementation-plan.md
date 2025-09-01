# Simplified Smart Upload Interface Plan

## Core Approach: User Choice, Not Auto-Detection

**Single upload page with three clear options:**

### 1. Three-Tab Interface
```
┌─── Audio ───┬─── Video ───┬─── Livestream ───┐
│ Direct      │ Direct      │ Full service    │
│ sermon      │ sermon      │ video with      │
│ recording   │ video       │ analysis        │
│ Max: 100MB  │ Max: 2GB    │ Max: 2GB        │
│ MP3,WAV,M4A │ MP4,MOV,AVI │ MP4,MOV,AVI,MKV │
│ [Drop zone] │ [Drop zone] │ [Drop zone]     │
└─────────────┴─────────────┴─────────────────┘
```

### 2. Implementation Strategy

**Single Controller with Type Parameter:**
- `SmartUploadController@create` - Show three-tab interface
- `SmartUploadController@store` - Route to correct API based on `type` parameter
- User explicitly chooses "audio", "video", or "livestream" tab

**API Endpoints Used:**
- **Audio Tab** → `POST /api/sermons/automated` (existing)
- **Video Tab** → `POST /api/sermons/video` (new - implemented)
- **Livestream Tab** → `POST /api/livestreams/process` (existing)

**Frontend Logic:**
- Tab selection determines which API endpoint to use
- Different file validation rules per tab:
  - Audio: 100MB, audio formats only
  - Video: 2GB, video formats, processes entire file as sermon
  - Livestream: 2GB, video formats, analyzes and segments
- Appropriate progress tracking for each processing type
- Same UI patterns, different backend integration

### 3. Current Implementation Status

**✅ Completed APIs:**
- Audio Upload: `/api/sermons/automated` (existing)
- **Video Upload: `/api/sermons/video` (newly implemented)**
- Livestream Upload: `/api/livestreams/process` (existing)

**✅ Video Upload Features:**
- Supports MP4, MOV, AVI, MKV formats
- 2GB file size limit
- Extracts audio from entire video for transcription
- Processes through existing sermon pipeline
- Rate limited (1/min, 5/hour)
- Comprehensive error handling
- Full test coverage

### 4. Benefits of This Approach
- ✅ Clear user intent - no guessing
- ✅ Simple implementation with existing APIs
- ✅ Familiar UX pattern (tabs/sections)  
- ✅ Easy to maintain and debug
- ✅ Follows existing admin page patterns
- ✅ All three workflows available in one interface

### 5. Tab Descriptions for Users

**Audio Tab:**
- "Upload a sermon audio recording (MP3, WAV, M4A)"
- "Perfect for direct audio recordings from your recording device"
- Processing: Transcription → AI Analysis → Sermon Creation

**Video Tab:**
- "Upload a sermon video file (MP4, MOV, AVI, MKV)"
- "For videos containing only the sermon (no music/announcements)"
- Processing: Audio Extraction → Transcription → AI Analysis → Sermon Creation

**Livestream Tab:**
- "Upload a full service video for automatic sermon detection"
- "System will analyze audio levels to find and extract the sermon portion"
- Processing: Audio Analysis → Segmentation → Sermon Extraction → Processing

The user picks what they're uploading upfront, then we handle the appropriate API integration behind the scenes. All three processing pipelines are now available through a unified interface!