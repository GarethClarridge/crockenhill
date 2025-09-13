# Product Overview

## Crockenhill Baptist Church Website

This is a Laravel-based website for Crockenhill Baptist Church (crockenhill.org) that serves as both a public-facing church website and an advanced automated media processing platform.

### Core Features

- **Church Website**: Static content organized by areas (Christ, Church, Community, Members)
- **Sermon Management**: Audio/video sermon uploads with intelligent processing routing
- **AI-Powered Processing**: Automated transcription, analysis, and metadata extraction using OpenAI
- **Unified Media Processing**: Single system handling audio, video, and livestream content
- **Livestream Processing**: Automated video segmentation and sermon extraction from full recordings
- **Content Management**: Pages, meetings, calendar events, and authenticated member areas

### Key Domains

- **Sermons**: Central content with audio/video files, transcripts, AI-generated metadata
- **Pages**: Static content organized by PageArea enum (Christ, Church, Community, Members)
- **Meetings**: Church events with MeetingFrequency and MeetingType enums
- **Processing**: Unified media processing with ProcessingStatusContract implementation
- **Users**: Authentication system for members area access

### Recent Architectural Improvements

#### ProcessingStatusContract Implementation
- **Unified API Responses**: Consistent interface across all processing types
- **StandardProcessingResponse**: Single response format for status endpoints
- **Polymorphic Processing**: Contract-based routing to appropriate processors
- **Enhanced Error Handling**: Standardized error responses and recovery options

#### Intelligent Processing Router
- **ProcessingRouter**: Automatically routes uploads to appropriate processors
- **Multi-format Support**: Handles audio files, sermon videos, and full livestreams
- **Dual Processing Modes**: Segmentation for livestreams, direct processing for sermon videos

### Technology Focus

The application emphasizes unified media processing architecture, AI integration (OpenAI Whisper + GPT), FFmpeg-based video analysis, and contract-driven API consistency for scalable sermon management.