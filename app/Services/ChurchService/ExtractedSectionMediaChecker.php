<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use Illuminate\Support\Facades\Storage;

class ExtractedSectionMediaChecker
{
    /**
     * Whether the extracted assets a section is published from are present: a talk's video and
     * audio, a song's video. Songs are never cut to audio, so asking for one refused every song
     * held for review (1363's "Rejoice", 2026-10-22) and let a split or merged song keep its clip.
     */
    public function hasExtractedMedia(ServiceSection $section): bool
    {
        $videoPath = $section->extracted_video_path;
        $audioPath = $section->section_type === ServiceSectionType::Song ? null : $section->extracted_audio_path;

        if (! is_string($videoPath) || $videoPath === ''
            || ($section->section_type !== ServiceSectionType::Song && (! is_string($audioPath) || $audioPath === ''))) {
            return false;
        }

        $disk = Storage::disk($section->extractedAssetDisk());

        return $disk->exists($videoPath) && ($audioPath === null || $disk->exists($audioPath));
    }
}
