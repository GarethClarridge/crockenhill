<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ServiceSection;
use Illuminate\Support\Facades\Storage;

class ExtractedSectionMediaChecker
{
    /**
     * Whether both the extracted video and audio assets for a service section
     * are present on their respective disks.
     */
    public function hasExtractedMedia(ServiceSection $section): bool
    {
        $videoPath = $section->extracted_video_path;
        $audioPath = $section->extracted_audio_path;

        if (! is_string($videoPath) || $videoPath === '' || ! is_string($audioPath) || $audioPath === '') {
            return false;
        }

        $disk = Storage::disk($section->extractedAssetDisk());

        return $disk->exists($videoPath) && $disk->exists($audioPath);
    }
}
