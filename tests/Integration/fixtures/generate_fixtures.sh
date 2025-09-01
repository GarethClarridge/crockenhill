#!/bin/bash

# Generate test audio files using ffmpeg
cd "$(dirname "$0")"

echo "Generating test fixtures..."

# Check if ffmpeg is available
if ! command -v ffmpeg &> /dev/null; then
    echo "Error: ffmpeg is required but not installed"
    echo "Install with: brew install ffmpeg (macOS) or sudo apt-get install ffmpeg (Ubuntu)"
    exit 1
fi

# Short audio file (30 seconds of sine wave)
echo "Creating test_audio_short.mp3..."
ffmpeg -f lavfi -i "sine=frequency=440:duration=30" -acodec mp3 -ab 64k test_audio_short.mp3 -y

# Medium audio file (5 minutes of mixed content)
echo "Creating test_audio_medium.mp3..."
ffmpeg -f lavfi -i "sine=frequency=440:duration=150" -f lavfi -i "sine=frequency=880:duration=150" -filter_complex "[0:a][1:a]concat=n=2:v=0:a=1" -acodec mp3 -ab 128k test_audio_medium.mp3 -y

# Test video with audio (10 minutes, small size)
echo "Creating test_video_livestream.mp4..."
ffmpeg -f lavfi -i "testsrc=duration=600:size=320x240:rate=1" -f lavfi -i "sine=frequency=440:duration=600" -c:v libx264 -preset ultrafast -crf 40 -c:a aac -b:a 64k test_video_livestream.mp4 -y

# Corrupted file
echo "Creating test_video_corrupted.mp4..."
cp test_audio_short.mp3 test_video_corrupted.mp4
truncate -s 100 test_video_corrupted.mp4

echo "Test fixtures generated successfully"
echo "Generated files:"
ls -lh *.mp3 *.mp4 2>/dev/null || echo "No files found - check for errors above"